<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TravelOrderResource;
use App\Http\Resources\TravelOrderSummaryResource;
use App\Models\TravelOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TravelOrderController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(max((int) $request->integer('limit', 10), 1), 50);
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['nacrt', 'poslano', 'odobreno', 'odbijeno', 'isplaceno'])],
        ]);
        $expenseCountSql = match (DB::connection()->getDriverName()) {
            'pgsql' => 'jsonb_array_length(expenses::jsonb)',
            'sqlite' => 'json_array_length(expenses)',
            default => 'JSON_LENGTH(expenses)',
        };
        $query = $request->user()->travelOrders()
            ->select([
                'client_id',
                'order_number',
                'status',
                'created_at',
                'employee_name',
                'route',
                'purpose',
                'departure_time',
                'total_hours',
                'balance_to_pay',
            ])
            ->selectRaw("COALESCE({$expenseCountSql}, 0) as receipt_count")
            ->latest();

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        return TravelOrderSummaryResource::collection(
            $query->paginate($limit)->withQueryString()
        );
    }

    public function show(Request $request, string $travelOrder)
    {
        $order = $request->user()->travelOrders()
            ->where('client_id', $travelOrder)
            ->firstOrFail();

        return new TravelOrderResource($order);
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

        $expenseTraceId = (string) Str::uuid();
        if ($request->has('expenses')) {
            Log::info('Travel order expense update received', [
                'trace_id' => $expenseTraceId,
                'user_id' => $request->user()->id,
                'travel_order_id' => $travelOrder,
                'stored_before' => $this->expenseLogSummary($order->expenses),
                'incoming' => $this->expenseLogSummary($request->input('expenses')),
            ]);
        }

        $data = $this->validated($request, true, $order);
        $order->update($data);
        $order->refresh();

        if (array_key_exists('expenses', $data)) {
            Log::info('Travel order expense update persisted', [
                'trace_id' => $expenseTraceId,
                'user_id' => $request->user()->id,
                'travel_order_id' => $travelOrder,
                'stored_after' => $this->expenseLogSummary($order->expenses),
            ]);
        }

        return new TravelOrderResource($order);
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
            // Profile details are snapshots, not prerequisites for creating a draft.
            // Older app versions may omit them and empty inputs arrive as null.
            'employeeTitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employeeOib' => ['sometimes', 'nullable', 'string', 'max:50'],
            'employeeIban' => ['sometimes', 'nullable', 'string', 'max:50'],
            // A draft order may be created before company details are filled in.
            // Export is gated in the app until those details are complete.
            'companyName' => [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:255'],
            'companyOib' => [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:50'],
            'route' => [$required, 'string', 'max:1000'],
            'isRoundTrip' => ['sometimes', 'boolean'],
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
            // These fields were added after the first mobile release, so creation
            // must remain compatible with clients that do not send them.
            'breakfastIncluded' => ['sometimes', 'nullable', 'boolean'],
            'lunchIncluded' => ['sometimes', 'nullable', 'boolean'],
            'dinnerIncluded' => ['sometimes', 'nullable', 'boolean'],
            'hotelIncluded' => ['sometimes', 'nullable', 'boolean'],
            'residenceDistanceKm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
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
            'receiptImages' => ['sometimes', 'array', 'max:100'],
            'receiptImages.*.id' => ['required', 'string', 'max:100'],
            'receiptImages.*.expenseId' => ['required', 'string', 'max:100'],
            'receiptImages.*.imageUri' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'receiptImages.*.imageData' => ['sometimes', 'nullable', 'string'],
            'receiptImages.*.imageMimeType' => ['sometimes', 'nullable', 'string', 'max:100'],
            'totalExpensesCost' => [$required, 'numeric', 'min:0'],
            'advancementPaid' => [$required, 'numeric', 'min:0'],
            'grandTotal' => [$required, 'numeric', 'min:0'],
            'balanceToPay' => [$required, 'numeric'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'approvedBy' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Laravel converts empty strings to null; keep database values as empty
        // strings while company details are intentionally unfinished.
        if (array_key_exists('companyName', $data)) {
            $data['companyName'] ??= '';
        }
        if (array_key_exists('companyOib', $data)) {
            $data['companyOib'] ??= '';
        }
        if (array_key_exists('expenses', $data)) {
            $data['expenses'] = array_map(
                fn (array $expense): array => $this->ensureTicketHasItem($expense),
                $data['expenses'],
            );
        }

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
            'isRoundTrip' => 'is_round_trip',
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
            'receiptImages' => 'receipt_images',
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

        if (! $partial && ! array_key_exists('is_round_trip', $data)) {
            $data['is_round_trip'] = true;
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

    private function expenseLogSummary(mixed $expenses): array
    {
        if (! is_array($expenses)) {
            return [];
        }

        return collect($expenses)->map(function (mixed $expense): array {
            if (! is_array($expense)) {
                return ['invalid_expense' => get_debug_type($expense)];
            }

            return [
                'id' => $expense['id'] ?? null,
                'category' => $expense['category'] ?? null,
                'amountInEur' => $expense['amountInEur'] ?? null,
                'originalAmount' => $expense['originalAmount'] ?? null,
                'currency' => $expense['currency'] ?? null,
                'subtotal' => $expense['subtotal'] ?? null,
                'vat' => $expense['vat'] ?? null,
                'items' => collect(is_array($expense['items'] ?? null) ? $expense['items'] : [])
                    ->map(fn (mixed $item): array => is_array($item) ? [
                        'name' => $item['name'] ?? null,
                        'quantity' => $item['quantity'] ?? null,
                        'unitPrice' => $item['unitPrice'] ?? null,
                        'total' => $item['total'] ?? null,
                    ] : ['invalid_item' => get_debug_type($item)])
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    private function ensureTicketHasItem(array $expense): array
    {
        $ticketCategories = ['Avionska karta', 'Autobuska karta', 'Vozna karta', 'Prijevozna karta'];
        if (! in_array($expense['category'] ?? null, $ticketCategories, true) || ! empty($expense['items'])) {
            return $expense;
        }

        $amount = (float) ($expense['originalAmount'] ?? $expense['amountInEur'] ?? 0);
        $expense['items'] = [[
            'name' => ($expense['description'] ?? '') ?: $expense['category'],
            'quantity' => 1,
            'unitPrice' => $amount,
            'total' => $amount,
            'vatRate' => 0,
        ]];

        return $expense;
    }
}
