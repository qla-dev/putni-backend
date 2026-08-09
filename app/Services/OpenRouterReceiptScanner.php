<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class OpenRouterReceiptScanner
{
    public function scan(array $images, string $documentType = 'receipt'): array
    {
        $isAirTicket = $documentType === 'air-ticket';
        $systemPrompt = $isAirTicket
            ? 'Očitavaš isključivo avionsku kartu ili boarding pass za putni nalog. Obavezno prepoznaj stvarno mjesto polaska u departureLocation i krajnje odredište u destinationLocation. Kod karte sa smjerom, grad lijevo ili prije strelice/aviona je polazak, a grad desno ili poslije strelice/aviona je odredište. Postavi category na Avionska karta. Očitaj aviokompaniju ako je prikazana; ako nije, vendor ostavi prazan. Za nepostojeće iznose, PDV, način plaćanja i stavke koristi prazne vrijednosti, nule ili prazan niz. Ne izmišljaj gradove. Vrati samo podatke iz zadane JSON sheme.'
            : 'Precizno očitaj račun za putni nalog. Ne izmišljaj nečitljive vrijednosti. departureLocation i destinationLocation ostavi prazne. Valutu odredi isključivo iz oznake ili simbola na dokumentu i vrati njen ISO 4217 kod. Ukupan iznos preračunaj u EUR u polje totalInEur; ako je dokument u EUR, totalInEur mora biti jednak polju total. Polja koja nisu prikazana vrati kao prazne stringove, nule ili prazne nizove. Vrati samo podatke koji odgovaraju zadanoj JSON shemi.';
        $userPrompt = $isAirTicket
            ? 'Očitaj polazak, krajnje odredište, aviokompaniju, datum leta, broj karte ili rezervacije, valutu i iznos ako su prikazani. Najvažnija polja su departureLocation i destinationLocation.'
            : 'Očitaj trgovca, datum, broj računa, kategoriju, valutu kao ISO 4217 kod, osnovicu, PDV, ukupan iznos u izvornoj valuti, ukupan iznos preračunat u EUR, način plaćanja i svaki pojedinačni artikal ili uslugu.';

        if ($documentType === 'transport-ticket') {
            $systemPrompt = 'Read a transport ticket for a travel order: plane, bus, or train. Extract the real departure city into departureLocation and final destination city into destinationLocation. Do not invent locations. Extract the carrier, travel date, ticket or booking number, currency, and amount where shown. Return only the requested JSON schema.';
            $userPrompt = 'Read this transport ticket. Departure and destination are the most important fields.';
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
        if ($response->successful()) return;

        throw ValidationException::withMessages([
            'images' => ['AI skeniranje trenutno nije dostupno. Pokušajte ponovo.'],
        ]);
    }

    private function outputText(array $payload): string
    {
        $content = data_get($payload, 'choices.0.message.content');
        if (is_string($content) && trim($content) !== '') return trim($content);
        if (is_array($content)) {
            $text = collect($content)->pluck('text')->filter()->implode("\n");
            if (trim($text) !== '') return trim($text);
        }

        throw new RuntimeException(data_get($payload, 'error.message', 'AI servis nije vratio rezultat skeniranja.'));
    }

    private function normalizeResult(array $result, string $documentType): array
    {
        $allowedCategories = ['Gorivo', 'Smještaj', 'Prehrana', 'Cestarina', 'Parking', 'Avionska karta', 'Ostalo'];
        $category = $documentType === 'air-ticket'
            ? 'Avionska karta'
            : $this->stringValue($result['category'] ?? '');
        if (! in_array($category, $allowedCategories, true)) $category = 'Ostalo';

        $currency = strtoupper($this->stringValue($result['currency'] ?? ''));
        if ($currency === '') $currency = 'EUR';

        $total = $this->numericValue($result['total'] ?? 0);
        $totalInEur = is_numeric($result['totalInEur'] ?? null)
            ? $this->numericValue($result['totalInEur'])
            : ($currency === 'EUR' ? $total : 0.0);

        $items = [];
        foreach ((is_array($result['items'] ?? null) ? $result['items'] : []) as $item) {
            if (! is_array($item)) continue;
            $items[] = [
                'name' => $this->stringValue($item['name'] ?? '', 'Stavka'),
                'quantity' => $this->numericValue($item['quantity'] ?? 0),
                'unitPrice' => $this->numericValue($item['unitPrice'] ?? 0),
                'total' => $this->numericValue($item['total'] ?? 0),
                'vatRate' => $this->numericValue($item['vatRate'] ?? 0),
            ];
        }

        $warnings = array_values(array_filter(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));

        $vendorFallback = $category === 'Avionska karta'
            ? 'Nepoznata aviokompanija'
            : 'Nepoznat trgovac';

        return [
            'vendor' => $this->stringValue($result['vendor'] ?? '', $vendorFallback),
            'date' => $this->stringValue($result['date'] ?? ''),
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
        ];
    }

    private function stringValue(mixed $value, string $fallback = ''): string
    {
        if (! is_string($value) && ! is_numeric($value)) return $fallback;
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : $fallback;
    }

    private function numericValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['vendor', 'date', 'receiptNumber', 'category', 'currency', 'subtotal', 'vat', 'total', 'totalInEur', 'paymentMethod', 'description', 'items', 'confidence', 'warnings', 'departureLocation', 'destinationLocation'],
            'properties' => [
                'vendor' => ['type' => 'string'],
                'date' => ['type' => 'string'],
                'receiptNumber' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => ['Gorivo', 'Smještaj', 'Prehrana', 'Cestarina', 'Parking', 'Avionska karta', 'Ostalo']],
                'currency' => ['type' => 'string'],
                'subtotal' => ['type' => 'number'],
                'vat' => ['type' => 'number'],
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
                            'unitPrice' => ['type' => 'number'],
                            'total' => ['type' => 'number'],
                            'vatRate' => ['type' => 'number'],
                        ],
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'departureLocation' => ['type' => 'string'],
                'destinationLocation' => ['type' => 'string'],
            ],
        ];
    }
}
