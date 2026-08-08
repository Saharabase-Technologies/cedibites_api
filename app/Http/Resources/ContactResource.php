<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'source' => $this->source,

            /*
             * supplementary — never ordered; not a customer, not in any customer figure.
             * acquired      — ordered after we imported them. The list earned this one.
             * already_customer — was ordering before the import. Found, not won.
             *
             * Three states rather than a converted boolean, because the
             * difference between the last two is the difference between a list
             * that worked and a list that sold us our own customers back.
             */
            'status' => $this->status(),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'was_customer_before_import' => $this->was_customer_before_import,
            /** Null unless the list actually won them. See Contact::daysToConvert(). */
            'days_to_convert' => $this->daysToConvert(),

            'import' => $this->whenLoaded('import', fn () => [
                'id' => $this->import->id,
                'label' => $this->import->label,
            ]),

            'customer_id' => $this->customer_id,
            'converted_order_id' => $this->converted_order_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
