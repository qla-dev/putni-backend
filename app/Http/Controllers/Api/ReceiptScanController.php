<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenRouterReceiptScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReceiptScanController extends Controller
{
    public function store(Request $request, OpenRouterReceiptScanner $scanner)
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*.base64' => ['required', 'string'],
            'images.*.mimeType' => ['sometimes', 'nullable', 'string', 'in:image/jpeg,image/png,image/webp,image/heic,image/heif'],
            'documentType' => ['sometimes', 'string', 'in:receipt,air-ticket,transport-ticket'],
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

        $traceId = (string) Str::uuid();
        $documentType = $validated['documentType'] ?? 'receipt';
        Log::info('Receipt scan started', [
            'trace_id' => $traceId,
            'user_id' => $request->user()->id,
            'document_type' => $documentType,
            'image_count' => count($validated['images']),
        ]);

        $result = $scanner->scan($validated['images'], $documentType);

        Log::info('Receipt scan normalized result', [
            'trace_id' => $traceId,
            'user_id' => $request->user()->id,
            'document_type' => $documentType,
            'category' => $result['category'] ?? null,
            'currency' => $result['currency'] ?? null,
            'total' => $result['total'] ?? null,
            'totalInEur' => $result['totalInEur'] ?? null,
            'items' => collect($result['items'] ?? [])->map(fn (array $item): array => [
                'name' => $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unitPrice' => $item['unitPrice'] ?? null,
                'total' => $item['total'] ?? null,
            ])->values()->all(),
        ]);

        return response()->json([
            'data' => $result,
        ])->header('X-Receipt-Scan-Trace-Id', $traceId);
    }
}
