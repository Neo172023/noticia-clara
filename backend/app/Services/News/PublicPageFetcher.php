<?php

namespace App\Services\News;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class PublicPageFetcher
{
    private const MAX_BYTES = 2_000_000;

    private const MAX_REDIRECTS = 3;

    /** @return array{url: string, html: string, contentType: string} */
    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $uri = $this->publicUri($url);
            $host = $uri->getHost();
            $port = $uri->getScheme() === 'https' ? 443 : 80;
            $ip = $this->resolvePublicAddress($host);
            $url = (string) $uri;
            $body = '';
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'NoticiaClara/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        return 0;
                    }

                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            try {
                $ok = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
                $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            } finally {
                curl_close($curl);
            }

            if ($ok && in_array($status, [301, 302, 303, 307, 308], true) && $redirect && $hop < self::MAX_REDIRECTS) {
                $url = $this->redirectUrl($uri, $redirect);

                continue;
            }

            if ($ok && $status === 200 && preg_match('~^(text/html|application/xhtml\+xml)(?:\s*;|$)~i', $contentType)) {
                return ['url' => $url, 'html' => $body, 'contentType' => $contentType];
            }

            break;
        }

        throw new AnalysisException('No pudimos abrir la noticia. Es posible que el sitio restrinja el acceso, que el enlace haya caducado o que la página no sea un artículo HTML accesible.');
    }

    private function redirectUrl(Uri $from, string $location): string
    {
        $this->rejectAmbiguousUrl($location);
        try {
            return (string) UriResolver::resolve($from, new Uri($location));
        } catch (InvalidArgumentException) {
            throw $this->invalidUrl();
        }
    }

    private function publicUri(string $url): Uri
    {
        $this->rejectAmbiguousUrl($url);
        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            throw $this->invalidUrl();
        }

        $host = $uri->getHost();
        if (! preg_match('~\Ahttps?://[^/?#@]+(?:[/?#]|\z)~i', $url)
            || ! preg_match('~\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z~', $host)
            || preg_match('~\.(localhost|local|internal|invalid|test|example|onion|arpa)$~', $host)
            || $uri->getPort() !== null) {
            throw $this->invalidUrl();
        }

        return $uri->withFragment('');
    }

    private function rejectAmbiguousUrl(string $url): void
    {
        if (strlen($url) > 2048 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw $this->invalidUrl();
        }
    }

    private function resolvePublicAddress(string $host): string
    {
        // Query the absolute name to avoid falling back to local DNS search suffixes.
        $addresses = $this->resolve($host.'.');
        if ($addresses === []) {
            throw new AnalysisException('No pudimos localizar el dominio público de la noticia.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)
                || (int) $address >= 224) {
                throw $this->invalidUrl();
            }
        }

        return $addresses[0];
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }

    private function invalidUrl(): AnalysisException
    {
        return new AnalysisException('Ingresá un enlace HTTP o HTTPS a un sitio público, sin credenciales ni puertos especiales.');
    }
}
