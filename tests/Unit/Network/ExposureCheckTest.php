<?php

namespace Tests\Unit\Network;

use App\Domain\Network\ExposureCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExposureCheckTest extends TestCase
{
    #[DataProvider('privateHosts')]
    public function test_private_hosts(string $host): void
    {
        $this->assertTrue(ExposureCheck::isPrivateHost($host), $host);
    }

    public static function privateHosts(): array
    {
        return array_map(fn ($h) => [$h], [
            '192.168.1.5', '10.0.0.2', '172.16.4.4', '172.31.255.1', '127.0.0.1', '169.254.1.1', '100.100.1.1',
            '[::1]', 'fd12:3456::1', 'fe80::1', '::ffff:192.168.0.9', 'remoment.local', 'remoment.lan', 'nas.home.arpa',
            'remoment', 'localhost', 'REMOMENT.LOCAL:8080', '192.168.1.5:80',
        ]);
    }

    #[DataProvider('publicHosts')]
    public function test_public_hosts(string $host): void
    {
        $this->assertFalse(ExposureCheck::isPrivateHost($host), $host);
    }

    public static function publicHosts(): array
    {
        return array_map(fn ($h) => [$h], [
            '8.8.8.8', '172.32.0.1', '203.0.113.7:80', '2001:4860:4860::8888', 'remoment.example.com', 'home.duckdns.org',
        ]);
    }

    public function test_request_assessment(): void
    {
        $this->assertSame([], ExposureCheck::assessRequest('192.168.1.5', null, null, '192.168.1.20', '192.168.1.5'));
        $this->assertSame([ExposureCheck::HOST_PUBLIC], ExposureCheck::assessRequest('remoment.example.com', null, null, '192.168.1.20'));
        // a proxy: forwarded host and original client win over what the proxy itself sent
        $this->assertSame([], ExposureCheck::assessRequest('127.0.0.1', 'remoment.lan', '192.168.1.40, 10.0.0.1', '127.0.0.1'));
        $this->assertSame([ExposureCheck::HOST_PUBLIC, ExposureCheck::CLIENT_PUBLIC], ExposureCheck::assessRequest('127.0.0.1', 'my.example.org', '198.51.100.9', '127.0.0.1'));
        $this->assertSame([ExposureCheck::CLIENT_PUBLIC], ExposureCheck::assessRequest('192.168.1.5', null, null, '198.51.100.9'));
        $this->assertSame([ExposureCheck::SERVER_PUBLIC], ExposureCheck::assessRequest('remoment', null, null, '192.168.1.2', '203.0.113.5'));
    }

    public function test_configured_url_and_scheme(): void
    {
        $this->assertSame([], ExposureCheck::assessConfigured('http://remoment.local'));
        $this->assertSame([], ExposureCheck::assessConfigured(''));
        $this->assertSame([ExposureCheck::APP_URL_PUBLIC], ExposureCheck::assessConfigured('https://remoment.example.com'));

        $this->assertTrue(ExposureCheck::isPlainHttp('http'));
        $this->assertFalse(ExposureCheck::isPlainHttp('http', 'https'));
        $this->assertTrue(ExposureCheck::isPlainHttp('https', 'http'));
    }
}
