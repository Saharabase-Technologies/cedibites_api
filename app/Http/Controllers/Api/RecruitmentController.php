<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitRecruitmentApplicationRequest;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentLink;
use Illuminate\Http\JsonResponse;

/**
 * The public end of onboarding: what a new staff member sees, and what they send.
 *
 * Unauthenticated by design — the token in the URL is the only credential, and
 * whoever holds it can fill the form in. That is acceptable because a submission
 * is inert: no user, no employee, no login until someone checks it and creates
 * the account. It is also why links carry an expiry.
 */
class RecruitmentController extends Controller
{
    /**
     * What this link is hiring for, so the recruit can see they opened the right
     * one before filling in twelve fields.
     */
    public function show(string $token): JsonResponse
    {
        $link = $this->openLink($token);

        if (! $link) {
            return $this->closed();
        }

        return response()->success([
            'kind' => $link->kind->value,
            'posting' => $link->postingName(),
            'branch_name' => $link->branch?->name,
            'label' => $link->label,
            'expires_at' => $link->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Take the form.
     *
     * Nothing is created but a row to review. The response deliberately carries
     * no application id, no status and no way to check back: there is nothing to
     * check, and hinting otherwise invites people to keep looking.
     */
    public function store(SubmitRecruitmentApplicationRequest $request): JsonResponse
    {
        // Guaranteed open — the request refuses to validate against a link that
        // is not, so that a closed link answers about the link rather than about
        // whatever else was wrong with the form.
        $link = $request->link();

        RecruitmentApplication::create([
            'recruitment_link_id' => $link->id,
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            // Cast 'hashed' on the model — the plaintext stops here.
            'password_hash' => $request->input('password'),
            ...$request->safe()->only([
                'ghana_card_id', 'date_of_birth', 'nationality',
                'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_relationship',
            ]),
        ]);

        return response()->created([
            'message' => "Thanks. We've got your details — you'll get a message when your account is ready.",
        ]);
    }

    /** The link, if it exists and is still open. Null otherwise. */
    private function openLink(string $token): ?RecruitmentLink
    {
        $link = RecruitmentLink::with('branch')->where('token', $token)->first();

        return $link?->isOpen() ? $link : null;
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
