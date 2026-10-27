<?php

namespace App\Integrations\BangOlufsen\MasterLink;

/** Collects the bytes read from the MLGW socket and hands out complete frames; bytes before a SOH are dropped. */
final class MlgwFrameBuffer
{
    private string $buffer = '';

    /** @return MlgwFrame[] */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $frames = [];

        while ($this->buffer !== '') {
            if (ord($this->buffer[0]) !== MlgwFrame::SOH) {
                $next = strpos($this->buffer, chr(MlgwFrame::SOH));
                $this->buffer = $next === false ? '' : substr($this->buffer, $next);

                continue;
            }

            if (strlen($this->buffer) < 4) {
                break;
            }

            $length = ord($this->buffer[2]);

            if (strlen($this->buffer) < 4 + $length) {
                break;
            }

            $frames[] = new MlgwFrame(ord($this->buffer[1]), substr($this->buffer, 4, $length));
            $this->buffer = substr($this->buffer, 4 + $length);
        }

        return $frames;
    }
}
