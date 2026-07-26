<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class RevenueCatController extends Controller
{
    public function consumeCredit(Request $request)
    {
        $user = DB::transaction(function () use ($request) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_if($user->ai_order_credits < 1, 422, 'No AI order credits remain.');
            $user->decrement('ai_order_credits');

            return $user->refresh();
        });

        return response()->json(['data' => ['user' => new UserResource($user)]]);
    }

    public function syncCredits(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
        ]);
        $credits = $this->creditsForProduct($validated['product_id']);
        abort_if($credits === null, 422, 'This RevenueCat product is not mapped to an AI credit package.');

        $apiKey = config("services.revenuecat.{$validated['platform']}_api_key")
            ?: config('services.revenuecat.secret_api_key');
        if (! $apiKey) {
            return response()->json(['message' => 'RevenueCat API key is not configured.'], 503);
        }

        $appUserId = "putni-user-{$request->user()->id}";
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->withHeader('X-Platform', $validated['platform'])
            ->timeout(8)
            ->get('https://api.revenuecat.com/v1/subscribers/'.rawurlencode($appUserId));

        if (! $response->successful()) {
            return response()->json(['message' => 'RevenueCat purchase verification failed.'], 502);
        }

        $transactions = $response->json("subscriber.non_subscriptions.{$validated['product_id']}", []);
        $granted = DB::transaction(function () use ($request, $transactions, $validated, $credits) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $granted = 0;

            foreach ($transactions as $transaction) {
                $transactionId = $transaction['id'] ?? null;
                if (! is_string($transactionId) || $transactionId === '') {
                    continue;
                }
                $created = DB::table('revenuecat_purchases')->insertOrIgnore([
                    'user_id' => $user->id,
                    'transaction_id' => $transactionId,
                    'product_id' => $validated['product_id'],
                    'credits' => $credits,
                    'purchased_at' => $transaction['purchase_date'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($created) {
                    $granted += $credits;
                }
            }

            if ($granted > 0) {
                $user->increment('ai_order_credits', $granted);
            }

            return $granted;
        });

        return response()->json([
            'data' => [
                'app_user_id' => $appUserId,
                'granted' => $granted,
                'user' => new UserResource($request->user()->refresh()),
            ],
        ]);
    }

    private function creditsForProduct(string $productId): ?int
    {
        $products = config('services.revenuecat.credit_products', []);
        if (isset($products[$productId])) {
            return (int) $products[$productId];
        }
        if (preg_match('/(?:^|[^0-9])(10|25)(?:[^0-9]|$)/', $productId, $match)) {
            return (int) $match[1];
        }

        return null;
    }
}
