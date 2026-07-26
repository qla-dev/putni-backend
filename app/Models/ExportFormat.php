<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportFormat extends Model
{
    protected $fillable = [
        'name',
        'title',
        'description',
        'extension',
        'mime_type',
        'handler',
        'icon',
        'color',
        'is_integration',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_integration' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }
}
