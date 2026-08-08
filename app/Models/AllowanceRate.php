<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowanceRate extends Model
{
    protected $fillable = [
        'country',
        'rate_bam',
        'region',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'rate_bam' => 'float',
            'is_default' => 'boolean',
        ];
    }
}
