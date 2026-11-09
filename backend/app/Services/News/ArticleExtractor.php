<?php

namespace App\Services\News;

use DOMDocument;
use DOMXPath;

class ArticleExtractor
{
    public function __construct(private PublicPageFetcher $fetcher) {}

    /** @return array{url: string, title: string, text: string} */
    public function extract(string $url): array
    {
        $page = $this->fetcher->fetch($url);
        $charset = preg_match('/charset\s*=\s*["\']?([\w-]+)/i', $page['contentType'], $match)
            ? $match[1] : null;
        $html = $page['html'];
        if ($charset === null && preg_match('/<meta\b[^>]*\bcharset\s*=\s*["\']?([\w-]+)/i', substr($html, 0, 8192), $match)) {
            $charset = $match[1];
        }

        try {
            $html = mb_convert_encoding($html, 'UTF-8', $charset ?? 'UTF-8');
        } catch (\ValueError) {
            throw $this->unreadable();
        }

        $document = new DOMDocument;
        if (trim($html) === '' || ! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw $this->unreadable();
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//nav|//footer|//aside|//form|//script|//style|//noscript|//template|//*[@hidden or @aria-hidden="true"]') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = '';
        foreach (['//meta[@property="og:title"]/@content', '//article//h1', '//main//h1', '//h1', '//title'] as $selector) {
            $title = $this->clean($xpath->query($selector)->item(0)?->textContent ?? '');
            if ($title !== '') {
                break;
            }
        }

        // Prefer the article body over the layout that contains it.
        foreach (['//*[@itemprop="articleBody"]', '//article', '//main|//*[@role="main"]'] as $selector) {
            $text = '';
            foreach ($xpath->query($selector) as $candidate) {
                $paragraphs = [];
                foreach ($xpath->query('.//p', $candidate) as $paragraph) {
                    $value = $this->clean($paragraph->textContent);
                    if (mb_strlen($value) >= 35) {
                        $paragraphs[] = $value;
                    }
                }
                $body = implode("\n\n", array_unique($paragraphs));
                if (mb_strlen($body) > mb_strlen($text)) {
                    $text = $body;
                }
            }

            if ($title !== '' && mb_strlen($text) >= 180) {
                return ['url' => $page['url'], 'title' => mb_substr($title, 0, 350), 'text' => mb_substr($text, 0, 7000)];
            }
        }

        throw $this->unreadable();
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function unreadable(): AnalysisException
    {
        return new AnalysisException('No encontramos suficiente texto de la noticia. Probá con un enlace directo a un artículo de acceso público.');
    }
}
