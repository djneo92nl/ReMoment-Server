# Master Link Gateway (MLGW / BLGW)

Status: **probe only**, no driver yet. The gateway is the IP door to a Master Link bus (BeoSound 4, Ouverture, BeoSound 3000/9000 and other Master Link masters). It is control and status only: audio leaves the master through its own outputs (a line-in on a modern speaker, or the NL/ML converter, see `ase-api-map.md`).

## Probe

```bash
php artisan mlgw:probe {gateway ip} [--user=admin] [--password=] [--seconds=120] [--no-http] [--no-api] [--no-bus]
```

Read-only. It logs in, asks for the serial number, answers keepalives and starts the bus log; it never sends a key, source or volume command. The password is asked for when `--password` is left out. Everything received is printed (decoded fields plus the raw hex) and appended to `storage/app/mlgw-probes/{ip}-{time}.jsonl`; passwords are never written, and keys that look like secrets in the gateway's config JSON are replaced.

Press keys on the master while it listens (source, preset, volume, standby, a CD track step) and keep the capture: it is what the driver's decoders get checked against.

Channels:

| Channel | Transport | What it gives |
|---|---|---|
| IP protocol | TCP 9000, `MlgwFrame` | login (`0x30` user NUL password), source status `0x02`, picture and sound status `0x03` (volume, mute), light/control `0x04`, all standby `0x05`, virtual buttons `0x20`, serial `0x3A` |
| Bus log | telnet 23, `TelnetFilter`, `BusTelegram` | the gateway's undocumented CLI: after the password and the `LGW >` prompt, `_MLLOG ONLINE` streams every telegram on the bus, including display text (`0x06`), extended info text (`0x0B`) and track/channel info |
| Web | HTTP, digest or basic auth | the config JSON (zones, devices); the path is a guess (`/mlgw/mlgwpservices.json`, `/mlgwpservices.json`), the probe lists what answers |

## Unverified

All layouts come from the Home Assistant plugin [giachello/mlgw](https://github.com/giachello/mlgw), not from a gateway of ours. Specifically unchecked: the serial request byte (`0x39`), the meaning of the login status byte, the telnet login (the plugin sends only the password), every offset in `BusTelegram`, and whether a BeoSound 4 reports track text at all. The plugin itself lists limits: a master that starts its own source (A.MEM on a BeoSound 3000) is not seen, and a video master with several active sources confuses it.

## Plan (after the probe)

A `MasterLinkGatewayDriver` with power, source activation, volume and media keys by Beo4 command, and a listener holding both connections (`DeviceListenerInterface`, one fork per gateway). Audio through a line-in: a device-meta link to the speaker whose line-in source is activated when the master plays. Not built until the capture shows what the master reports.
