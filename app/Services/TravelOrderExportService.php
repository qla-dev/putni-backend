<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ExportFormat;
use App\Models\TravelOrder;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class TravelOrderExportService
{
    private const BAM_RATE = 1.95583;

    public function generate(TravelOrder $order, ExportFormat $format, string $currency = 'BAM', bool $includeImages = false): array
    {
        $content = match ($format->handler) {
            'pdf' => $this->pdf($order),
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

    private function pdf(TravelOrder $order): string
    {
        return (new SkulaPdfRenderer)->render($this->skula($order));
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
        $headerStyles = $this->addHeaderStyles($zip);
        $appLinkRelation = $this->addAppLinkRelation($zip);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        throw_unless(is_string($xml), new RuntimeException('SKULA worksheet is missing.'));

        $expenses = collect($order->expenses)->values();
        $expenseGroups = $this->groupSkulaExpenses($expenses->all());
        $transportCount = count($expenseGroups['transport']);
        $transportRowDelta = $transportCount - 4;
        if ($transportRowDelta > 0) {
            $xml = $this->insertWorksheetRows($xml, 18, $transportRowDelta, 15);
        } elseif ($transportRowDelta < 0) {
            $xml = $this->removeWorksheetRows($xml, 14 + $transportCount, -$transportRowDelta);
        }
        $lodgingCount = count($expenseGroups['lodging']);
        $lodgingRowDelta = $lodgingCount - 3;
        $lodgingStartRow = 20 + $transportRowDelta;
        $lodgingTotalRow = 23 + $transportRowDelta;
        if ($lodgingRowDelta > 0) {
            $xml = $this->insertWorksheetRows($xml, $lodgingTotalRow, $lodgingRowDelta, 21 + $transportRowDelta);
        } elseif ($lodgingRowDelta < 0) {
            $xml = $this->removeWorksheetRows($xml, $lodgingStartRow + $lodgingCount, -$lodgingRowDelta);
        }
        $otherCount = count($expenseGroups['other']);
        $otherRowDelta = $otherCount - 9;
        $otherStartRow = 25 + $transportRowDelta + $lodgingRowDelta;
        $otherTotalRow = 34 + $transportRowDelta + $lodgingRowDelta;
        if ($otherRowDelta > 0) {
            $xml = $this->insertWorksheetRows($xml, $otherTotalRow, $otherRowDelta, $otherTotalRow - 2);
        } elseif ($otherRowDelta < 0) {
            $xml = $this->removeWorksheetRows($xml, $otherStartRow + $otherCount, -$otherRowDelta);
        }
        $rowDelta = $transportRowDelta + $lodgingRowDelta + $otherRowDelta;
        $sectionRows = [
            'transport' => $transportCount > 0 ? range(14, 13 + $transportCount) : [],
            'lodging' => $lodgingCount > 0 ? range($lodgingStartRow, $lodgingStartRow + $lodgingCount - 1) : [],
            'other' => $otherCount > 0 ? range($otherStartRow, $otherStartRow + $otherCount - 1) : [],
        ];
        $sectionTotalRows = [
            'transport' => 14 + $transportCount,
            'lodging' => $lodgingStartRow + $lodgingCount,
            'other' => $otherStartRow + $otherCount,
        ];
        $allowanceRate = $this->bam($order->daily_allowance_rate_eur);
        $allowanceQuantity = $allowanceRate > 0 ? round($order->total_hours / 24, 2) : 0.0;
        $allowanceTotal = $this->bam($order->total_allowance_cost);
        $mileageTotal = $this->bam($order->total_km_cost);
        $listedExpensesTotal = $expenses
            ->sum(fn (array $expense): float => $this->bam((float) ($expense['amountInEur'] ?? 0)));
        $advance = $this->bam($order->advancement_paid);
        $expenseTotal = round($allowanceTotal + $mileageTotal + $listedExpensesTotal, 2);
        $balance = $this->bam($order->balance_to_pay);
        $replacements = [
            'C3' => mb_strtoupper($order->employee_name, 'UTF-8'),
            'E3' => "Broj službenog naloga:
{$order->order_number}",
            'C4' => $this->date($order->departure_time),
            'E4' => $order->departure_time->format('H:i'),
            'C5' => $this->date($order->arrival_time),
            'E5' => $order->arrival_time->format('H:i'),
            'C6' => "{$order->total_hours} SATI",
            'A9' => 1,
            'B9' => 'DNEVNICA',
            'C9' => $this->skulaDecimal($allowanceQuantity),
            'D9' => $this->skulaDecimal($allowanceRate),
            'E9' => $this->skulaAmount($allowanceTotal),
            'E12' => $this->skulaAmount($allowanceTotal),
            'E'.(35 + $rowDelta) => $this->skulaAmount($expenseTotal),
            'E'.(36 + $rowDelta) => $this->skulaAmount($advance),
            'E'.(37 + $rowDelta) => $this->skulaAmount(max($balance, 0)),
            'E'.(38 + $rowDelta) => $this->skulaAmount(max(-$balance, 0)),
            'C'.(40 + $rowDelta) => $this->date(now()),
            'B'.(45 + $rowDelta) => $order->employee_name,
            'C'.(45 + $rowDelta) => $order->approved_by,
        ];
        foreach ($replacements as $cell => $value) {
            $style = null;
            if (in_array($cell, ['C9', 'D9'], true)) {
                $style = 91;
            } elseif (str_starts_with($cell, 'E') && ! in_array($cell, ['E3', 'E4', 'E5'], true)) {
                $style = 90;
            }
            $xml = $this->replaceCell($xml, $cell, $value, $style);
        }
        $sequence = 1;
        foreach ($sectionRows as $section => $rows) {
            foreach ($rows as $index => $row) {
                $expense = $expenseGroups[$section][$index] ?? null;
                $amount = $expense ? $this->bam((float) ($expense['amountInEur'] ?? 0)) : null;
                $xml = $this->replaceCell($xml, "A{$row}", $expense ? $sequence++ : null);
                $xml = $this->replaceCell($xml, "B{$row}", $expense ? $this->expenseVendor($expense) : null, null, 9);
                $xml = $this->replaceCell($xml, "C{$row}", $expense ? 1 : null, 91);
                $xml = $this->replaceCell($xml, "D{$row}", $amount === null ? null : $this->skulaDecimal($amount), 91);
                $xml = $this->replaceCell($xml, "E{$row}", $this->skulaAmount($amount ?? 0), 90);
            }
            $sectionTotal = collect($expenseGroups[$section])
                ->sum(fn (array $expense): float => $this->bam((float) ($expense['amountInEur'] ?? 0)));
            $xml = $this->replaceCell($xml, 'E'.$sectionTotalRows[$section], $this->skulaAmount(round($sectionTotal, 2)), 90);
        }
        $xml = $this->prepareWorksheetLayout($xml, $this->companyLines($order), $headerStyles, $appLinkRelation, 48 + $rowDelta);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $this->updatePrintArea($zip, 48 + $rowDelta);
        $this->removeCalculationChain($zip);
        $zip->close();
        $content = file_get_contents($temporary);
        @unlink($temporary);
        throw_unless(is_string($content), new RuntimeException('SKULA export could not be read.'));

        return $content;
    }

    private function replaceCell(
        string $xml,
        string $cell,
        string|int|float|null $value,
        ?int $style = null,
        ?int $fontSize = null,
        bool $bold = false,
    ): string {
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
            $text = '<t xml:space="preserve">'.$this->xml((string) $value).'</t>';
            if ($fontSize !== null || $bold) {
                $runProperties = '<rPr>'.($bold ? '<b/>' : '').($fontSize !== null ? '<sz val="'.$fontSize.'"/>' : '').'<rFont val="Calibri"/><family val="2"/></rPr>';
                $content = '<is><r>'.$runProperties.$text.'</r></is>';
            } else {
                $content = '<is>'.$text.'</is>';
            }
            $type = ' t="inlineStr"';
        }

        return preg_replace($pattern, '<c r="'.$cell.'"'.$attributes.$type.'>'.$content.'</c>', $xml, 1) ?? $xml;
    }

    private function groupSkulaExpenses(array $expenses): array
    {
        $groups = ['transport' => [], 'lodging' => [], 'other' => []];
        $transportCategories = [
            'gorivo', 'rent-a-car', 'cestarina', 'parking', 'mostarina', 'tunelarina',
            'vinjeta', 'trajekt', 'avionska karta', 'autobuska karta', 'vozna karta', 'prijevozna karta',
        ];
        $lodgingCategories = ['smještaj', 'smjestaj', 'hotel', 'noćenje', 'nocenje'];

        foreach ($expenses as $expense) {
            if (! is_array($expense)) {
                continue;
            }
            $category = mb_strtolower(trim((string) ($expense['category'] ?? '')), 'UTF-8');
            $section = in_array($category, $lodgingCategories, true)
                ? 'lodging'
                : (in_array($category, $transportCategories, true) ? 'transport' : 'other');
            $groups[$section][] = $expense;
        }

        return $groups;
    }

    private function insertWorksheetRows(string $xml, int $beforeRow, int $count, int $templateRow): string
    {
        if ($count < 1) {
            return $xml;
        }

        $document = new DOMDocument;
        throw_unless($document->loadXML($xml), new RuntimeException('SKULA worksheet XML is invalid.'));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $template = $xpath->query("/x:worksheet/x:sheetData/x:row[@r='{$templateRow}']")->item(0);
        throw_unless($template instanceof DOMElement, new RuntimeException('SKULA expense row template is missing.'));
        $template = $template->cloneNode(true);

        foreach ($xpath->query('/x:worksheet/x:sheetData/x:row') as $row) {
            if (! $row instanceof DOMElement || (int) $row->getAttribute('r') < $beforeRow) {
                continue;
            }
            $newRow = (int) $row->getAttribute('r') + $count;
            $row->setAttribute('r', (string) $newRow);
            foreach ($xpath->query('x:c', $row) as $cell) {
                if ($cell instanceof DOMElement && preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $match)) {
                    $cell->setAttribute('r', $match[1].$newRow);
                }
            }
        }

        foreach ($xpath->query('/x:worksheet/x:mergeCells/x:mergeCell') as $merge) {
            if (! $merge instanceof DOMElement || ! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $merge->getAttribute('ref'), $match)) {
                continue;
            }
            $start = (int) $match[2];
            $end = (int) $match[4];
            if ($start >= $beforeRow) {
                $start += $count;
                $end += $count;
            } elseif ($end >= $beforeRow) {
                $end += $count;
            }
            $merge->setAttribute('ref', $match[1].$start.':'.$match[3].$end);
        }

        foreach ($xpath->query('/x:worksheet/x:rowBreaks/x:brk') as $break) {
            if ($break instanceof DOMElement && (int) $break->getAttribute('id') >= $beforeRow) {
                $break->setAttribute('id', (string) ((int) $break->getAttribute('id') + $count));
            }
        }

        $reference = $xpath->query('/x:worksheet/x:sheetData/x:row[@r="'.($beforeRow + $count).'"]')->item(0);
        throw_unless($reference instanceof DOMElement, new RuntimeException('SKULA row insertion point is missing.'));
        for ($index = 0; $index < $count; $index++) {
            $newRowNumber = $beforeRow + $index;
            $newRow = $template->cloneNode(true);
            $newRow->setAttribute('r', (string) $newRowNumber);
            foreach ($xpath->query('x:c', $newRow) as $cell) {
                if (! $cell instanceof DOMElement || ! preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $match)) {
                    continue;
                }
                $cell->setAttribute('r', $match[1].$newRowNumber);
                while ($cell->firstChild) {
                    $cell->removeChild($cell->firstChild);
                }
            }
            $reference->parentNode->insertBefore($newRow, $reference);
        }

        return $document->saveXML($document->documentElement) ?: $xml;
    }

    private function removeWorksheetRows(string $xml, int $startRow, int $count): string
    {
        if ($count < 1) {
            return $xml;
        }

        $document = new DOMDocument;
        throw_unless($document->loadXML($xml), new RuntimeException('SKULA worksheet XML is invalid.'));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $endRow = $startRow + $count - 1;
        $rows = [];
        foreach ($xpath->query('/x:worksheet/x:sheetData/x:row') as $row) {
            if ($row instanceof DOMElement) {
                $rows[] = $row;
            }
        }
        foreach ($rows as $row) {
            $number = (int) $row->getAttribute('r');
            if ($number >= $startRow && $number <= $endRow) {
                $row->parentNode->removeChild($row);

                continue;
            }
            if ($number <= $endRow) {
                continue;
            }
            $newRow = $number - $count;
            $row->setAttribute('r', (string) $newRow);
            foreach ($xpath->query('x:c', $row) as $cell) {
                if ($cell instanceof DOMElement && preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $match)) {
                    $cell->setAttribute('r', $match[1].$newRow);
                }
            }
        }

        $removedMerges = 0;
        $merges = [];
        foreach ($xpath->query('/x:worksheet/x:mergeCells/x:mergeCell') as $merge) {
            if ($merge instanceof DOMElement) {
                $merges[] = $merge;
            }
        }
        foreach ($merges as $merge) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $merge->getAttribute('ref'), $match)) {
                continue;
            }
            $start = (int) $match[2];
            $end = (int) $match[4];
            if ($start >= $startRow && $end <= $endRow) {
                $merge->parentNode->removeChild($merge);
                $removedMerges++;

                continue;
            }
            if ($start > $endRow) {
                $start -= $count;
                $end -= $count;
            } elseif ($end > $endRow) {
                $end -= $count;
            }
            $merge->setAttribute('ref', $match[1].$start.':'.$match[3].$end);
        }
        if ($removedMerges > 0) {
            $mergeCells = $xpath->query('/x:worksheet/x:mergeCells')->item(0);
            if ($mergeCells instanceof DOMElement) {
                $mergeCells->setAttribute('count', (string) ((int) $mergeCells->getAttribute('count') - $removedMerges));
            }
        }

        foreach ($xpath->query('/x:worksheet/x:rowBreaks/x:brk') as $break) {
            if ($break instanceof DOMElement && (int) $break->getAttribute('id') > $endRow) {
                $break->setAttribute('id', (string) ((int) $break->getAttribute('id') - $count));
            }
        }

        return $document->saveXML($document->documentElement) ?: $xml;
    }

    private function prepareWorksheetLayout(string $xml, array $companyLines, array $headerStyles, string $appLinkRelation, int $lastRow): string
    {
        $xml = preg_replace_callback('/<sheetView\b([^>]*)>/', function (array $match): string {
            $attributes = preg_match('/\sview="[^"]*"/', $match[1])
                ? preg_replace('/\sview="[^"]*"/', ' view="normal"', $match[1], 1)
                : $match[1].' view="normal"';

            return '<sheetView'.$attributes.'>';
        }, $xml, 1) ?? $xml;

        $xml = preg_replace('/<c r="F\d+"[^>]*?(?:\s*\/>|>.*?<\/c>)/s', '', $xml) ?? $xml;
        $xml = preg_replace(
            '/<col min="6" max="16384"([^>]*)\/>/',
            '<col min="6" max="16384" width="0" hidden="1" customWidth="1"/>',
            $xml,
            1,
        ) ?? $xml;
        $xml = preg_replace_callback('/<row r="(\d+)"/', fn (array $match): string => '<row r="'.((int) $match[1] + 3).'"', $xml) ?? $xml;
        $xml = preg_replace_callback('/<c r="([A-Z]+)(\d+)"/', fn (array $match): string => '<c r="'.$match[1].((int) $match[2] + 3).'"', $xml) ?? $xml;
        $xml = preg_replace_callback('/<mergeCell ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"\/>/', fn (array $match): string => '<mergeCell ref="'.$match[1].((int) $match[2] + 3).':'.$match[3].((int) $match[4] + 3).'"/>', $xml) ?? $xml;
        $xml = preg_replace('/<dimension ref="[^"]+"\/>/', '<dimension ref="A1:E'.$lastRow.'"/>', $xml, 1) ?? $xml;

        $appLines = ['Odrađeno sa aplikacijom', 'Putni nalozi - AI unos troška'];
        $companyRows = '';
        foreach (array_values(array_pad(array_slice($companyLines, 0, 3), 3, '')) as $index => $line) {
            $row = $index + 1;
            $companyRows .= '<row r="'.$row.'" spans="1:5" ht="12" customHeight="1"><c r="A'.$row.'" s="'.$headerStyles['company'].'" t="inlineStr"><is><r><rPr><b/><sz val="8"/><rFont val="Calibri"/><family val="2"/></rPr><t xml:space="preserve">'.$this->xml($line).'</t></r></is></c>';
            $appLine = $appLines[$index] ?? null;
            if ($appLine !== null) {
                $style = $index === count($appLines) - 1 ? $headerStyles['link'] : $headerStyles['note'];
                $companyRows .= '<c r="D'.$row.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($appLine).'</t></is></c><c r="E'.$row.'" s="'.$style.'"/>';
            }
            $companyRows .= '</row>';
        }
        $xml = str_replace('<sheetData>', '<sheetData>'.$companyRows, $xml);
        $xml = preg_replace_callback('/<mergeCells count="(\d+)">/', fn (array $match): string => '<mergeCells count="'.((int) $match[1] + 5).'"><mergeCell ref="A1:B1"/><mergeCell ref="A2:B2"/><mergeCell ref="A3:B3"/><mergeCell ref="D1:E1"/><mergeCell ref="D2:E2"/>', $xml, 1) ?? $xml;
        $xml = str_replace('<pageMargins', '<hyperlinks><hyperlink ref="D2:E2" r:id="'.$appLinkRelation.'"/></hyperlinks><pageMargins', $xml);
        $xml = $this->replaceCell($xml, 'A4', 'OBRAČUN TROŠKOVA SLUŽBENOG PUTOVANJA', 74);

        return $xml;
    }

    private function addHeaderStyles(ZipArchive $zip): array
    {
        $styles = $zip->getFromName('xl/styles.xml');
        throw_unless(is_string($styles), new RuntimeException('SKULA styles are missing.'));
        throw_unless(preg_match('/<cellXfs count="(\d+)">/', $styles, $match) === 1, new RuntimeException('SKULA cell styles are invalid.'));
        throw_unless(preg_match('/<fonts count="(\d+)"/', $styles, $fontMatch) === 1, new RuntimeException('SKULA fonts are invalid.'));

        $fontIndex = (int) $fontMatch[1];
        $styles = preg_replace('/<fonts count="\d+"/', '<fonts count="'.($fontIndex + 1).'"', $styles, 1) ?? $styles;
        $linkFont = '<font><u/><sz val="8"/><color rgb="FF0563C1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>';
        $styles = str_replace('</fonts>', $linkFont.'</fonts>', $styles);

        $styleIndex = (int) $match[1];
        $styles = preg_replace('/<cellXfs count="\d+">/', '<cellXfs count="'.($styleIndex + 3).'">', $styles, 1) ?? $styles;
        $headerStyles = '<xf numFmtId="0" fontId="10" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="10" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="'.$fontIndex.'" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>';
        $styles = str_replace('</cellXfs>', $headerStyles.'</cellXfs>', $styles);
        $zip->addFromString('xl/styles.xml', $styles);

        return ['company' => $styleIndex, 'note' => $styleIndex + 1, 'link' => $styleIndex + 2];
    }

    private function addAppLinkRelation(ZipArchive $zip): string
    {
        $path = 'xl/worksheets/_rels/sheet1.xml.rels';
        $relations = $zip->getFromName($path);
        throw_unless(is_string($relations), new RuntimeException('SKULA worksheet relations are missing.'));

        $identifier = 'rId'.(substr_count($relations, '<Relationship ') + 1);
        $relation = '<Relationship Id="'.$identifier.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://business.qla.dev/" TargetMode="External"/>';
        $zip->addFromString($path, str_replace('</Relationships>', $relation.'</Relationships>', $relations));

        return $identifier;
    }

    private function companyLines(TravelOrder $order): array
    {
        $company = null;
        if (trim((string) $order->company_oib) !== '') {
            $company = Company::query()->where('oib', $order->company_oib)->first();
        }
        if (! $company && trim((string) $order->company_name) !== '') {
            $company = Company::query()->where('name', $order->company_name)->first();
        }

        $name = trim((string) ($company?->name ?: $order->company_name));
        $address = trim(implode(', ', array_filter([
            trim((string) $company?->address),
            trim((string) $company?->city),
        ])));
        $country = trim((string) $company?->country);
        $details = $address !== '' ? $address : 'OIB: '.trim((string) $order->company_oib);

        return [$name, $details, $country];
    }

    private function updatePrintArea(ZipArchive $zip, int $lastRow): void
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if (! is_string($workbook)) {
            return;
        }

        $workbook = preg_replace_callback(
            '/\$A\$\d+:\$[A-Z]+\$\d+/',
            fn (): string => '$A$1:$E$'.$lastRow,
            $workbook,
            1,
        ) ?? $workbook;
        $zip->addFromString('xl/workbook.xml', $workbook);
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

    private function bam(float $value): float
    {
        return round($value * self::BAM_RATE, 2);
    }

    private function skulaDecimal(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function skulaAmount(float $value): string
    {
        return $this->skulaDecimal($value).' KM';
    }

    private function date(DateTimeInterface $date): string
    {
        return $date->format('d.m.Y');
    }
}
