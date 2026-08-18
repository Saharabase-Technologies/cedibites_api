<?php

namespace App\Http\Controllers\Api\StaffMessaging;

use App\Enums\StaffMessageEvent;
use App\Enums\StaffMessageTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffMessaging\StoreStaffMessageRuleRequest;
use App\Http\Resources\StaffMessaging\StaffMessageRuleResource;
use App\Models\StaffMessageRule;
use App\Services\Platform\RuntimeSettings;
use App\Services\StaffMessaging\StaffRuleDryRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StaffMessageRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = StaffMessageRule::query()
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return response()->success([
            'rules' => StaffMessageRuleResource::collection($rules),
            // Reported alongside, because a list of live rules sending nothing
            // is otherwise impossible to explain.
            'automation_enabled' => (bool) app(RuntimeSettings::class)->get('staff_messaging.automation_enabled'),
        ]);
    }

    /** What the rule builder needs to render itself. */
    public function options(): JsonResponse
    {
        return response()->success([
            'events' => collect(StaffMessageEvent::cases())->map(fn (StaffMessageEvent $event) => [
                'value' => $event->value,
                'label' => $event->label(),
                'required_conditions' => $event->requiredConditions(),
                'merge_fields' => array_merge(['name', 'first_name', 'branch'], $event->mergeFields()),
            ])->values(),
            'targets' => collect(StaffMessageTarget::cases())->map(fn (StaffMessageTarget $target) => [
                'value' => $target->value,
                'label' => $target->label(),
            ])->values(),
            'order_statuses' => [
                'received', 'accepted', 'preparing', 'ready',
                'ready_for_pickup', 'out_for_delivery',
            ],
        ]);
    }

    public function store(StoreStaffMessageRuleRequest $request): JsonResponse
    {
        $rule = StaffMessageRule::create($request->validated() + [
            'created_by_user_id' => $request->user()->id,
            // Always off. See the note in the form request: saving and switching
            // on are separate acts so a rule can be dry-run in between.
            'is_active' => false,
        ]);

        return response()->success(new StaffMessageRuleResource($rule), 'Rule saved. It is not live yet.');
    }

    public function show(StaffMessageRule $rule): JsonResponse
    {
        return response()->success(new StaffMessageRuleResource($rule));
    }

    public function update(StoreStaffMessageRuleRequest $request, StaffMessageRule $rule): JsonResponse
    {
        // `is_active` is not in the validated set, so editing can never switch a
        // rule on or off in either direction. Liveness changes only through
        // toggle().
        $rule->update($request->validated());

        return response()->success(new StaffMessageRuleResource($rule->refresh()), 'Rule updated.');
    }

    /**
     * The only thing that starts, or stops, real messages.
     */
    public function toggle(Request $request, StaffMessageRule $rule): JsonResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        Log::channel('stack')->info('Staff message rule toggled', [
            'rule_id' => $rule->id,
            'is_active' => $rule->is_active,
            'by' => $request->user()->id,
        ]);

        $message = $rule->is_active
            ? 'Rule is live.'
            : 'Rule switched off.';

        // Being live is not sufficient. Somebody switching on their first rule
        // and seeing nothing happen needs to be told why here, not left to
        // discover the kill switch.
        if ($rule->is_active && ! app(RuntimeSettings::class)->get('staff_messaging.automation_enabled')) {
            $message = 'Rule is live, but automation is switched off globally — nothing will send yet.';
        }

        return response()->success(new StaffMessageRuleResource($rule->refresh()), $message);
    }

    /**
     * Replay against history. Writes nothing, sends nothing.
     */
    public function dryRun(Request $request, StaffMessageRule $rule, StaffRuleDryRun $dryRun): JsonResponse
    {
        $days = min(90, max(1, $request->integer('days') ?: 30));

        return response()->success($dryRun->run($rule, $days));
    }

    public function destroy(StaffMessageRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->success(null, 'Rule deleted.');
    }
}
