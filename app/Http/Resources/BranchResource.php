<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Add computed is_open status based on operating hours
        $data['is_open'] = $this->isCurrentlyOpen();

        // Add extended access flags for staff systems
        $data['extended_staff_access'] = $this->extended_staff_access;
        $data['extended_order_access'] = $this->extended_order_access;
        $data['staff_access_allowed'] = $this->isStaffAccessAllowed();

        // Include full menu items if loaded.
        //
        // Union of both sources while the migration is in flight: dishes the
        // merge has moved onto the pivot, plus any it has not reached yet that
        // still carry this branch_id. Deduped by id, since a dish appears in
        // both for its own original branch. Once menu_items.branch_id is
        // dropped only servedMenuItems remains.
        $legacy = $this->relationLoaded('menuItems') ? $this->menuItems : null;
        $served = $this->relationLoaded('servedMenuItems') ? $this->servedMenuItems : null;

        if ($legacy !== null || $served !== null) {
            $items = ($served ?? collect())
                ->concat($legacy ?? collect())
                ->unique('id')
                ->values();

            $data['menu_items'] = MenuItemResource::collection($items);
        }

        // Include manager information if loaded
        if ($this->relationLoaded('managers')) {
            $manager = $this->managers->first();
            $data['manager'] = $manager ? [
                'id' => $manager->id,
                'name' => $manager->user->name ?? null,
                'email' => $manager->user->email ?? null,
                'phone' => $manager->user->phone ?? null,
            ] : null;
        }

        // Include operating hours if loaded
        if ($this->relationLoaded('operatingHours')) {
            $data['operating_hours'] = $this->operatingHours->mapWithKeys(function ($hour) {
                return [$hour->day_of_week => [
                    'is_open' => $hour->is_open,
                    'open_time' => $hour->open_time ? substr($hour->open_time, 0, 5) : null, // HH:MM format
                    'close_time' => $hour->close_time ? substr($hour->close_time, 0, 5) : null,
                    'manual_override_open' => $hour->manual_override_open,
                    'manual_override_at' => $hour->manual_override_at?->toISOString(),
                ]];
            });
        }

        // Include active delivery settings if loaded
        if ($this->relationLoaded('deliverySettings')) {
            $activeSetting = $this->activeDeliverySetting();
            $data['delivery_settings'] = $activeSetting ? [
                'base_delivery_fee' => (float) $activeSetting->base_delivery_fee,
                'per_km_fee' => (float) $activeSetting->per_km_fee,
                'delivery_radius_km' => (float) $activeSetting->delivery_radius_km,
                'min_order_value' => (float) $activeSetting->min_order_value,
                'estimated_delivery_time' => $activeSetting->estimated_delivery_time,
            ] : null;
        }

        // Include order types if loaded
        if ($this->relationLoaded('orderTypes')) {
            $data['order_types'] = $this->orderTypes->mapWithKeys(function ($type) {
                return [$type->order_type => [
                    'is_enabled' => $type->is_enabled,
                    'metadata' => $type->metadata,
                ]];
            });
        }

        // Include payment methods if loaded
        if ($this->relationLoaded('paymentMethods')) {
            $data['payment_methods'] = $this->paymentMethods->mapWithKeys(function ($method) {
                return [$method->payment_method => [
                    'is_enabled' => $method->is_enabled,
                    'metadata' => $method->metadata,
                ]];
            });
        }

        return $data;
    }
}
