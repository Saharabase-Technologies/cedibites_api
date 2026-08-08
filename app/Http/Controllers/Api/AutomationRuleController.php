<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutomationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAutomationRuleRequest;
use App\Http\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationDryRun;
use App\Services\Campaigns\MessageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rules that message customers without anybody pressing send.
 *
 * Saving and activating are two separate calls, the same way composing and
 * sending are for a campaign. A rule written this morning must not start texting
 * people because somebody hit save with a checkbox in the wrong state.
 *
 * Gated on `manage_campaigns` — a rule reaches the whole customer base over
 * time, one person at a time, which is the same reach as a campaign spread thin.
 */
class AutomationRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = AutomationRuleResource::withCounts(
            AutomationRule::with(['shortLink', 'createdBy'])
        )
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return response()->success([
            'data' => AutomationRuleResource::collection($rules)->resolve(),

            // Surfaced beside the list rather than buried in settings: a screen
            // full of active rules that are sending nothing is otherwise
            // impossible to explain.
            'automation_enabled' => (bool) config('automation.enabled', false),
            'cooldown_days' => (int) config('automation.cooldown_days', 3),
        ]);
    }

    public function show(AutomationRule $rule): JsonResponse
    {
        $rule = AutomationRuleResource::withCounts(
            AutomationRule::where('id', $rule->id)->with(['shortLink', 'createdBy'])
        )->firstOrFail();

        return response()->success([
            ...(new AutomationRuleResource($rule))->resolve(),
            'suppression_breakdown' => AutomationRuleResource::suppressionBreakdown($rule->id),
        ]);
    }

    public function store(SaveAutomationRuleRequest $request): JsonResponse
    {
        $rule = AutomationRule::create([
            ...$request->safe()->except('is_active'),
            // Inactive on creation, whatever was posted. Switching a rule on is
            // a decision of its own and has its own endpoint.
            'is_active' => false,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->created(
            (new AutomationRuleResource($rule->load(['shortLink', 'createdBy'])))->resolve(),
        );
    }

    public function update(SaveAutomationRuleRequest $request, AutomationRule $rule): JsonResponse
    {
        // Editing does not change whether it is live, in either direction.
        $rule->update($request->safe()->except('is_active'));

        return response()->success(
            (new AutomationRuleResource($rule->fresh(['shortLink', 'createdBy'])))->resolve(),
            'Rule saved.',
        );
    }

    /**
     * Switch a rule on or off.
     *
     * Its own endpoint because it is its own decision — the one that starts or
     * stops real messages going to real people. Logged on the model.
     */
    public function toggle(Request $request, AutomationRule $rule): JsonResponse
    {
        $active = $request->boolean('is_active');

        $rule->update(['is_active' => $active]);

        return response()->success(
            (new AutomationRuleResource($rule->fresh(['shortLink', 'createdBy'])))->resolve(),
            $active
                ? (config('automation.enabled', false)
                    ? 'Rule is live. It will fire on the next qualifying order.'
                    // Two switches; saying so here saves somebody watching a
                    // live rule do nothing and concluding it is broken.
                    : 'Rule is on, but automation is switched off globally, so nothing will send yet.')
                : 'Rule switched off.',
        );
    }

    public function destroy(AutomationRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->deleted();
    }

    /**
     * What this rule would have done against real history. Sends nothing.
     *
     * The screen nobody should be able to skip before switching a rule on — it
     * is the only thing that catches a rule which fires on every order, and it
     * catches it for free.
     */
    public function dryRun(Request $request, AutomationRule $rule, AutomationDryRun $dryRun): JsonResponse
    {
        $days = $request->filled('days') ? (int) $request->integer('days') : null;

        return response()->success($dryRun->run($rule, $days));
    }

    /**
     * Everything the rule builder needs: the events, and what each one asks for.
     *
     * Served rather than hard-coded in the frontend so the event list and the
     * settings each one needs cannot drift out of step with the enum.
     */
    public function options(Request $request, MessageMeter $meter): JsonResponse
    {
        return response()->success([
            'events' => array_map(fn (AutomationEvent $e) => [
                'value' => $e->value,
                'label' => $e->label(),
                'description' => $e->description(),
                'config_keys' => $e->configKeys(),
            ], AutomationEvent::cases()),

            'merge_fields' => [
                ['field' => '{name}', 'description' => 'Their first name, or "there" if we have none'],
                ['field' => '{dish}', 'description' => 'What they ordered — for "tried something new", the new item'],
                ['field' => '{branch}', 'description' => 'The branch they ordered from'],
                ['field' => '{order_number}', 'description' => 'The order reference'],
                ['field' => '{link}', 'description' => 'The short link attached to this rule'],
            ],

            'automation_enabled' => (bool) config('automation.enabled', false),
            'cooldown_days' => (int) config('automation.cooldown_days', 3),
            'rate_per_segment' => (float) config('automation.rate_per_segment', 0.0243),
        ]);
    }

    /**
     * Measure a message without saving it.
     *
     * Merge fields are measured as their FIELD NAME, not as a filled value,
     * which is a deliberate understatement — see the note in the frontend meter.
     * The authority on cost is still this endpoint.
     */
    public function measure(Request $request, MessageMeter $meter): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:1600']]);

        return response()->success([
            ...$meter->measure($validated['message']),
            'estimated_cost_each' => $meter->estimateCost($validated['message'], 1),
        ]);
    }
}
