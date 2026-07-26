<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Wastage\WastageService;
use App\Http\Controllers\Controller;
use App\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IMS settings.
 *
 * The portal has had a wastage-threshold editor since the mock scaffold, wired
 * to an endpoint that never existed - it silently failed on every save and the
 * figure it displayed was fiction. The threshold itself was a PHP constant.
 *
 * Rather than invent an `inventory_settings` table for one number, this rides on
 * the `system_settings` key/value store that already backs the service charge
 * and the manual-entry-date flag, through the same cached service.
 *
 * The threshold is a single business rule, not a per-location one. When per-
 * location thresholds are genuinely wanted, that is a schema change and a
 * product decision - not something to fake by pretending this endpoint scopes.
 */
class InventorySettingController extends Controller
{
    /** The single source of truth for the setting's key + default. */
    public const THRESHOLD_KEY = 'inventory_wastage_threshold_amount';

    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->success($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wastage_threshold_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $this->settings->set(
            self::THRESHOLD_KEY,
            (string) round((float) $data['wastage_threshold_amount'], 2),
            'string',
        );

        activity('settings')
            ->causedBy($request->user())
            ->withProperties(['key' => self::THRESHOLD_KEY, 'value' => $data['wastage_threshold_amount']])
            ->event('updated')
            ->log('Inventory wastage threshold updated');

        return response()->success($this->payload(), 'Wastage threshold updated.');
    }

    /**
     * Shaped to match the frontend's `InventorySettings`. `location_id` is null
     * and stays null - the rule is global, and saying so is better than omitting
     * the field and letting the client guess.
     */
    private function payload(): array
    {
        return [
            'id' => 1,
            'location_id' => null,
            'wastage_threshold_amount' => WastageService::threshold(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
