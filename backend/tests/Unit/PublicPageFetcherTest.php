<?php

namespace Tests\Unit;

use App\Services\News\AnalysisException;
use App\Services\News\PublicPageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicPageFetcherTest extends TestCase
{
    #[DataProvider('blockedAddresses')]
    public function test_it_blocks_unsafe_addresses_before_connecting(string $url, array $addresses): void
    {
        $fetcher = $this->getMockBuilder(PublicPageFetcher::class)->onlyMethods(['resolve', 'download'])->getMock();
        $fetcher->method('resolve')->willReturn($addresses);
        $fetcher->expects($this->never())->method('download');
        $this->expectException(AnalysisException::class);

        $fetcher->fetch($url);
    }

    public static function blockedAddresses(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/', []],
            'integer IPv4' => ['http://2130706433/', []],
            'hexadecimal IPv4' => ['http://0x7f000001/', []],
            'IPv6 mapped' => ['http://[::ffff:127.0.0.1]/', []],
            'local name' => ['http://servicio.internal/', []],
            'credentials' => ['https://usuario:clave@medio.com.ar/', []],
            'nonstandard port' => ['https://medio.com.ar:8080/', []],
            'unvalidated scheme' => ['file:///etc/hosts', []],
            'ambiguous path' => ['https://medio.com.ar\\@localhost/', []],
            'mixed DNS' => ['https://medio.com.ar/', ['8.8.8.8', '10.1.0.2']],
            'link local DNS' => ['https://medio.com.ar/', ['169.254.169.254']],
            'carrier-grade NAT DNS' => ['https://medio.com.ar/', ['100.64.0.1']],
            'multicast DNS' => ['https://medio.com.ar/', ['224.0.0.1']],
        ];
    }

    public function test_it_pins_public_dns_and_rechecks_it_on_a_relative_redirect(): void
    {
        $fetcher = $this->getMockBuilder(PublicPageFetcher::class)->onlyMethods(['resolve', 'download'])->getMock();
        $fetcher->expects($this->exactly(2))->method('resolve')->with('medio.com.ar.')
            ->willReturnOnConsecutiveCalls(['8.8.8.8'], ['9.9.9.9']);
        $fetcher->expects($this->exactly(2))->method('download')->willReturnMap([
            ['https://medio.com.ar/nota', 'medio.com.ar:443:8.8.8.8', ['status' => 302, 'redirect' => '/noticias/nota']],
            ['https://medio.com.ar/noticias/nota', 'medio.com.ar:443:9.9.9.9', ['status' => 200, 'contentType' => 'text/html', 'body' => '<article></article>']],
        ]);

        $this->assertSame('https://medio.com.ar/noticias/nota', $fetcher->fetch('https://medio.com.ar/nota#inicio')['url']);
    }

    public function test_it_rejects_a_redirect_to_a_private_dns_address(): void
    {
        $fetcher = $this->getMockBuilder(PublicPageFetcher::class)->onlyMethods(['resolve', 'download'])->getMock();
        $fetcher->expects($this->exactly(2))->method('resolve')->willReturnMap([
            ['medio.com.ar.', ['8.8.8.8']],
            ['redirect.com.ar.', ['127.0.0.1']],
        ]);
        $fetcher->expects($this->once())->method('download')->willReturn([
            'status' => 302, 'redirect' => '//redirect.com.ar/privado',
        ]);
        $this->expectException(AnalysisException::class);

        $fetcher->fetch('https://medio.com.ar/noticia');
    }
}
