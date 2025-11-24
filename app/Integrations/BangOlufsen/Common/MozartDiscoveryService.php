<?php

namespace App\Integrations\BangOlufsen\Common;

class MozartDiscoveryService
{
    protected string $group = '224.0.0.251';

    protected int $port = 5353;

    protected string $serviceType = '_beoremote._tcp.local';

    public function discover(int $timeoutSeconds = 10): array
    {
        if (! defined('IP_ADD_MEMBERSHIP')) {
            define('IP_ADD_MEMBERSHIP', 12);
        }
        if (! defined('IPPROTO_IP')) {
            define('IPPROTO_IP', 0);
        }

        $devices = [];

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_bind($socket, '0.0.0.0', 0); // allow ephemeral send port
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 255);
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_LOOP, 1);

        // Try joining multicast group (fallback quietly if not supported)
        $mreq = @inet_pton($this->group).pack('V', 0);
        @socket_set_option(
            $socket,
            IPPROTO_IP,
            IP_ADD_MEMBERSHIP,
            [
                'group' => $this->group,
                'interface' => '0.0.0.0',
            ]
        );
        $query = $this->buildMdnsQuery($this->serviceType);
        socket_sendto($socket, $query, strlen($query), 0, $this->group, $this->port);

        $timeout = time() + $timeoutSeconds;
        while (time() < $timeout) {
            $r = [$socket];
            $w = $e = [];
            if (socket_select($r, $w, $e, 1) > 0) {
                $buf = '';
                $from = '';
                $portOut = 0;
                socket_recvfrom($socket, $buf, 2048, 0, $from, $portOut);

                if (stripos($buf, 'Beo') !== false || stripos($buf, 'Bang') !== false) {
                    $found = $this->parseMdnsNames($buf);
                    $devices[$found[0]['ip']] = $found[0];

                }
            }
        }

        socket_close($socket);

        return array_values($devices);
    }

    protected function buildMdnsQuery(string $name): string
    {
        $parts = explode('.', $name);
        $query = "\x00\x00" // ID
            ."\x00\x00" // Flags
            ."\x00\x01" // Questions
            ."\x00\x00" // Answer RRs
            ."\x00\x00" // Authority RRs
            ."\x00\x00"; // Additional RRs
        foreach ($parts as $p) {
            $query .= chr(strlen($p)).$p;
        }
        $query .= "\x00"."\x00\x0c"."\x00\x01"; // Type PTR, Class IN

        return $query;
    }

    /**
     * Parse an mDNS/DNS response packet and return structured info.
     *
     * @param  string  $buf  Raw packet bytes
     * @return array [
     *               'instance' => string|null,
     *               'hostname' => string|null,
     *               'ip' => string|null,
     *               'info' => array (txt key=>value)
     *               ]
     */
    private function parseMdnsNames(string $buf): array
    {
        $len = strlen($buf);
        $offset = 0;

        // read header
        if ($len < 12) {
            return [];
        }

        $id = $this->readUint16($buf, $offset);
        $flags = $this->readUint16($buf, $offset);
        $qdcount = $this->readUint16($buf, $offset);
        $ancount = $this->readUint16($buf, $offset);
        $nscount = $this->readUint16($buf, $offset);
        $arcount = $this->readUint16($buf, $offset);

        // skip questions
        for ($i = 0; $i < $qdcount; $i++) {
            $this->readName($buf, $offset); // qname
            $offset += 4; // qtype(2) + qclass(2)
        }

        $instance = null;
        $hostname = null;
        $ip = null;
        $info = [];

        // helper to parse resource records (answers + additional)
        $totalRR = $ancount + $nscount + $arcount;
        for ($r = 0; $r < $totalRR && $offset < $len; $r++) {
            $rrname = $this->readName($buf, $offset);
            $type = $this->readUint16($buf, $offset);
            $class = $this->readUint16($buf, $offset);
            $ttl = $this->readUint32($buf, $offset);
            $rdlen = $this->readUint16($buf, $offset);

            if ($offset + $rdlen > $len) {
                break;
            } // malformed

            $rdata = substr($buf, $offset, $rdlen);

            // A record
            if ($type === 1 && $rdlen === 4) {
                $ip = implode('.', array_map('ord', str_split($rdata)));
            }

            // TXT record (type 16)
            if ($type === 16) {
                // TXT is a sequence of <len><text> strings
                $txtOffset = 0;
                while ($txtOffset < $rdlen) {
                    $tlen = ord($rdata[$txtOffset]);
                    $txtOffset++;
                    if ($tlen === 0) {
                        continue;
                    }
                    $txt = substr($rdata, $txtOffset, $tlen);
                    $txtOffset += $tlen;
                    // key=value or standalone
                    if (strpos($txt, '=') !== false) {
                        [$k, $v] = explode('=', $txt, 2);
                        $info[trim($k)] = trim($v);
                    } else {
                        // store as array entry if no '='
                        $info[] = $txt;
                    }
                }
            }

            // SRV record (type 33) => priority(2)+weight(2)+port(2)+target(name)
            if ($type === 33) {
                $srvOff = 0;
                $priority = ($this->ordAt($rdata, $srvOff) << 8) + $this->ordAt($rdata, $srvOff + 1);
                $srvOff += 2;
                $weight = ($this->ordAt($rdata, $srvOff) << 8) + $this->ordAt($rdata, $srvOff + 1);
                $srvOff += 2;
                $port = ($this->ordAt($rdata, $srvOff) << 8) + $this->ordAt($rdata, $srvOff + 1);
                $srvOff += 2;
                // target name starts at $srvOff relative to rdata => position in whole packet is $offset + $srvOff
                $offset1 = $offset + $srvOff;
                $target = $this->readName($buf, $offset1, true);
                // readName with $absolute=true returns name without advancing global offset
                $hostname = $target;
                // we already advanced offset by rdlen, so nothing more to do
            }

            // For service instance names, prefer a readable label from rrname or TXT name
            if (! $instance && $rrname && preg_match('/^[^\._]+/', $rrname, $m)) {
                $instance = $m[0];
            }

            $offset += $rdlen;
        }

        // fallback: if no hostname found, attempt to infer from instance
        if (! $hostname && $instance) {
            // normalize: replace spaces/non-alnum with '-', lower and append .local
            $hostname = strtolower(preg_replace('/[^a-z0-9\-]+/i', '-', $instance)).'.local';
        }

        return [[
            'instance' => $instance,
            'hostname' => $hostname,
            'ip' => $ip,
            'info' => $info,
        ]];
    }

    /* ---------- helper readers ---------- */

    private function ordAt(string $buf, int $pos): int
    {
        if ($pos < 0 || $pos >= strlen($buf)) {
            return 0;
        }

        return ord($buf[$pos]);
    }

    private function readUint16(string $buf, int &$offset): int
    {
        $v = ($this->ordAt($buf, $offset) << 8) + $this->ordAt($buf, $offset + 1);
        $offset += 2;

        return $v;
    }

    private function readUint32(string $buf, int &$offset): int
    {
        $v = ($this->ordAt($buf, $offset) << 24) + ($this->ordAt($buf, $offset + 1) << 16)
            + ($this->ordAt($buf, $offset + 2) << 8) + $this->ordAt($buf, $offset + 3);
        $offset += 4;

        return $v;
    }

    /**
     * Read a DNS name from $buf starting at $offset.
     * If $absolute === false then it returns name and advances $offset by consumed bytes.
     * If $absolute === true it returns the name but does NOT advance $offset (useful for SRV target inside rdata).
     */
    private function readName(string $buf, int &$offset, bool $absolute = false): string
    {
        $len = strlen($buf);
        $parts = [];
        $startOffset = $offset;
        $jumped = false;
        $maxLoops = 100; // safety
        $loops = 0;

        while ($offset < $len && $loops++ < $maxLoops) {
            $lenOctet = ord($buf[$offset]);

            // compression pointer (two MSB bits set)
            if (($lenOctet & 0xC0) === 0xC0) {
                $b2 = ord($buf[$offset + 1]);
                $pointer = (($lenOctet & 0x3F) << 8) | $b2;
                if (! $jumped) {
                    $offset += 2;
                    $jumped = true;
                } else {
                    // if already jumped, don't move offset further
                }
                // recursively read pointed name without changing original offset
                $ptr = $pointer;
                $parts[] = $this->readName($buf, $ptr, true);
                break;
            }

            if ($lenOctet === 0) {
                $offset += 1;
                break;
            }

            $offset++;
            $part = substr($buf, $offset, $lenOctet);
            $parts[] = $part;
            $offset += $lenOctet;
        }

        $name = implode('.', array_filter($parts, fn ($p) => $p !== ''));

        // if calling with absolute=true we must not advance the caller's offset
        if ($absolute) {
            return $name;
        }

        return $name;
    }
}
