<?php

namespace Database\Seeders;

use App\Models\ExportFormat;
use Illuminate\Database\Seeder;

class ExportFormatSeeder extends Seeder
{
    public function run(): void
    {
        $formats = [
            ['name' => 'pdf', 'title' => 'Putni nalog', 'description' => 'Dokument spreman za ispis i dijeljenje', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'handler' => 'pdf', 'icon' => 'file-text', 'color' => '#007AFF', 'is_integration' => false, 'sort_order' => 10],
            ['name' => 'pantheon', 'title' => 'Datalab PANTHEON ERP', 'description' => 'Službeni XML format za Pantheon', 'extension' => 'xml', 'mime_type' => 'text/xml', 'handler' => 'pantheon', 'icon' => 'pantheon', 'color' => '#FF9500', 'is_integration' => true, 'sort_order' => 20],
            ['name' => 'spica', 'title' => 'ŠPICA Time & Space', 'description' => 'Evidencija službenih putovanja', 'extension' => 'csv', 'mime_type' => 'text/csv', 'handler' => 'spica', 'icon' => 'spica', 'color' => '#800020', 'is_integration' => true, 'sort_order' => 30],
            ['name' => 'option', 'title' => 'OPTION ERP Knjigovodstvo', 'description' => 'Format za Option računovodstvo', 'extension' => 'csv', 'mime_type' => 'text/csv', 'handler' => 'option', 'icon' => 'building', 'color' => '#32ADE6', 'is_integration' => true, 'sort_order' => 40],
            ['name' => 'skula', 'title' => 'SKULA ERP', 'description' => 'Obračun troškova službenog putovanja', 'extension' => 'xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'handler' => 'skula', 'icon' => 'graduation-cap', 'color' => '#5856D6', 'is_integration' => true, 'sort_order' => 50],
            ['name' => 'infonet', 'title' => 'Infonet infoERP', 'description' => 'Strukturirani format za API konektor', 'extension' => 'json', 'mime_type' => 'application/json', 'handler' => 'infonet', 'icon' => 'infonet', 'color' => '#34C759', 'is_integration' => true, 'sort_order' => 60],
            ['name' => 'dynamics', 'title' => 'Microsoft Dynamics 365', 'description' => 'Dataverse format za ERP integraciju', 'extension' => 'json', 'mime_type' => 'application/json', 'handler' => 'dynamics', 'icon' => 'dynamics', 'color' => '#34C759', 'is_integration' => true, 'sort_order' => 70],
        ];

        foreach ($formats as $format) {
            ExportFormat::query()->updateOrCreate(['name' => $format['name']], $format);
        }
    }
}
