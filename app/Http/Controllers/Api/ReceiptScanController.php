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
            'documentType' => ['sometimes', 'string', 'in:receipt,air-ticket'],
        ], [
            'images.required' => 'Dodajte barem jednu fotografiju.',
            'images.array' => 'Fotografije nisu poslane u ispravnom formatu.',
            'images.min' => 'Dodajte barem jednu fotografiju.',
            'images.max' => 'Možete dodati najviše 10 fotografija.',
            'images.*.base64.required' => 'Odabranu fotografiju nije moguće obraditi.',
            'images.*.base64.string' => 'Odabrana fotografija nije u ispravnom formatu.',
            'images.*.mimeType.in' => 'Format fotografije nije podržan.',
            'documentType.in' => 'Vrsta dokumenta nije podržana.',
        ]);

        if (! config('services.openrouter.api_key')) {
            return response()->json([
                'message' => 'AI skeniranje računa nije konfigurirano na serveru.',
            ], 503);
        }

        return response()->json([
            'data' => $scanner->scan($validated['images'], $validated['documentType'] ?? 'receipt'),
        ]);
    }
}
