<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LampyrisUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'freeAccessExpiresAt' => $this->free_access_expires_at
                ? $this->free_access_expires_at->getTimestamp() * 1000
                : null,
        ];
    }
}
