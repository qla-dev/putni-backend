<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExportFormatResource;
use App\Models\ExportFormat;
use App\Services\TravelOrderExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TravelOrderExportController extends Controller
{
    public function index()
    {
        return ExportFormatResource::collection(
            ExportFormat::query()->where('is_active', true)->orderBy('sort_order')->get()
        );
    }

    public function show(
        Request $request,
        string $travelOrder,
        ExportFormat $exportFormat,
        TravelOrderExportService $exporter,
    ): Response {
        abort_unless($exportFormat->is_active, 404);
        $order = $request->user()->travelOrders()
            ->where('client_id', $travelOrder)
            ->firstOrFail();
        $currency = $request->validate([
            'currency' => ['sometimes', 'in:EUR,BAM'],
        ])['currency'] ?? 'EUR';
        $export = $exporter->generate($order, $exportFormat, $currency);

        return response($export['content'], 200, [
            'Content-Type' => $export['mime_type'],
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
