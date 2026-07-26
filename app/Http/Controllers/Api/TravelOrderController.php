<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TravelOrderResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TravelOrderController extends Controller
{
    public function index(Request $request)
    {
        return TravelOrderResource::collection(
            $request->user()->travelOrders()->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        [$order, $remainingCredits] = DB::transaction(function () use ($request, $data) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_if($user->ai_order_credits < 1, 422, 'No AI order credits remain.');
            $order = $user->travelOrders()->create($data);
            $user->decrement('ai_order_credits');

            return [$order, (int) $user->refresh()->ai_order_credits];
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
        $order->update($this->validated($request, true));

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

    private function validated(Request $request, bool $partial = false): array
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
            'companyName' => [$required, 'string', 'max:255'],
            'companyOib' => [$required, 'string', 'max:50'],
            'route' => [$required, 'string', 'max:1000'],
            'startLocation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destinationCountry' => [$required, 'string', 'max:255'],
            'purpose' => [$required, 'string', 'max:2000'],
            'departureTime' => [$required, 'date'],
            'arrivalTime' => [$required, 'date', 'after_or_equal:departureTime'],
            'routeStopTimes' => ['sometimes', 'array'],
            'routeStopTimes.*' => ['date'],
            'totalHours' => [$required, 'numeric', 'min:0'],
            'transportType' => [$required, 'string', 'max:100'],
            'vehicleName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vehiclePlate' => ['sometimes', 'nullable', 'string', 'max:100'],
            'totalKm' => [$required, 'numeric', 'min:0'],
            'totalKmCost' => [$required, 'numeric', 'min:0'],
            'dailyAllowanceRateEur' => [$required, 'numeric', 'min:0'],
            'totalAllowanceCost' => [$required, 'numeric', 'min:0'],
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
            'totalHours' => 'total_hours',
            'transportType' => 'transport_type',
            'vehicleName' => 'vehicle_name',
            'vehiclePlate' => 'vehicle_plate',
            'totalKm' => 'total_km',
            'totalKmCost' => 'total_km_cost',
            'dailyAllowanceRateEur' => 'daily_allowance_rate_eur',
            'totalAllowanceCost' => 'total_allowance_cost',
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

        return $data;
    }
}
