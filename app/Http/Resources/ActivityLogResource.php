<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\MenuAddOn;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuTag;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promo;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    private const ENTITY_MAP = [
        Order::class => 'order',
        Payment::class => 'order',
        Branch::class => 'branch',
        MenuItem::class => 'menu',
        MenuCategory::class => 'menu',
        MenuTag::class => 'menu',
        MenuAddOn::class => 'menu',
        Promo::class => 'menu',
        User::class => 'staff',
        Employee::class => 'staff',
        Customer::class => 'customer',
        Shift::class => 'system',
    ];

    // Opening a branch without its checklist is the unusual action head office
    // asked to see flagged; lateness and unfixed problems sit beside it.
    private const WARNING_EVENTS = ['refunded', 'deleted', 'customer_deleted', 'branch_opened_without_checklist', 'branch_not_open', 'branch_still_not_open', 'opening_grace_expired'];

    private const DESTRUCTIVE_EVENTS = ['role_changed', 'customer_suspended'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'event' => $this->event,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
                'email' => $this->causer->email ?? null,
            ] : null,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toISOString(),
            'entity' => $this->deriveEntity(),
            'severity' => $this->deriveSeverity(),
        ];
    }

    private function deriveEntity(): string
    {
        if (! $this->subject_type) {
            return 'system';
        }

        return self::ENTITY_MAP[$this->subject_type] ?? 'system';
    }

    private function deriveSeverity(): string
    {
        $event = $this->event;
        if (in_array($event, self::DESTRUCTIVE_EVENTS, true)) {
            return 'destructive';
        }
        if (in_array($event, self::WARNING_EVENTS, true)) {
            return 'warning';
        }

        return 'info';
    }
}
