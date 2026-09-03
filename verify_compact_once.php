<?php

use App\Models\ExportFormat;
use App\Models\TravelOrder;
use App\Services\TravelOrderExportService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$order = TravelOrder::findOrFail(42);
$format = ExportFormat::where('name', 'skula')->firstOrFail();
$export = $app->make(TravelOrderExportService::class)->generate($order, $format);
$path = __DIR__.'/SKULA_Adnan_Mandzo_compact.xlsx';
file_put_contents($path, $export['content']);

$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
    throw new RuntimeException('Archive validation failed.');
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
        $document = new DOMDocument();
        if (! $document->loadXML($zip->getFromIndex($index))) {
            throw new RuntimeException("Invalid XML: {$name}");
        }
    }
}
$sheet = new DOMDocument();
$sheet->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
$xpath = new DOMXPath($sheet);
$xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
$value = fn (string $cell): string => trim($xpath->query("//x:c[@r='{$cell}']")->item(0)?->textContent ?? '');

echo 'archive=valid'.PHP_EOL;
echo 'transport_last='.$value('B22').';total_next='.$value('B23').' '.$value('E23').PHP_EOL;
echo 'lodging_last='.$value('B26').';total_next='.$value('B27').' '.$value('E27').PHP_EOL;
echo 'other_last='.$value('B30').';total_next='.$value('B31').' '.$value('E31').PHP_EOL;
echo 'row32='.$value('B32').' '.$value('E32').PHP_EOL;
echo 'dimension='.$xpath->query('/x:worksheet/x:dimension')->item(0)?->getAttribute('ref').PHP_EOL;
echo 'calc_chain='.($zip->locateName('xl/calcChain.xml') === false ? 'removed' : 'present').PHP_EOL;
$zip->close();
