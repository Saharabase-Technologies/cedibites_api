<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\RuntimeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Behaviour settings a tech admin can change without SSH.
 *
 * Lives under `/platform`, which is already `role:tech_admin` plus a passcode
 * gate. That is the right ceiling: these values change how the platform behaves
 * for everybody, and the business owner deliberately does not hold them.
 *
 * This endpoint cannot write `.env` and cannot read secrets. It moves DB
 * overrides on an allowlist — see RuntimeSettings::definitions().
 */
class PlatformSettingsController extends Controller
{
    public function __construct(
        private readonly RuntimeSettings $runtime,
    ) {}

    /**
     * Every editable setting, its current value, its default, and which is
     * winning.
     */
    public function index(): JsonResponse
    {
        return response()->success([
            'settings' => $this->runtime->all(),
            // Named so nobody changes production believing they are on beta.
            // The panel puts this at the top in plain sight.
            'environment' => config('app.env'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            // No type constraint here — RuntimeSettings casts and clamps
            // according to the definition, which is the only place that knows
            // what this key is meant to be.
            'value' => ['required'],
        ]);

        if (! $this->runtime->isEditable($validated['key'])) {
            // 422 rather than 403. It is not that this caller may not edit it;
            // it is that the key is not editable by anyone through this door.
            return response()->json([
                'message' => 'That setting cannot be changed from here.',
            ], 422);
        }

        $value = $this->runtime->set($validated['key'], $validated['value'], $request->user()->id);

        return response()->success(
            ['key' => $validated['key'], 'value' => $value],
            'Saved. It takes effect immediately — no restart needed.',
        );
    }

    /** Drop the override and follow the server file again. */
    public function revert(Request $request): JsonResponse
    {
        $validated = $request->validate(['key' => ['required', 'string']]);

        if (! $this->runtime->isEditable($validated['key'])) {
            return response()->json([
                'message' => 'That setting cannot be changed from here.',
            ], 422);
        }

        $value = $this->runtime->revert($validated['key'], $request->user()->id);

        return response()->success(
            ['key' => $validated['key'], 'value' => $value],
            'Back to the server default.',
        );
    }
}
