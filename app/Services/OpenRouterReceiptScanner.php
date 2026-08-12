<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class OpenRouterReceiptScanner
{
    private const BAM_PER_EUR = 1.95583;

    public function scan(array $images, string $documentType = 'receipt'): array
    {
        $isAirTicket = $documentType === 'air-ticket';
        $systemPrompt = $isAirTicket
            ? 'Očitavaš isključivo avionsku kartu ili boarding pass za putni nalog. Obavezno prepoznaj stvarno mjesto polaska u departureLocation i krajnje odredište u destinationLocation, te države tih mjesta u departureCountry i destinationCountry. Kada je lokacija prikazana kao bilo koji IATA kod aerodroma, prepoznaj aerodrom i u polje lokacije vrati puni naziv pripadajućeg grada, nikada samo IATA kod. Koristi standardna engleska imena država (npr. Bosnia and Herzegovina, Croatia). Kod karte sa smjerom, grad lijevo ili prije strelice/aviona je polazak, a grad desno ili poslije strelice/aviona je odredište. Postavi category na Avionska karta. Očitaj datum leta u date kao YYYY-MM-DD. Očitaj datum i vrijeme polaska u departureDateTime te datum i vrijeme dolaska u arrivalDateTime, oba kao YYYY-MM-DDTHH:mm bez izmišljanja vremenske zone. Ako vrijeme ili cijela vrijednost nije čitljiva, vrati prazan string za to date-time polje. Očitaj aviokompaniju ako je prikazana; ako nije, vendor ostavi prazan. Polje items uvijek mora imati barem jednu stavku naziva Avionska karta, količine 1; ako cijena nije prikazana, unitPrice i total postavi na 0. Za ostale nepostojeće iznose, porez i način plaćanja koristi prazne vrijednosti ili nule. Ne izmišljaj gradove ni države. Vrati samo podatke iz zadane JSON sheme.'
            : 'Precizno očitaj račun ili kartu za putni nalog. Ne izmišljaj nečitljive vrijednosti. Datum računa ili putovanja je obavezan: očitaj stvarni datum s dokumenta i vrati ga isključivo u formatu YYYY-MM-DD. Ako datum nije čitljiv, vrati prazan string. Ako je dokument karta, odredi vrstu strogo prema dokazima na karti: Autobuska karta za autobus, coach, bus, peron/stajalište ili cestovnog prijevoznika; Vozna karta samo kada postoje jasni dokazi željeznice/voza, npr. train, railway, rail, kolodvor, wagon, carriage ili broj voza; Avionska karta samo za flight/boarding pass/airline. Ne označavaj autobusku kartu kao Vozna karta samo zato što sadrži polazak i odredište. Zatim očitaj stvarno mjesto polaska u departureLocation, krajnje odredište u destinationLocation, te njihove države u departureCountry i destinationCountry koristeći standardna engleska imena država. Za kartu očitaj i departureDateTime te arrivalDateTime kao YYYY-MM-DDTHH:mm kada su prikazani. Ne izmišljaj vrijeme ni vremensku zonu; nečitljive date-time vrijednosti vrati kao prazne stringove. Za običan račun sva polja rute i date-time polja ostavi prazna. Valutu odredi isključivo iz oznake ili simbola na dokumentu i vrati njen ISO 4217 kod. Ukupan iznos preračunaj u EUR u polje totalInEur; ako je dokument u EUR, totalInEur mora biti jednak polju total. Polja koja nisu prikazana vrati kao prazne stringove, nule ili prazne nizove. Vrati samo podatke koji odgovaraju zadanoj JSON shemi.';
        $userPrompt = $isAirTicket
            ? 'Očitaj polazak, državu polaska, krajnje odredište, državu odredišta, departureDateTime i arrivalDateTime u formatu YYYY-MM-DDTHH:mm, aviokompaniju, datum leta u formatu YYYY-MM-DD, broj karte ili rezervacije, valutu i iznos ako su prikazani. Najvažnija polja su departureLocation, departureCountry, destinationLocation, destinationCountry, departureDateTime i arrivalDateTime.'
            : 'Očitaj trgovca, datum računa ili putovanja u formatu YYYY-MM-DD, broj računa ili karte, kategoriju, valutu kao ISO 4217 kod, osnovicu, porez, ukupan iznos u izvornoj valuti, ukupan iznos preračunat u EUR, način plaćanja i svaki pojedinačni artikal ili uslugu. Porez može biti PDV/VAT, sales tax, GST ili druga vrsta poreza navedena na dokumentu. Ukupan iznos poreza vrati u polju vat, a stopu poreza svake stavke u vatRate, bez obzira na naziv vrste poreza na dokumentu. Ako je karta, očitaj i polazak, državu polaska, odredište, državu odredišta, departureDateTime i arrivalDateTime.';

        if (! $isAirTicket) {
            $systemPrompt .= ' Postavi category tačno na Rent-a-car kada dokument predstavlja najam vozila, car rental/car hire račun ili ugovor agencije za iznajmljivanje automobila. Ne koristi Rent-a-car samo zato što račun spominje automobil.';
            $userPrompt .= ' Prepoznaj Rent-a-car kada dokument jasno dokazuje uslugu najma vozila.';
        }

        if ($documentType === 'transport-ticket') {
            $systemPrompt = 'Read a transport ticket for a travel order: plane, bus, or train. Classify only from evidence printed on the ticket. Set category exactly to Autobuska karta for bus, coach, bus station/stop, or a road carrier; set Vozna karta only with explicit rail/train evidence such as train, railway, rail, station/kolodvor, wagon, carriage, or a train number; set Avionska karta only for flight, boarding pass, or airline evidence. Do not classify a bus ticket as Vozna karta merely because it shows a route. Use Prijevozna karta only when no transport type can be identified. Extract the real departure city into departureLocation and final destination city into destinationLocation. When any location is printed as an airport IATA code, identify that airport and return its full city name instead of the code. Also extract their countries into departureCountry and destinationCountry, using standard English country names (for example, Bosnia and Herzegovina, Croatia). Extract departureDateTime and arrivalDateTime as YYYY-MM-DDTHH:mm when printed on the ticket. Do not invent a time or timezone; return an empty string for a date-time that is not readable. Extract the travel date in YYYY-MM-DD format. Extract the carrier, ticket or booking number, currency, and amount where shown. The items array must always contain at least one ticket item with quantity 1. If no price is shown, use 0 for its unitPrice and total. Return only the requested JSON schema.';
            $userPrompt = 'Read this transport ticket. Departure, departure country, destination, destination country, departureDateTime, arrivalDateTime, and travel date are required when visible.';
        }

        $response = Http::withToken((string) config('services.openrouter.api_key'))
            ->acceptJson()
            ->timeout(90)
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'Putni Nalozi AI Scanner',
            ])
            ->post((string) config('services.openrouter.url'), [
                'model' => config('services.openrouter.model'),
                'temperature' => 0,
                'provider' => ['require_parameters' => true],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $isAirTicket ? 'putni_nalozi_air_ticket' : 'putni_nalozi_receipt',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $userPrompt,
                            ],
                            ...array_map(fn (array $image) => [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:'.($image['mimeType'] ?? 'image/jpeg').';base64,'.$image['base64'],
                                ],
                            ], $images),
                        ],
                    ],
                ],
            ]);

        $this->ensureSuccessful($response);

        try {
            $result = json_decode($this->outputText($response->json()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('AI servis je vratio neispravan rezultat skeniranja.');
        }

        return $this->normalizeResult(is_array($result) ? $result : [], $documentType);
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw ValidationException::withMessages([
            'images' => ['AI skeniranje trenutno nije dostupno. Pokušajte ponovo.'],
        ]);
    }

    private function outputText(array $payload): string
    {
        $content = data_get($payload, 'choices.0.message.content');
        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }
        if (is_array($content)) {
            $text = collect($content)->pluck('text')->filter()->implode("\n");
            if (trim($text) !== '') {
                return trim($text);
            }
        }

        throw new RuntimeException(data_get($payload, 'error.message', 'AI servis nije vratio rezultat skeniranja.'));
    }

    private function normalizeResult(array $result, string $documentType): array
    {
        $allowedCategories = ['Gorivo', 'Smještaj', 'Prehrana', 'Rent-a-car', 'Cestarina', 'Parking', 'Mostarina', 'Tunelarina', 'Vinjeta', 'Trajekt', 'Avionska karta', 'Autobuska karta', 'Vozna karta', 'Prijevozna karta', 'Ostalo'];
        $category = $documentType === 'air-ticket'
            ? 'Avionska karta'
            : $this->stringValue($result['category'] ?? '');
        if (! in_array($category, $allowedCategories, true)) {
            $category = 'Ostalo';
        }

        $currency = strtoupper($this->stringValue($result['currency'] ?? ''));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $total = $this->numericValue($result['total'] ?? 0);
        // OCR is responsible for reading the printed amount and currency, but
        // currency conversion must be deterministic. Never trust the model's
        // converted value for BAM receipts.
        $totalInEur = match ($currency) {
            'BAM' => $total / self::BAM_PER_EUR,
            'EUR' => $total,
            default => is_numeric($result['totalInEur'] ?? null)
                ? $this->numericValue($result['totalInEur'])
                : 0.0,
        };

        $items = [];
        foreach ((is_array($result['items'] ?? null) ? $result['items'] : []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = [
                'name' => $this->stringValue($item['name'] ?? '', 'Stavka'),
                'quantity' => $this->numericValue($item['quantity'] ?? 0),
                'unitPrice' => $this->numericValue($item['unitPrice'] ?? 0),
                'total' => $this->numericValue($item['total'] ?? 0),
                'vatRate' => $this->numericValue($item['vatRate'] ?? 0),
            ];
        }

        if ($items === [] && in_array($documentType, ['air-ticket', 'transport-ticket'], true)) {
            $items[] = [
                'name' => $category,
                'quantity' => 1.0,
                'unitPrice' => $total,
                'total' => $total,
                'vatRate' => 0.0,
            ];
        }

        $warnings = array_values(array_filter(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));

        $vendorFallback = $category === 'Avionska karta'
            ? 'Nepoznata aviokompanija'
            : 'Nepoznat trgovac';

        $date = $this->stringValue($result['date'] ?? '');
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($date === '' || ! $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            $date = now()->toDateString();
        }

        return [
            'vendor' => $this->stringValue($result['vendor'] ?? '', $vendorFallback),
            'date' => $date,
            'receiptNumber' => $this->stringValue($result['receiptNumber'] ?? ''),
            'category' => $category,
            'currency' => $currency,
            'subtotal' => $this->numericValue($result['subtotal'] ?? 0),
            'vat' => $this->numericValue($result['vat'] ?? 0),
            'total' => $total,
            'totalInEur' => $totalInEur,
            'paymentMethod' => $this->stringValue($result['paymentMethod'] ?? '', 'Nije navedeno'),
            'description' => $this->stringValue(
                $result['description'] ?? '',
                $category === 'Avionska karta' ? 'Avionska karta' : 'Račun',
            ),
            'items' => $items,
            'confidence' => max(0.0, min(1.0, $this->numericValue($result['confidence'] ?? 0))),
            'warnings' => $warnings,
            'departureLocation' => $this->stringValue($result['departureLocation'] ?? ''),
            'destinationLocation' => $this->stringValue($result['destinationLocation'] ?? ''),
            'departureCountry' => $this->stringValue($result['departureCountry'] ?? ''),
            'destinationCountry' => $this->stringValue($result['destinationCountry'] ?? ''),
            'departureDateTime' => $this->validLocalDateTime($result['departureDateTime'] ?? ''),
            'arrivalDateTime' => $this->validLocalDateTime($result['arrivalDateTime'] ?? ''),
        ];
    }

    private function stringValue(mixed $value, string $fallback = ''): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $fallback;
        }
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function numericValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function validLocalDateTime(mixed $value): string
    {
        $value = $this->stringValue($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return $parsed && $parsed->format('Y-m-d\TH:i') === $value ? $value : '';
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['vendor', 'date', 'receiptNumber', 'category', 'currency', 'subtotal', 'vat', 'total', 'totalInEur', 'paymentMethod', 'description', 'items', 'confidence', 'warnings', 'departureLocation', 'destinationLocation', 'departureCountry', 'destinationCountry', 'departureDateTime', 'arrivalDateTime'],
            'properties' => [
                'vendor' => ['type' => 'string'],
                'date' => ['type' => 'string'],
                'receiptNumber' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => ['Gorivo', 'Smještaj', 'Prehrana', 'Rent-a-car', 'Cestarina', 'Parking', 'Mostarina', 'Tunelarina', 'Vinjeta', 'Trajekt', 'Avionska karta', 'Autobuska karta', 'Vozna karta', 'Prijevozna karta', 'Ostalo']],
                'currency' => ['type' => 'string'],
                'subtotal' => ['type' => 'number', 'description' => 'Receipt amount before tax.'],
                'vat' => ['type' => 'number', 'description' => 'Total tax amount, including VAT, sales tax, GST, or another tax type shown on the document.'],
                'total' => ['type' => 'number'],
                'totalInEur' => ['type' => 'number'],
                'paymentMethod' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'quantity', 'unitPrice', 'total', 'vatRate'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unitPrice' => ['type' => 'number', 'description' => 'Unit price before tax.'],
                            'total' => ['type' => 'number', 'description' => 'Line total before tax.'],
                            'vatRate' => ['type' => 'number', 'description' => 'Tax percentage for this item, regardless of the tax type name.'],
                        ],
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'departureLocation' => ['type' => 'string'],
                'destinationLocation' => ['type' => 'string'],
                'departureCountry' => ['type' => 'string'],
                'destinationCountry' => ['type' => 'string'],
                'departureDateTime' => ['type' => 'string'],
                'arrivalDateTime' => ['type' => 'string'],
            ],
        ];
    }
}
