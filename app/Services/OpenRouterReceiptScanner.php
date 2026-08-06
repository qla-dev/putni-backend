<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class OpenRouterReceiptScanner
{
    public function scan(array $images): array
    {
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
                        'name' => 'putni_nalozi_receipt',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Precizno očitaj račun ili avionsku kartu za putni nalog. Ne izmišljaj nečitljive vrijednosti. Za avionsku kartu vrati mjesto polaska u departureLocation i krajnje odredište u destinationLocation; za ostale račune vrati prazne stringove. Valutu odredi isključivo iz oznake ili simbola na dokumentu i vrati njen ISO 4217 kod. Ukupan iznos preračunaj u EUR u polje totalInEur; ako je dokument u EUR, totalInEur mora biti jednak polju total. Vrati samo podatke koji odgovaraju zadanoj JSON shemi.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Očitaj trgovca ili aviokompaniju, datum, broj računa ili karte, kategoriju, valutu kao ISO 4217 kod, osnovicu, PDV, ukupan iznos u izvornoj valuti, ukupan iznos preračunat u EUR, način plaćanja i svaki pojedinačni artikal ili uslugu. Ako je dokument avionska karta, očitaj i mjesto polaska te krajnje odredište.',
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

        Validator::make($result, $this->resultRules())->validate();

        return $result;
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) return;

        $message = $response->json('error.message') ?: 'AI skeniranje računa trenutno nije dostupno.';
        throw ValidationException::withMessages(['images' => [$message]]);
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

    private function resultRules(): array
    {
        return [
            'vendor' => ['required', 'string'],
            'date' => ['present', 'string'],
            'receiptNumber' => ['present', 'string'],
            'category' => ['required', 'in:Gorivo,Smještaj,Prehrana,Cestarina,Parking,Avionska karta,Ostalo'],
            'currency' => ['required', 'string'],
            'subtotal' => ['required', 'numeric'],
            'vat' => ['required', 'numeric'],
            'total' => ['required', 'numeric'],
            'totalInEur' => ['required', 'numeric'],
            'paymentMethod' => ['required', 'string'],
            'description' => ['required', 'string'],
            'items' => ['required', 'array'],
            'items.*.name' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric'],
            'items.*.unitPrice' => ['required', 'numeric'],
            'items.*.total' => ['required', 'numeric'],
            'items.*.vatRate' => ['required', 'numeric'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'warnings' => ['present', 'array'],
            'warnings.*' => ['string'],
            'departureLocation' => ['present', 'string'],
            'destinationLocation' => ['present', 'string'],
        ];
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
