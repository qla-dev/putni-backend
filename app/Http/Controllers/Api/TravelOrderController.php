<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TravelOrderResource;
use App\Models\TravelOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TravelOrderController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(max((int) $request->integer('limit', 10), 1), 50);

        return TravelOrderResource::collection(
            $request->user()->travelOrders()->latest()->paginate($limit)
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        [$order, $remainingCredits] = DB::transaction(function () use ($request, $data) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $creditAccount = User::query()->lockForUpdate()->findOrFail($user->creditAccount()->id);
            abort_if($creditAccount->ai_order_credits < 1, 422, 'No AI order credits remain.');
            $order = $user->travelOrders()->create($data);
            $creditAccount->decrement('ai_order_credits');

            return [$order, (int) $creditAccount->refresh()->ai_order_credits];
        });

        return response()->json([
            'data' => [
                'order' => (new TravelOrderResource($order))->resolve($request),
                'remainingAiOrders' => $remainingCredits,
            ],
        ], 201);
    }

    public function update(Request $request, string $travelOrder)
    {
        $order = $request->user()->travelOrders()
            ->where('client_id', $travelOrder)
            ->firstOrFail();
        $order->update($this->validated($request, true, $order));

        return new TravelOrderResource($order->refresh());
    }

    public function destroy(Request $request, string $travelOrder)
    {
        $order = $request->user()->travelOrders()
            ->where('client_id', $travelOrder)
            ->firstOrFail();
        $order->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $partial = false, ?TravelOrder $existingOrder = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'id' => [$required, 'string', 'max:100'],
            'orderNumber' => [$required, 'string', 'max:100'],
            'status' => [$required, Rule::in(['nacrt', 'poslano', 'odobreno', 'odbijeno', 'isplaceno'])],
            'employeeName' => [$required, 'string', 'max:255'],
            'employeeTitle' => [$required, 'string', 'max:255'],
            'employeeOib' => [$required, 'string', 'max:50'],
            'employeeIban' => [$required, 'string', 'max:50'],
            // A draft order may be created before company details are filled in.
            // Export is gated in the app until those details are complete.
            'companyName' => [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:255'],
            'companyOib' => [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:50'],
            'route' => [$required, 'string', 'max:1000'],
            'startLocation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destinationCountry' => [$required, 'string', 'max:255'],
            'purpose' => [$required, 'string', 'max:2000'],
            'departureTime' => [$required, 'date'],
            'arrivalTime' => [$required, 'date', 'after_or_equal:departureTime'],
            'routeStopTimes' => ['sometimes', 'array'],
            'routeStopTimes.*' => ['date'],
            'routeStopCountries' => ['sometimes', 'array'],
            'routeStopCountries.*' => ['nullable', 'string', 'max:255'],
            'totalHours' => [$required, 'numeric', 'min:0'],
            'transportType' => [$required, 'string', 'max:100'],
            'vehicleName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vehiclePlate' => ['sometimes', 'nullable', 'string', 'max:100'],
            'totalKm' => [$required, 'numeric', 'min:0'],
            'totalKmCost' => [$required, 'numeric', 'min:0'],
            'bmb95Price' => ['sometimes', 'numeric', 'min:0'],
            'dailyAllowanceRateEur' => [$required, 'numeric', 'min:0'],
            'totalAllowanceCost' => [$required, 'numeric', 'min:0'],
            'breakfastIncluded' => [$required, 'boolean'],
            'lunchIncluded' => [$required, 'boolean'],
            'dinnerIncluded' => [$required, 'boolean'],
            'hotelIncluded' => [$required, 'boolean'],
            'residenceDistanceKm' => [$required, 'numeric', 'min:0'],
            'expenses' => [$partial ? 'sometimes' : 'present', 'array'],
            'expenses.*.id' => ['required', 'string', 'max:100'],
            'expenses.*.category' => ['required', 'string', 'max:100'],
            'expenses.*.description' => ['required', 'string', 'max:1000'],
            'expenses.*.vendor' => ['present', 'nullable', 'string', 'max:255'],
            'expenses.*.receiptNumber' => ['present', 'nullable', 'string', 'max:100'],
            'expenses.*.date' => ['required', 'date'],
            'expenses.*.amountInEur' => ['required', 'numeric', 'min:0'],
            'expenses.*.paymentMethod' => ['required', 'string', 'max:100'],
            'expenses.*.imageUri' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'expenses.*.imageData' => ['sometimes', 'nullable', 'string'],
            'expenses.*.imageMimeType' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expenses.*.originalAmount' => ['sometimes', 'numeric', 'min:0'],
            'expenses.*.scannedByAi' => ['sometimes', 'boolean'],
            'expenses.*.subtotal' => ['sometimes', 'numeric', 'min:0'],
            'expenses.*.vat' => ['sometimes', 'numeric', 'min:0'],
            'expenses.*.currency' => ['sometimes', 'string', 'max:10'],
            'expenses.*.confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'expenses.*.warnings' => ['sometimes', 'array'],
            'expenses.*.warnings.*' => ['string', 'max:1000'],
            'expenses.*.items' => ['sometimes', 'array'],
            'expenses.*.items.*.name' => ['required', 'string', 'max:500'],
            'expenses.*.items.*.quantity' => ['required', 'numeric', 'min:0'],
            'expenses.*.items.*.unitPrice' => ['required', 'numeric', 'min:0'],
            'expenses.*.items.*.total' => ['required', 'numeric', 'min:0'],
            'expenses.*.items.*.vatRate' => ['sometimes', 'numeric', 'min:0'],
            'totalExpensesCost' => [$required, 'numeric', 'min:0'],
            'advancementPaid' => [$required, 'numeric', 'min:0'],
            'grandTotal' => [$required, 'numeric', 'min:0'],
            'balanceToPay' => [$required, 'numeric'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'approvedBy' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Laravel converts empty strings to null; keep database values as empty
        // strings while company details are intentionally unfinished.
        if (array_key_exists('companyName', $data)) $data['companyName'] ??= '';
        if (array_key_exists('companyOib', $data)) $data['companyOib'] ??= '';

        $map = [
            'id' => 'client_id',
            'orderNumber' => 'order_number',
            'employeeName' => 'employee_name',
            'employeeTitle' => 'employee_title',
            'employeeOib' => 'employee_oib',
            'employeeIban' => 'employee_iban',
            'companyName' => 'company_name',
            'companyOib' => 'company_oib',
            'startLocation' => 'start_location',
            'destinationCountry' => 'destination_country',
            'departureTime' => 'departure_time',
            'arrivalTime' => 'arrival_time',
            'routeStopTimes' => 'route_stop_times',
            'routeStopCountries' => 'route_stop_countries',
            'totalHours' => 'total_hours',
            'transportType' => 'transport_type',
            'vehicleName' => 'vehicle_name',
            'vehiclePlate' => 'vehicle_plate',
            'totalKm' => 'total_km',
            'totalKmCost' => 'total_km_cost',
            'bmb95Price' => 'bmb95_price',
            'dailyAllowanceRateEur' => 'daily_allowance_rate_eur',
            'totalAllowanceCost' => 'total_allowance_cost',
            'breakfastIncluded' => 'breakfast_included',
            'lunchIncluded' => 'lunch_included',
            'dinnerIncluded' => 'dinner_included',
            'hotelIncluded' => 'hotel_included',
            'residenceDistanceKm' => 'residence_distance_km',
            'totalExpensesCost' => 'total_expenses_cost',
            'advancementPaid' => 'advancement_paid',
            'grandTotal' => 'grand_total',
            'balanceToPay' => 'balance_to_pay',
            'approvedBy' => 'approved_by',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $data[$column] = $data[$input];
                unset($data[$input]);
            }
        }

        if ($partial && $existingOrder) {
            $data = array_merge([
                'total_hours' => $existingOrder->total_hours,
                'total_km' => $existingOrder->total_km,
                'daily_allowance_rate_eur' => $existingOrder->daily_allowance_rate_eur,
                'total_km_cost' => $existingOrder->total_km_cost,
                'bmb95_price' => $existingOrder->bmb95_price,
                'total_expenses_cost' => $existingOrder->total_expenses_cost,
                'advancement_paid' => $existingOrder->advancement_paid,
                'breakfast_included' => $existingOrder->breakfast_included,
                'lunch_included' => $existingOrder->lunch_included,
                'dinner_included' => $existingOrder->dinner_included,
                'hotel_included' => $existingOrder->hotel_included,
                'residence_distance_km' => $existingOrder->residence_distance_km,
            ], $data);
        }

        return $data;
    }
}
