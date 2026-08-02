<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class LampyrisUser extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'apple_id',
        'free_access_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'free_access_expires_at' => 'datetime',
        ];
    }
}
