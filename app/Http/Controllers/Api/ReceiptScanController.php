<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenRouterReceiptScanner;
use Illuminate\Http\Request;

class ReceiptScanController extends Controller
{
    public function store(Request $request, OpenRouterReceiptScanner $scanner)
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*.base64' => ['required', 'string'],
            'images.*.mimeType' => ['sometimes', 'nullable', 'string', 'in:image/jpeg,image/png,image/webp,image/heic,image/heif'],
        ]);

        if (! config('services.openrouter.api_key')) {
            return response()->json([
                'message' => 'AI skeniranje računa nije konfigurirano na serveru.',
            ], 503);
        }

        return response()->json(['data' => $scanner->scan($validated['images'])]);
    }
}
