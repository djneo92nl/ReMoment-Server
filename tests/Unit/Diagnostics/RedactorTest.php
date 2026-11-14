<?php

namespace Tests\Unit\Diagnostics;

use App\Domain\Diagnostics\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    /** @return array<string, array{string, string}> input, the secret that must be gone */
    public static function secrets(): array
    {
        return [
            'ipv4' => ['connect to 192.168.1.79:8080 failed', '192.168.1.79'],
            'ipv6' => ['host fe80::1ff:fe23:4567:890a refused', 'fe80::1ff:fe23:4567:890a'],
            'ipv6 full' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334 down', '2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
            'mac colon' => ['mac 00:1A:2B:3C:4D:5E seen', '00:1A:2B:3C:4D:5E'],
            'mac dash' => ['mac 00-1A-2B-3C-4D-5E seen', '00-1A-2B-3C-4D-5E'],
            'email' => ['user remko@example.com logged in', 'remko@example.com'],
            'bearer' => ['Authorization: Bearer abcdef123456SECRETvalue', 'abcdef123456SECRETvalue'],
            'rmt token' => ['token rmt_Zk39dkslQWE82jdkslaPPPq issued', 'rmt_Zk39dkslQWE82jdkslaPPPq'],
            'env style password' => ['DB_PASSWORD=hunter2hunter2', 'hunter2hunter2'],
            'quoted password' => ['password: "s3cr3t phrase"', 's3cr3t phrase'],
            'json password' => ['{"client_secret":"abc-very-secret","x":1}', 'abc-very-secret'],
            'query api key' => ['GET /x?api_key=AKIA1234567890&a=1', 'AKIA1234567890'],
            'last.fm session key' => ['lastfm_session_key = zzzTOPSECRETzzz', 'zzzTOPSECRETzzz'],
            'url credentials' => ['mysql://root:rootpw99@db/app', 'rootpw99'],
            'jwt' => ['jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.sig_abcdef', 'eyJhbGciOiJIUzI1NiJ9'],
            'long hex' => ['hash 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', '9f86d081884c7d659a2feaa0c55ad015'],
            'long base64' => ['key blob dGhpcy1pcy1hLXZlcnktbG9uZy1vcGFxdWUtdG9rZW4xMjM0NTY', 'dGhpcy1pcy1hLXZlcnktbG9uZy1vcGFxdWUtdG9rZW4xMjM0NTY'],
            'app key' => ['APP_KEY=base64:abcdefghijklmnopqrstuvwxyz0123456789ABCDEF=', 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEF'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_text_removes_the_secret(string $input, string $secret): void
    {
        $out = (new Redactor)->text($input);

        $this->assertStringNotContainsString($secret, $out);
    }

    public function test_known_literals_are_removed_wherever_they_appear_even_escaped(): void
    {
        $r = new Redactor(['pa/ss"word-literal']);

        $this->assertStringNotContainsString('pa/ss"word-literal', $r->text('x pa/ss"word-literal y'));
        $this->assertStringNotContainsString('pa\\/ss\\"word-literal', $r->text('{"a":'.json_encode('pa/ss"word-literal').'}'));
        $this->assertStringNotContainsString('pa%2Fss%22word-literal', $r->text('?q='.rawurlencode('pa/ss"word-literal')));
    }

    public function test_values_under_secret_keys_go_entirely_and_nesting_is_walked(): void
    {
        $out = (new Redactor)->value(['api_token' => 'tok-1', 'nested' => ['password' => 'pw', 'ok' => 'fine', 'ip' => '10.0.0.5'], 'n' => 5]);

        $this->assertSame(Redactor::MASK, $out['api_token']);
        $this->assertSame(Redactor::MASK, $out['nested']['password']);
        $this->assertSame('fine', $out['nested']['ok']);
        $this->assertSame('[ip]', $out['nested']['ip']);
        $this->assertSame(5, $out['n']);
    }

    public function test_ordinary_diagnostic_text_stays_readable(): void
    {
        $line = '[2026-11-14 10:00:01] local.ERROR: The device did not respond (design pinned, version 1.2.3) in 12ms';
        $out = (new Redactor)->text($line);

        $this->assertStringContainsString('did not respond', $out);
        $this->assertStringContainsString('design pinned', $out);
        $this->assertStringContainsString('1.2.3', $out);
    }
}
