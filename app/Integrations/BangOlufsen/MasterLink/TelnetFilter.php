<?php

namespace App\Integrations\BangOlufsen\MasterLink;

/**
 * Strips telnet option negotiation from the bytes of a raw socket and refuses every option, so the
 * gateway's CLI can be read without a telnet library. A sequence split across two reads is kept.
 */
final class TelnetFilter
{
    private const IAC = 255;

    private const SE = 240;

    private const SB = 250;

    private const WILL = 251;

    private const WONT = 252;

    private const DO = 253;

    private const DONT = 254;

    private string $pending = '';

    /** @return array{0: string, 1: string} the text, and the bytes to send back */
    public function filter(string $bytes): array
    {
        $in = $this->pending.$bytes;
        $this->pending = '';
        $text = $reply = '';
        $length = strlen($in);

        for ($i = 0; $i < $length;) {
            if (ord($in[$i]) !== self::IAC) {
                $text .= $in[$i++];

                continue;
            }

            if ($i + 1 >= $length) {
                $this->pending = substr($in, $i);
                break;
            }

            $command = ord($in[$i + 1]);

            if ($command === self::IAC) {
                $text .= chr(self::IAC);
                $i += 2;
            } elseif ($command >= self::WILL && $command <= self::DONT) {
                if ($i + 2 >= $length) {
                    $this->pending = substr($in, $i);
                    break;
                }

                $option = $in[$i + 2];
                $answer = match ($command) {
                    self::DO => self::WONT,
                    self::WILL => self::DONT,
                    default => null,
                };

                if ($answer !== null) {
                    $reply .= chr(self::IAC).chr($answer).$option;
                }
                $i += 3;
            } elseif ($command === self::SB) {
                $end = strpos($in, chr(self::IAC).chr(self::SE), $i + 2);

                if ($end === false) {
                    $this->pending = substr($in, $i);
                    break;
                }
                $i = $end + 2;
            } else {
                $i += 2;
            }
        }

        return [$text, $reply];
    }
}
