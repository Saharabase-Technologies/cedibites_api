<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Both ends of post-order feedback: the customer's form, and what came back.
 *
 * The public end is unauthenticated — the token in the URL is the only
 * credential. That is acceptable because the token is bound to one order, the
 * form accepts exactly one submission, and nothing it returns identifies the
 * customer. What it does expose is what was ordered and from where, which is why
 * the token is eight characters rather than a campaign link's six, and why the
 * routes are throttled.
 */
class OrderFeedbackController extends Controller
{
    // ─── Public ──────────────────────────────────────────────────────────────

    /**
     * What this form is about, so the customer sees the right meal before rating it.
     *
     * The token identifies the order, so they never type an order number.
     */
    public function show(string $token): JsonResponse
    {
        $feedback = OrderFeedback::with('order.branch')->where('token', $token)->first();

        if (! $feedback) {
            return $this->closed();
        }

        if ($feedback->isSubmitted()) {
            // Told apart from expired deliberately, and only here: somebody who
            // taps their own link twice should see "thanks, we have it" rather
            // than be told their feedback vanished.
            return response()->success([
                'already_submitted' => true,
                'order_number' => $feedback->order?->order_number,
            ]);
        }

        if ($feedback->isExpired()) {
            return $this->closed();
        }

        return response()->success([
            'already_submitted' => false,
            'order_number' => $feedback->order?->order_number,
            'branch_name' => $feedback->order?->branch?->name,
            'ordered_at' => $feedback->order?->created_at?->toIso8601String(),
            'expires_at' => $feedback->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Take the answer. Once.
     *
     * A second submission is refused rather than merged: the form is not for
     * changing your mind, and letting a forwarded link overwrite an answer would
     * mean anybody holding the URL could rewrite what the customer said.
     */
    public function store(Request $request, string $token): JsonResponse
    {
        $feedback = OrderFeedback::where('token', $token)->first();

        if (! $feedback || ! $feedback->isOpen()) {
            return $this->closed();
        }

        $validated = $request->validate([
            'rating_overall' => ['required', 'integer', 'min:1', 'max:5'],
            // The breakdown is optional. One tap on the overall score is a
            // complete answer, and demanding three is how a response rate dies.
            'rating_food' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_service' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Conditional UPDATE as the claim, so a double-tap on a slow connection
        // records one answer rather than two.
        $claimed = OrderFeedback::whereKey($feedback->id)
            ->whereNull('submitted_at')
            ->update([
                ...$validated,
                'submitted_at' => now(),
            ]);

        if (! $claimed) {
            return response()->success(['already_submitted' => true], 'Thanks — we already have this.');
        }

        /*
         * Tie the answer back to the automation firing that asked for it.
         *
         * Without this, response rate per rule is always zero and there is no
         * way to learn which asks work — which is the whole reason for having
         * several rules rather than one. Matched on the order because that is
         * what both sides hold.
         *
         * Only the firing that actually sent, and only if it has not already
         * been credited: a rule that was suppressed did not earn this answer.
         */
        \App\Models\AutomationFire::where('order_id', $feedback->order_id)
            ->whereNotNull('sent_at')
            ->whereNull('order_feedback_id')
            ->limit(1)
            ->update(['order_feedback_id' => $feedback->id]);

        return response()->success(
            ['already_submitted' => false],
            'Thank you. That helps more than you know.',
        );
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    /**
     * What came back.
     *
     * Only submitted rows by default. An unanswered request is not feedback —
     * it is an SMS — and listing thousands of them would bury the handful of
     * things somebody actually wrote.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OrderFeedback::with(['order.branch'])->answered();

        if ($request->filled('branch_id')) {
            $branchId = $request->integer('branch_id');
            $query->whereHas('order', fn ($q) => $q->where('branch_id', $branchId));
        }

        // The ones worth reading first. A three-star with a paragraph attached
        // says more than a five-star with nothing.
        if ($request->boolean('unhappy_only')) {
            $query->where('rating_overall', '<=', 3);
        }

        $feedback = $query->latest('submitted_at')->paginate($request->integer('per_page', 25));

        return response()->success([
            ...$feedback->toArray(),
            'data' => array_map($this->present(...), $feedback->items()),
            'summary' => $this->summary($request->integer('branch_id') ?: null),
        ]);
    }

    /**
     * The headline numbers.
     *
     * Response rate is submitted over *sent* — a request that failed to go out
     * has sent_at null and is excluded, because a message nobody received must
     * not read as a message nobody answered.
     */
    private function summary(?int $branchId): array
    {
        $scope = fn () => OrderFeedback::query()
            ->when($branchId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('branch_id', $branchId)));

        $sent = $scope()->whereNotNull('sent_at')->count();
        $answered = $scope()->answered()->count();

        return [
            'sent' => $sent,
            'answered' => $answered,
            'response_rate' => $sent > 0 ? round($answered / $sent * 100, 1) : null,
            'average_overall' => $this->average($scope(), 'rating_overall'),
            'average_food' => $this->average($scope(), 'rating_food'),
            'average_service' => $this->average($scope(), 'rating_service'),
        ];
    }

    private function average(\Illuminate\Database\Eloquent\Builder $query, string $column): ?float
    {
        $average = $query->answered()->whereNotNull($column)->avg($column);

        return $average === null ? null : round((float) $average, 2);
    }

    private function present(OrderFeedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'order_number' => $feedback->order?->order_number,
            'branch_name' => $feedback->order?->branch?->name,
            // The name they gave when ordering. No phone, no address — this list
            // is read to find out what went wrong, not to look people up.
            'customer_name' => $feedback->order?->contact_name,
            'rating_overall' => $feedback->rating_overall,
            'rating_food' => $feedback->rating_food,
            'rating_service' => $feedback->rating_service,
            'comment' => $feedback->comment,
            'submitted_at' => $feedback->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * One answer for "never existed" and "expired".
     *
     * Telling them apart would turn this endpoint into a way of testing whether
     * a token is real, which is the whole of the token's value.
     */
    private function closed(): JsonResponse
    {
        return response()->error('This link has expired.', 404);
    }
}
