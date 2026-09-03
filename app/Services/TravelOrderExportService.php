<?php

namespace App\Services;

use App\Models\ExportFormat;
use App\Models\TravelOrder;
use DateTimeInterface;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class TravelOrderExportService
{
    private const BAM_RATE = 1.95583;

    public function generate(TravelOrder $order, ExportFormat $format, string $currency = 'BAM', bool $includeImages = false): array
    {
        $content = match ($format->handler) {
            'pdf' => $this->pdf($order, $currency),
            'pantheon' => $this->pantheon($order),
            'spica' => $this->spica($order),
            'option' => $this->option($order),
            'skula' => $this->skula($order),
            'infonet' => $this->infonet($order),
            'dynamics' => $this->dynamics($order),
            default => throw new RuntimeException("Unknown export handler [{$format->handler}]."),
        };

        $export = [
            'content' => $content,
            'filename' => $this->filename($format, $order),
            'mime_type' => $format->mime_type,
        ];

        return $includeImages ? $this->withImages($order, $export) : $export;
    }

    private function withImages(TravelOrder $order, array $export): array
    {
        $path = tempnam(sys_get_temp_dir(), 'putni_export_');
        throw_unless($path !== false, new RuntimeException('ZIP export could not be prepared.'));
        $zip = new ZipArchive;
        throw_unless($zip->open($path, ZipArchive::OVERWRITE) === true, new RuntimeException('ZIP export could not be created.'));
        $zip->addFromString($export['filename'], $export['content']);
        $receiptImages = collect($order->receipt_images ?? []);
        foreach ((array) $order->expenses as $expense) {
            if (! is_array($expense) || (empty($expense['imageData']) && empty($expense['imageUri']))) {
                continue;
            }
            if ($receiptImages->contains(fn (mixed $image): bool => is_array($image)
                && ($image['expenseId'] ?? null) === ($expense['id'] ?? null)
                && ((! empty($expense['imageData']) && ($image['imageData'] ?? null) === $expense['imageData'])
                    || (! empty($expense['imageUri']) && ($image['imageUri'] ?? null) === $expense['imageUri'])))) {
                continue;
            }
            $receiptImages->prepend([
                'id' => 'legacy-'.($expense['id'] ?? ''),
                'expenseId' => $expense['id'] ?? '',
                'imageData' => $expense['imageData'] ?? null,
                'imageUri' => $expense['imageUri'] ?? null,
                'imageMimeType' => $expense['imageMimeType'] ?? null,
            ]);
        }
        $expensesById = collect((array) $order->expenses)->keyBy('id');
        foreach ($receiptImages->values()->all() as $index => $image) {
            $data = is_array($image) ? (string) ($image['imageData'] ?? '') : '';
            $binary = null;
            if ($data !== '') {
                if (str_starts_with($data, 'data:')) {
                    $data = (string) (explode(',', $data, 2)[1] ?? '');
                }
                $binary = base64_decode($data, true);
            } elseif (is_array($image)) {
                $uriPath = parse_url((string) ($image['imageUri'] ?? ''), PHP_URL_PATH);
                if ($uriPath && str_starts_with($uriPath, '/uploads/receipts/')) {
                    $absolutePath = public_path(ltrim($uriPath, '/'));
                    if (File::exists($absolutePath)) {
                        $binary = File::get($absolutePath);
                    }
                }
            }
            if (! is_string($binary) || $binary === '') {
                continue;
            }
            $mime = is_array($image) ? (string) ($image['imageMimeType'] ?? 'image/jpeg') : 'image/jpeg';
            $extension = match ($mime) {
                'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
            };
            $expense = is_array($image) ? $expensesById->get($image['expenseId'] ?? '') : null;
            $label = preg_replace('/[^\pL\pN_.-]+/u', '_', (string) ($expense['vendor'] ?? $expense['category'] ?? 'racun'));
            $zip->addFromString('galerija/'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)."_{$label}.{$extension}", $binary);
        }
        $zip->close();
        $content = file_get_contents($path);
        @unlink($path);
        throw_unless(is_string($content), new RuntimeException('ZIP export could not be read.'));

        return ['content' => $content, 'filename' => preg_replace('/\.[^.]+$/', '', $export['filename']).'.zip', 'mime_type' => 'application/zip'];
    }

    private function filename(ExportFormat $format, TravelOrder $order): string
    {
        $prefix = match ($format->name) {
            'pdf' => 'Putni_nalog',
            'pantheon' => 'Pantheon',
            'spica' => 'Spica',
            'option' => 'OptionERP',
            'skula' => 'SKULA',
            'infonet' => 'Infonet_infoERP',
            'dynamics' => 'Dynamics365',
            default => $format->name,
        };
        $number = preg_replace('/[^\pL\pN_.-]+/u', '_', $order->order_number);

        return "{$prefix}_{$number}.{$format->extension}";
    }

    private function pdf(TravelOrder $order, string $currency): string
    {
        $lines = [
            "PUTNI NALOG {$order->order_number}",
            "Relacija: {$order->route}",
            "Zaposlenik: {$order->employee_name}",
            "Svrha: {$order->purpose}",
            '',
            'Dnevnice: '.$this->money($order->total_allowance_cost, $currency),
            'Kilometraza: '.$this->money($order->total_km_cost, $currency),
            'Ostali troskovi: '.$this->money($order->total_expenses_cost, $currency),
            'Akontacija: '.$this->money($order->advancement_paid, $currency),
            'Za isplatu: '.$this->money($order->balance_to_pay, $currency),
        ];
        $stream = "BT\n/F1 12 Tf\n50 790 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $stream .= "0 -24 Td\n";
            }
            $stream .= '('.$this->pdfText($line).") Tj\n";
        }
        $stream .= "ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function pantheon(TravelOrder $order): string
    {
        $expenses = collect($order->expenses)->map(fn (array $expense) => '
        <Expense>
          <ReceiptNo>'.$this->xml($expense['receiptNumber'] ?? '').'</ReceiptNo>
          <Category>'.$this->xml($expense['category'] ?? '').'</Category>
          <Vendor>'.$this->xml($expense['vendor'] ?? '').'</Vendor>
          <AmountEUR>'.number_format((float) ($expense['amountInEur'] ?? 0), 2, '.', '').'</AmountEUR>
        </Expense>')->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?>
<PantheonTravelOrdersExport xmlns="http://www.datalab.eu/pantheon/travelorders">
  <Header><ExportDate>'.now()->toIso8601String().'</ExportDate><System>Putni Nalozi</System></Header>
  <Orders><TravelOrder>
    <DocumentNo>'.$this->xml($order->order_number).'</DocumentNo>
    <Status>'.strtoupper($order->status).'</Status>
    <EmployeeCode>'.$this->xml($order->employee_oib).'</EmployeeCode>
    <EmployeeName>'.$this->xml($order->employee_name).'</EmployeeName>
    <IBAN>'.$this->xml($order->employee_iban).'</IBAN>
    <Route>'.$this->xml($order->route).'</Route>
    <Purpose>'.$this->xml($order->purpose).'</Purpose>
    <DateStart>'.$order->departure_time->toIso8601String().'</DateStart>
    <DateEnd>'.$order->arrival_time->toIso8601String().'</DateEnd>
    <Hours>'.$order->total_hours.'</Hours>
    <Country>'.$this->xml($order->destination_country).'</Country>
    <AllowanceAmount>'.number_format($order->total_allowance_cost, 2, '.', '').'</AllowanceAmount>
    <Kilometers>'.$order->total_km.'</Kilometers>
    <KilometerAmount>'.number_format($order->total_km_cost, 2, '.', '').'</KilometerAmount>
    <OtherExpenses>'.number_format($order->total_expenses_cost, 2, '.', '').'</OtherExpenses>
    <AdvancementAmount>'.number_format($order->advancement_paid, 2, '.', '').'</AdvancementAmount>
    <TotalPayable>'.number_format($order->balance_to_pay, 2, '.', '').'</TotalPayable>
    <ExpensesList>'.$expenses.'</ExpensesList>
  </TravelOrder></Orders>
</PantheonTravelOrdersExport>';
    }

    private function spica(TravelOrder $order): string
    {
        $header = 'CardNo/OIB;Employee;DateFrom;TimeFrom;DateTo;TimeTo;TravelCode;Location;TotalHours;Status';
        $row = [
            $order->employee_oib,
            $this->csv($order->employee_name),
            $order->departure_time->format('Y-m-d'),
            $order->departure_time->format('H:i'),
            $order->arrival_time->format('Y-m-d'),
            $order->arrival_time->format('H:i'),
            'PN_SLUZBENI_PUT',
            $this->csv($order->destination_country),
            $order->total_hours,
            $order->status,
        ];

        return "\xEF\xBB\xBF{$header}\n".implode(';', $row);
    }

    private function option(TravelOrder $order): string
    {
        $header = 'BR_NALOGA;OIB_ZAPOSLENIK;IME_PREZIME;DATUM_NALOGA;RELACIJA;IZNOS_DNEVNICE;IZNOS_KILOMETRAZA;IZNOS_TROSKOVI;UKUPNO_ZA_ISPLATU;IBAN_ZAPOSLENIK';
        $row = [
            $order->order_number,
            $order->employee_oib,
            $this->csv($order->employee_name),
            $order->created_at->format('Y-m-d'),
            $this->csv($order->route),
            number_format($order->total_allowance_cost, 2, '.', ''),
            number_format($order->total_km_cost, 2, '.', ''),
            number_format($order->total_expenses_cost, 2, '.', ''),
            number_format($order->balance_to_pay, 2, '.', ''),
            $order->employee_iban,
        ];

        return "\xEF\xBB\xBF{$header}\n".implode(';', $row);
    }

    private function infonet(TravelOrder $order): string
    {
        return $this->json([
            'schema' => 'putni-nalozi/infoerp/v1',
            'exportedAt' => now()->toIso8601String(),
            'document' => [
                'number' => $order->order_number,
                'status' => $order->status,
                'createdAt' => $order->created_at->toIso8601String(),
                'employee' => ['name' => $order->employee_name, 'oib' => $order->employee_oib, 'iban' => $order->employee_iban, 'position' => $order->employee_title],
                'company' => ['name' => $order->company_name, 'oib' => $order->company_oib],
                'travel' => ['route' => $order->route, 'purpose' => $order->purpose, 'country' => $order->destination_country, 'departure' => $order->departure_time->toIso8601String(), 'arrival' => $order->arrival_time->toIso8601String(), 'hours' => $order->total_hours, 'transport' => $order->transport_type, 'kilometers' => $order->total_km],
                'amounts' => ['allowance' => $order->total_allowance_cost, 'mileage' => $order->total_km_cost, 'expenses' => $order->total_expenses_cost, 'advance' => $order->advancement_paid, 'total' => $order->grand_total, 'balanceToPay' => $order->balance_to_pay, 'currency' => 'EUR'],
                'expenses' => $order->expenses,
            ],
        ]);
    }

    private function dynamics(TravelOrder $order): string
    {
        return $this->json([
            'schema' => 'putni-nalozi/dynamics-dataverse/v1',
            'entitySet' => 'qla_travelorders',
            'exportedAt' => now()->toIso8601String(),
            'record' => [
                'qla_name' => $order->order_number,
                'qla_status' => $order->status,
                'qla_createddate' => $order->created_at->toIso8601String(),
                'qla_employeename' => $order->employee_name,
                'qla_employeeoib' => $order->employee_oib,
                'qla_employeeiban' => $order->employee_iban,
                'qla_companyname' => $order->company_name,
                'qla_companyoib' => $order->company_oib,
                'qla_route' => $order->route,
                'qla_purpose' => $order->purpose,
                'qla_destinationcountry' => $order->destination_country,
                'qla_departure' => $order->departure_time->toIso8601String(),
                'qla_arrival' => $order->arrival_time->toIso8601String(),
                'qla_totalhours' => $order->total_hours,
                'qla_transporttype' => $order->transport_type,
                'qla_totalkilometers' => $order->total_km,
                'qla_allowanceamount' => $order->total_allowance_cost,
                'qla_mileageamount' => $order->total_km_cost,
                'qla_expensesamount' => $order->total_expenses_cost,
                'qla_advanceamount' => $order->advancement_paid,
                'qla_totalamount' => $order->grand_total,
                'qla_balancetopay' => $order->balance_to_pay,
                'transactioncurrency' => 'EUR',
                'qla_expensesjson' => json_encode($order->expenses, JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    private function skula(TravelOrder $order): string
    {
        $template = resource_path('exports/skula-template.xlsx');
        throw_unless(is_file($template), new RuntimeException('SKULA export template is missing.'));
        $temporary = tempnam(sys_get_temp_dir(), 'skula_');
        throw_unless($temporary !== false && copy($template, $temporary), new RuntimeException('SKULA export could not be prepared.'));
        $zip = new ZipArchive;
        throw_unless($zip->open($temporary) === true, new RuntimeException('SKULA export archive is invalid.'));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        throw_unless(is_string($xml), new RuntimeException('SKULA worksheet is missing.'));
        $xml = $this->normalWorksheetView($xml);

        $expenseRows = [14, 15, 16, 17, 20, 21, 22, 25, 26, 27, 28, 29, 30, 31, 32, 33];
        $expenses = collect($order->expenses)->values();
        $allowanceQuantity = round($order->total_hours / 24, 2);
        $allowanceRate = $this->bam($order->daily_allowance_rate_eur);
        $allowanceTotal = $this->bam($order->total_allowance_cost);
        $mileageTotal = $this->bam($order->total_km_cost);
        $listedExpensesTotal = $expenses
            ->take(count($expenseRows))
            ->sum(fn (array $expense): float => $this->bam((float) ($expense['amountInEur'] ?? 0)));
        $advance = $this->bam($order->advancement_paid);
        $expenseTotal = round($allowanceTotal + $mileageTotal + $listedExpensesTotal, 2);
        $balance = $this->bam($order->balance_to_pay);
        $replacements = [
            'C3' => mb_strtoupper($order->employee_name, 'UTF-8'),
            'E3' => "Broj službenog naloga:                       {$order->order_number}",
            'C4' => $this->date($order->departure_time),
            'E4' => $order->departure_time->format('H:i'),
            'C5' => $this->date($order->arrival_time),
            'E5' => $order->arrival_time->format('H:i'),
            'C6' => "{$order->total_hours} SATI",
            'A9' => 1,
            'B9' => 'DNEVNICA',
            'C9' => $allowanceQuantity,
            'D9' => $allowanceRate,
            'E9' => $allowanceTotal,
            'E12' => $allowanceTotal,
            'E35' => $expenseTotal,
            'E36' => $advance,
            'E37' => max($balance, 0),
            'E38' => max(-$balance, 0),
            'C40' => $this->date(now()),
            'B45' => $order->employee_name,
            'C45' => $order->approved_by,
        ];
        foreach ($replacements as $cell => $value) {
            $style = match ($cell) {
                'C9', 'D9' => 91,
                'E9', 'E12', 'E35', 'E36', 'E37', 'E38' => 90,
                default => null,
            };
            $xml = $this->replaceCell($xml, $cell, $value, $style);
        }
        foreach ($expenseRows as $index => $row) {
            $expense = $expenses->get($index);
            $amount = $expense ? $this->bam((float) ($expense['amountInEur'] ?? 0)) : null;
            $xml = $this->replaceCell($xml, "A{$row}", $expense ? $index + 1 : null);
            $xml = $this->replaceCell($xml, "B{$row}", $expense ? $this->expenseVendor($expense) : null);
            $xml = $this->replaceCell($xml, "C{$row}", $expense ? 1 : null, 91);
            $xml = $this->replaceCell($xml, "D{$row}", $amount, 91);
            $xml = $this->replaceCell($xml, "E{$row}", $amount ?? 0, 90);
        }
        foreach ([18 => [0, 4], 23 => [4, 3], 34 => [7, 9]] as $row => [$offset, $length]) {
            $sectionTotal = $expenses
                ->slice($offset, $length)
                ->sum(fn (array $expense): float => $this->bam((float) ($expense['amountInEur'] ?? 0)));
            $xml = $this->replaceCell($xml, "E{$row}", round($sectionTotal, 2), 90);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $this->removeCalculationChain($zip);
        $zip->close();
        $content = file_get_contents($temporary);
        @unlink($temporary);
        throw_unless(is_string($content), new RuntimeException('SKULA export could not be read.'));

        return $content;
    }

    private function replaceCell(string $xml, string $cell, string|int|float|null $value, ?int $style = null): string
    {
        $pattern = '/<c r="'.preg_quote($cell, '/').'"([^>]*?)(?:\s*\/>|>.*?<\/c>)/s';
        if (! preg_match($pattern, $xml, $match)) {
            return $xml;
        }
        $attributes = preg_replace('/\s+t="[^"]*"/', '', $match[1]);
        if ($style !== null) {
            $attributes = preg_replace('/\s+s="[^"]*"/', '', $attributes).' s="'.$style.'"';
        }
        if ($value === null) {
            $content = '';
            $type = '';
        } elseif (is_numeric($value)) {
            $content = '<v>'.$value.'</v>';
            $type = '';
        } else {
            $content = '<is><t xml:space="preserve">'.$this->xml((string) $value).'</t></is>';
            $type = ' t="inlineStr"';
        }

        return preg_replace($pattern, '<c r="'.$cell.'"'.$attributes.$type.'>'.$content.'</c>', $xml, 1) ?? $xml;
    }

    private function normalWorksheetView(string $xml): string
    {
        return preg_replace_callback('/<sheetView\b([^>]*)>/', function (array $match): string {
            $attributes = preg_match('/\sview="[^"]*"/', $match[1])
                ? preg_replace('/\sview="[^"]*"/', ' view="normal"', $match[1], 1)
                : $match[1].' view="normal"';

            return '<sheetView'.$attributes.'>';
        }, $xml, 1) ?? $xml;
    }

    private function expenseVendor(array $expense): string
    {
        foreach ([$expense['vendor'] ?? null, $expense['description'] ?? null, $expense['category'] ?? null] as $label) {
            if (is_string($label) && trim($label) !== '') {
                return mb_strtoupper(trim($label), 'UTF-8');
            }
        }

        return '';
    }

    private function removeCalculationChain(ZipArchive $zip): void
    {
        if ($zip->locateName('xl/calcChain.xml') !== false) {
            $zip->deleteName('xl/calcChain.xml');
        }

        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (is_string($relationships)) {
            $relationships = preg_replace('/<Relationship\b[^>]*Type="[^"]*\/calcChain"[^>]*\/>/', '', $relationships) ?? $relationships;
            $zip->addFromString('xl/_rels/workbook.xml.rels', $relationships);
        }

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if (is_string($contentTypes)) {
            $contentTypes = preg_replace('/<Override\b[^>]*PartName="\/xl\/calcChain\.xml"[^>]*\/>/', '', $contentTypes) ?? $contentTypes;
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        }
    }

    private function xml(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function csv(mixed $value): string
    {
        return '"'.str_replace('"', '""', (string) $value).'"';
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function money(float $value, string $currency): string
    {
        $converted = $currency === 'BAM' ? $this->bam($value) : $value;

        return number_format($converted, 2, ',', '.').' '.($currency === 'BAM' ? 'KM' : 'EUR');
    }

    private function bam(float $value): float
    {
        return round($value * self::BAM_RATE, 2);
    }

    private function date(DateTimeInterface $date): string
    {
        return $date->format('d.m.Y');
    }

    private function pdfText(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
