<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
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
            'teamEnabled' => ['sometimes', 'boolean'],
            'shareAiTokens' => ['sometimes', 'boolean'],
        ]);
        $company = $request->user()->ownedCompany;
        abort_unless($company, 403, 'Only a company owner can change team settings.');

        $company->update(array_filter([
            'team_enabled' => $validated['teamEnabled'] ?? null,
            'share_ai_tokens' => $validated['shareAiTokens'] ?? null,
        ], fn ($value) => $value !== null));

        if (! $company->team_enabled && $company->share_ai_tokens) {
            $company->update(['share_ai_tokens' => false]);
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

    private function payload(Company $company, User $viewer): array
    {
        $company->loadMissing(['owner', 'members']);

        return [
            'id' => $company->id,
            'name' => $company->name,
            'inviteCode' => $company->invite_code,
            'teamEnabled' => $company->team_enabled,
            'shareAiTokens' => $company->share_ai_tokens,
            'isOwner' => $company->owner_id === $viewer->id,
            'owner' => $this->member($company->owner),
            'members' => $company->members
                ->where('id', '!=', $company->owner_id)
                ->values()
                ->map(fn (User $user) => $this->member($user))
                ->all(),
        ];
    }

    private function member(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
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
