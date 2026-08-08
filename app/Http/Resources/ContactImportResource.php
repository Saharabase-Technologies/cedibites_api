<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContactImport */
class ContactImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $converted = $this->convertedCount();
        $acquired = max(0, $converted - $this->already_customer_count);

        return [
            'id' => $this->id,
            'label' => $this->label,
            'filename' => $this->filename,
            'source_note' => $this->source_note,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),

            'total_rows' => $this->total_rows,
            'imported_count' => $this->imported_count,
            'duplicate_count' => $this->duplicate_count,
            'invalid_count' => $this->invalid_count,
            'already_customer_count' => $this->already_customer_count,

            /*
             * What the list has actually produced.
             *
             * `acquired_count` deliberately subtracts the numbers that were
             * already customers when the file landed. Reporting total conversions
             * would let a list of 4,000 existing regulars show up as a wildly
             * successful acquisition on the day it was uploaded, before it had
             * done anything at all.
             */
            'converted_count' => $converted,
            'acquired_count' => $acquired,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
