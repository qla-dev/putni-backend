<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserVehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserVehicleController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => $request->user()->vehicles()->latest()->get()->map($this->resource(...))]);
    }

    public function catalog()
    {
        $brands = \DB::table('vehicle_brands')
            ->orderBy('name')
            ->get()
            ->map(fn ($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'models' => \DB::table('vehicle_models')
                    ->where('vehicle_brand_id', $brand->id)
                    ->orderBy('name')
                    ->pluck('name')
                    ->values(),
            ]);

        return response()->json(['data' => $brands]);
    }

    public function store(Request $request)
    {
        $vehicle = $request->user()->vehicles()->create($this->validated($request));

        return response()->json(['data' => $this->resource($vehicle)], 201);
    }

    public function update(Request $request, UserVehicle $vehicle)
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
        $vehicle->update($this->validated($request, $vehicle));

        return response()->json(['data' => $this->resource($vehicle->refresh())]);
    }

    public function destroy(Request $request, UserVehicle $vehicle)
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
        $vehicle->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?UserVehicle $vehicle = null): array
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:150'],
            'registrationPlate' => [
                'required', 'string', 'max:32',
                Rule::unique('user_vehicles', 'registration_plate')
                    ->where('user_id', $request->user()->id)
                    ->ignore($vehicle?->id),
            ],
            'ownershipType' => ['required', Rule::in(['privatno', 'poslovno'])],
        ], [], [
            'registrationPlate' => 'registracijske tablice',
        ]);

        return [
            'brand' => trim($data['brand']),
            'model' => trim($data['model']),
            'registration_plate' => strtoupper(trim($data['registrationPlate'])),
            'ownership_type' => $data['ownershipType'],
        ];
    }

    private function resource(UserVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'registrationPlate' => $vehicle->registration_plate,
            'ownershipType' => $vehicle->ownership_type,
        ];
    }
}
