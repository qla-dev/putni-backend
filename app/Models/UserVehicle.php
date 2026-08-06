<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserVehicle extends Model
{
    use HasFactory;

    protected $fillable = ['brand', 'model', 'registration_plate', 'ownership_type', 'share_with_team'];

    protected function casts(): array
    {
        return ['share_with_team' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
