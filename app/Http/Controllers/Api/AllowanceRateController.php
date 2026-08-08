<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowanceRate;

class AllowanceRateController extends Controller
{
    public function index()
    {
        return AllowanceRate::query()
            ->orderByDesc('is_default')
            ->orderBy('country')
            ->get(['country', 'rate_bam', 'is_default'])
            ->map(fn (AllowanceRate $rate) => [
                'country' => $rate->country,
                'rateBam' => $rate->rate_bam,
                'isDefault' => $rate->is_default,
            ]);
    }
}
