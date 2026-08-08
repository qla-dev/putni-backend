<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->client_id,
            'orderNumber' => $this->order_number,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'employeeName' => $this->employee_name,
            'employeeTitle' => $this->employee_title,
            'employeeOib' => $this->employee_oib,
            'employeeIban' => $this->employee_iban,
            'companyName' => $this->company_name,
            'companyOib' => $this->company_oib,
            'route' => $this->route,
            'startLocation' => $this->start_location,
            'destinationCountry' => $this->destination_country,
            'purpose' => $this->purpose,
            'departureTime' => $this->departure_time?->toIso8601String(),
            'arrivalTime' => $this->arrival_time?->toIso8601String(),
            'routeStopTimes' => $this->route_stop_times ?? [],
            'totalHours' => $this->total_hours,
            'transportType' => $this->transport_type,
            'vehicleName' => $this->vehicle_name,
            'vehiclePlate' => $this->vehicle_plate,
            'totalKm' => $this->total_km,
            'totalKmCost' => $this->total_km_cost,
            'dailyAllowanceRateEur' => $this->daily_allowance_rate_eur,
            'totalAllowanceCost' => $this->total_allowance_cost,
            'breakfastIncluded' => $this->breakfast_included,
            'lunchIncluded' => $this->lunch_included,
            'dinnerIncluded' => $this->dinner_included,
            'hotelIncluded' => $this->hotel_included,
            'residenceDistanceKm' => $this->residence_distance_km,
            'expenses' => $this->expenses ?? [],
            'totalExpensesCost' => $this->total_expenses_cost,
            'advancementPaid' => $this->advancement_paid,
            'grandTotal' => $this->grand_total,
            'balanceToPay' => $this->balance_to_pay,
            'notes' => $this->notes,
            'approvedBy' => $this->approved_by,
        ];
    }
}
