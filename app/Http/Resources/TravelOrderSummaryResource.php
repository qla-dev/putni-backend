<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelOrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->client_id,
            'orderNumber' => $this->order_number,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'employeeName' => $this->employee_name,
            'route' => $this->route,
            'purpose' => $this->purpose,
            'departureTime' => $this->departure_time?->toIso8601String(),
            'totalHours' => $this->total_hours,
            'balanceToPay' => $this->balance_to_pay,
            'receiptCount' => (int) ($this->receipt_count ?? 0),
        ];
    }
}
