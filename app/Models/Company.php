<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'invite_code',
        'team_enabled',
        'share_ai_tokens',
        'share_vehicles',
    ];

    protected function casts(): array
    {
        return [
            'team_enabled' => 'boolean',
            'share_ai_tokens' => 'boolean',
            'share_vehicles' => 'boolean',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
