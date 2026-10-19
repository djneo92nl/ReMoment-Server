<?php

namespace App\Integrations\Common;

use Illuminate\Support\Facades\Http;

/**
 * One UPnP service's control endpoint: calls an action over SOAP and answers its out-arguments as
 * `name => text`. Namespace-agnostic on the way back (renderers qualify the reply in different ways).
 * A UPnP fault becomes an exception: the "can't do that" codes an {@see UnsupportedOperationException}
 * (the API answers 422), every other one a plain RuntimeException (502).
 */
class UpnpSoap
{
    /** Invalid action, action failed, not implemented, transition / seek / format not available, content busy. */
    private const UNSUPPORTED_FAULTS = [401, 602, 701, 702, 703, 710, 711, 712, 714, 715, 716];

    public function __construct(
        private readonly string $controlUrl,
        private readonly string $serviceType,
        private readonly int $timeout = 5,
    ) {}

    /**
     * @param  array<string, scalar>  $arguments  in the order the action defines them
     * @return array<string, string>
     */
    public function call(string $action, array $arguments = []): array
    {
        $body = '';
        foreach ($arguments as $name => $value) {
            $body .= "<{$name}>".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</{$name}>";
        }

        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            ."<s:Body><u:{$action} xmlns:u=\"{$this->serviceType}\">{$body}</u:{$action}></s:Body></s:Envelope>";

        $response = Http::timeout($this->timeout)
            ->withHeaders(['SOAPAction' => "\"{$this->serviceType}#{$action}\""])
            ->withBody($envelope, 'text/xml; charset="utf-8"')
            ->post($this->controlUrl);

        // A fault comes with HTTP 500; its body says why.
        $reply = self::parse($response->body());

        if ($reply['fault'] !== null) {
            [$code, $message] = $reply['fault'];

            throw in_array($code, self::UNSUPPORTED_FAULTS, true)
                ? new UnsupportedOperationException("{$action}: {$message} ({$code})", $code)
                : new \RuntimeException("{$action}: {$message} ({$code})", $code);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("{$action}: HTTP {$response->status()}");
        }

        return $reply['out'];
    }

    /** @return array{out: array<string, string>, fault: ?array{0: int, 1: string}} */
    public static function parse(string $xml): array
    {
        $out = ['out' => [], 'fault' => null];
        $dom = new \DOMDocument;

        if ($xml === '' || !@$dom->loadXML($xml)) {
            return $out;
        }

        $envelopeBody = $dom->getElementsByTagNameNS('*', 'Body')->item(0);
        $first = null;
        foreach ($envelopeBody?->childNodes ?? [] as $node) {
            if ($node instanceof \DOMElement) {
                $first = $node;
                break;
            }
        }

        if ($first === null) {
            return $out;
        }

        if ($first->localName === 'Fault') {
            $code = $first->getElementsByTagNameNS('*', 'errorCode')->item(0)?->textContent;
            $message = $first->getElementsByTagNameNS('*', 'errorDescription')->item(0)?->textContent
                ?? $first->getElementsByTagName('faultstring')->item(0)?->textContent
                ?? 'fault';
            $out['fault'] = [(int) $code, trim($message)];

            return $out;
        }

        foreach ($first->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $out['out'][$node->localName] = $node->textContent;
            }
        }

        return $out;
    }
}
