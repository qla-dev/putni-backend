<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creditAccount = $this->creditAccount();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone ?? '',
            'oib' => $this->oib ?? '',
            'iban' => $this->iban ?? '',
            'jobTitle' => $this->job_title,
            'remainingAiOrders' => $creditAccount->ai_order_credits,
        ];
    }
}
