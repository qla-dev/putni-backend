<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expenses = collect($this->expenses ?? [])->map(function (array $expense): array {
            if (! empty($expense['items'])) {
                return $expense;
            }

            // Tickets can be attached before a price is known, so the scanner may
            // legitimately return no line items. The receipt editor edits line
            // items, therefore expose a single editable item for these expenses.
            $amount = (float) ($expense['originalAmount'] ?? $expense['amountInEur'] ?? 0);
            $expense['items'] = [[
                'name' => ($expense['description'] ?? '') ?: ($expense['category'] ?? 'Trošak'),
                'quantity' => 1,
                'unitPrice' => $amount,
                'total' => $amount,
            ]];

            return $expense;
        })->values()->all();
        $expenses = collect($expenses)->map(function (array $expense): array {
            unset($expense['imageUri'], $expense['imageData'], $expense['imageMimeType']);

            return $expense;
        })->values()->all();

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
            'isRoundTrip' => $this->is_round_trip,
            'startLocation' => $this->start_location,
            'destinationCountry' => $this->destination_country,
            'purpose' => $this->purpose,
            'departureTime' => $this->departure_time?->toIso8601String(),
            'arrivalTime' => $this->arrival_time?->toIso8601String(),
            'routeStopTimes' => $this->route_stop_times ?? [],
            'routeStopCountries' => $this->route_stop_countries ?? [],
            'totalHours' => $this->total_hours,
            'transportType' => $this->transport_type,
            'vehicleName' => $this->vehicle_name,
            'vehiclePlate' => $this->vehicle_plate,
            'totalKm' => $this->total_km,
            'totalKmCost' => $this->total_km_cost,
            'bmb95Price' => $this->bmb95_price,
            'bmb95Currency' => $this->bmb95_currency ?? 'BAM',
            'dailyAllowanceRateEur' => $this->daily_allowance_rate_eur,
            'dailyAllowanceAuto' => $this->daily_allowance_auto,
            'mainCardType' => $this->main_card_type,
            'totalAllowanceCost' => $this->total_allowance_cost,
            'breakfastIncluded' => $this->breakfast_included,
            'lunchIncluded' => $this->lunch_included,
            'dinnerIncluded' => $this->dinner_included,
            'hotelIncluded' => $this->hotel_included,
            'residenceDistanceKm' => $this->residence_distance_km,
            'expenses' => $expenses,
            'totalExpensesCost' => $this->total_expenses_cost,
            'advancementPaid' => $this->advancement_paid,
            'grandTotal' => $this->grand_total,
            'balanceToPay' => $this->balance_to_pay,
            'notes' => $this->notes,
            'approvedBy' => $this->approved_by,
        ];
    }
}
