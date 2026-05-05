<?php

namespace App\Models\Inventory;

use Database\Factories\Inventory\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'inventory_units';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'dimension',
        'is_base_unit',
        'is_active',
    ];

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_base_unit' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
