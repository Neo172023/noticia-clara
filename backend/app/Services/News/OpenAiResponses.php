<?php

namespace App\Services\News;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiResponses
{
    public function create(array $parameters): array
    {
        $key = config('services.openai.key');
        if (! is_string($key) || trim($key) === '') {
            throw new AnalysisException('El análisis está temporalmente deshabilitado porque falta configurar el servicio de OpenAI.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($key)
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(45)
                ->post('https://api.openai.com/v1/responses', $parameters + ['store' => false]);
        } catch (ConnectionException) {
            throw new AnalysisException('El servicio de análisis tardó demasiado o no está disponible. Volvé a intentarlo más tarde.');
        }

        $result = $response->json();
        if (! $response->successful() || ! is_array($result) || ($result['status'] ?? null) !== 'completed') {
            throw new AnalysisException('OpenAI no pudo completar el análisis. Volvé a intentarlo más tarde.');
        }

        return $result;
    }

    /** @return list<array> */
    public function textParts(array $response): array
    {
        $parts = [];
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }
}
