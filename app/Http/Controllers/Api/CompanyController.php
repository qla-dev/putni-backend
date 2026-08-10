<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TravelOrderResource;
use App\Models\Company;
use App\Models\User;
use App\Models\UserVehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user()->companies()->first();

        return $company
            ? response()->json(['data' => $this->payload($company, $request->user())])
            : response()->json(['data' => null]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        $existing = $user->companies()->first();

        if ($existing) {
            return response()->json(['data' => $this->payload($existing, $user)]);
        }

        $company = DB::transaction(function () use ($user, $validated) {
            $company = Company::create([
                'owner_id' => $user->id,
                'name' => $validated['name'],
                'invite_code' => $this->uniqueInviteCode(),
            ]);
            $company->members()->attach($user->id, ['role' => 'owner']);

            return $company;
        });

        return response()->json(['data' => $this->payload($company, $user)], 201);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'oib' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:50'],
            'teamEnabled' => ['sometimes', 'boolean'],
            'shareAiTokens' => ['sometimes', 'boolean'],
            'shareVehicles' => ['sometimes', 'boolean'],
        ]);
        $company = $request->user()->ownedCompany;
        abort_unless($company, 403, 'Only a company owner can change team settings.');

        $company->update(array_filter([
            'name' => isset($validated['name']) ? trim($validated['name']) : null,
            'oib' => array_key_exists('oib', $validated) ? trim((string) $validated['oib']) : null,
            'address' => array_key_exists('address', $validated) ? trim((string) $validated['address']) : null,
            'city' => array_key_exists('city', $validated) ? trim((string) $validated['city']) : null,
            'country' => array_key_exists('country', $validated) ? trim((string) $validated['country']) : null,
            'email' => array_key_exists('email', $validated) ? trim((string) $validated['email']) : null,
            'phone' => array_key_exists('phone', $validated) ? trim((string) $validated['phone']) : null,
            'iban' => array_key_exists('iban', $validated) ? trim((string) $validated['iban']) : null,
            'team_enabled' => $validated['teamEnabled'] ?? null,
            'share_ai_tokens' => $validated['shareAiTokens'] ?? null,
            'share_vehicles' => $validated['shareVehicles'] ?? null,
        ], fn ($value) => $value !== null));

        if (! $company->team_enabled && ($company->share_ai_tokens || $company->share_vehicles)) {
            $company->update(['share_ai_tokens' => false, 'share_vehicles' => false]);
        }

        return response()->json(['data' => $this->payload($company, $request->user())]);
    }

    public function join(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z]{3}[0-9]{3}$/'],
        ]);
        $user = $request->user();
        abort_if($user->ownedCompany()->exists(), 422, 'Company owners cannot join another company.');

        $company = Company::where('invite_code', Str::upper($validated['code']))->first();
        abort_unless($company && $company->team_enabled, 404, 'Invitation code was not found.');

        DB::transaction(function () use ($company, $user) {
            $user->companies()->detach();
            $company->members()->syncWithoutDetaching([$user->id => ['role' => 'member']]);
        });

        return response()->json(['data' => $this->payload($company, $user)]);
    }

    public function member(Request $request, User $member)
    {
        $company = $request->user()->ownedCompany;
        abort_unless($company, 403, 'Only a company owner can view member data.');
        abort_unless(
            $company->members()->where('users.id', $member->id)->exists(),
            404,
            'Company member was not found.',
        );

        return response()->json(['data' => [
            'member' => $this->memberPayload($member),
            'remainingAiOrders' => (int) $member->ai_order_credits,
            'orders' => TravelOrderResource::collection(
                $member->travelOrders()
                    ->where('company_name', $company->name)
                    ->latest()
                    ->get(),
            )->resolve($request),
        ]]);
    }

    public function removeMember(Request $request, User $member)
    {
        $company = $request->user()->ownedCompany;
        abort_unless($company, 403, 'Only a company owner can remove members.');
        abort_if($company->owner_id === $member->id, 422, 'The company owner cannot be removed.');
        abort_unless(
            $company->members()->where('users.id', $member->id)->exists(),
            404,
            'Company member was not found.',
        );

        $company->members()->detach($member->id);

        return response()->json(['data' => $this->payload($company->fresh(), $request->user())]);
    }

    private function payload(Company $company, User $viewer): array
    {
        $company->loadMissing(['owner', 'members']);
        $teamOrdersProcessed = $company->members()
            ->withCount([
                'travelOrders as team_orders_count' => fn ($query) => $query
                    ->where('company_name', $company->name),
            ])
            ->get()
            ->sum('team_orders_count');
        $companyVehicles = $company->share_vehicles
            ? UserVehicle::query()
                ->with('user:id,name')
                ->whereIn('user_id', $company->members->pluck('id'))
                ->where('share_with_team', true)
                ->latest()
                ->get()
            : collect();

        return [
            'id' => $company->id,
            'name' => $company->name,
            'oib' => $company->oib ?? '',
            'address' => $company->address ?? '',
            'city' => $company->city ?? '',
            'country' => $company->country ?? '',
            'email' => $company->email ?? '',
            'phone' => $company->phone ?? '',
            'iban' => $company->iban ?? '',
            'inviteCode' => $company->invite_code,
            'teamEnabled' => $company->team_enabled,
            'shareAiTokens' => $company->share_ai_tokens,
            'shareVehicles' => $company->share_vehicles,
            'companyVehicles' => $companyVehicles->map(fn (UserVehicle $vehicle) => [
                'id' => $vehicle->id,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'registrationPlate' => $vehicle->registration_plate,
                'ownershipType' => $vehicle->ownership_type,
                'shareWithTeam' => true,
                'ownerId' => $vehicle->user_id,
                'ownerName' => $vehicle->user->name,
                'isMine' => $vehicle->user_id === $viewer->id,
            ])->values()->all(),
            'teamOrdersProcessed' => $teamOrdersProcessed,
            'teamRemainingAiOrders' => $company->share_ai_tokens
                ? (int) $company->owner->ai_order_credits
                : 0,
            'isOwner' => $company->owner_id === $viewer->id,
            'owner' => $this->memberPayload($company->owner),
            'members' => $company->members
                ->where('id', '!=', $company->owner_id)
                ->values()
                ->map(fn (User $user) => $this->memberPayload($user))
                ->all(),
        ];
    }

    private function memberPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'jobTitle' => $user->job_title,
        ];
    }

    private function uniqueInviteCode(): string
    {
        do {
            $letters = implode('', array_map(
                fn () => chr(random_int(65, 90)),
                range(1, 3)
            ));
            $code = $letters.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        } while (Company::where('invite_code', $code)->exists());

        return $code;
    }
}
