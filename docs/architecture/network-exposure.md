# Network exposure warning (workstream 9b, network guardrails)

ReMoment is LAN-only and its API and MQTT have no authentication. The server **warns, never enforces**, when it looks reachable from the internet. Alert kind `network.exposed` (warning, key `''`), see [alerts.md](alerts.md).

## Detector

`App\Domain\Network\ExposureCheck` is pure (no DNS, no sockets). Private: RFC1918, loopback, link-local, IPv6 ULA/link-local, CGNAT `100.64/10` (Tailscale), names ending `.local .lan .home .home.arpa .internal .intranet .private .localdomain .localhost .test .corp`, and single-label names. Everything else is public.

`assessRequest(host, forwardedHost, forwardedFor, remoteAddr, serverAddr)` returns reason codes: `host_public` (X-Forwarded-Host, else Host), `client_public` (first X-Forwarded-For, else the connecting address; a port forward seen through NAT hairpin shows up here), `server_public` (the socket's own address). `assessConfigured(APP_URL)` gives `app_url_public`. Plain HTTP (`X-Forwarded-Proto` wins) only adds a sentence to the message.

Forwarded headers are read raw, since the app has no trusted-proxy setting; a LAN visitor can fake them, which only produces a warning.

## Raising and resolving

- `WarnWhenExposed` middleware (web and API groups) assesses each request (string work only). A public-looking one writes the cache (`exposure:request-reasons`, 1 h) and raises, at most once a minute (`exposure:request-throttle`); no database write per request.
- `AlertChecks::exposure()` (inside the every-minute `alerts:check`, already profile-gated) merges `APP_URL` with the cached reasons: raises or updates while any remain, resolves when none do (about an hour after the last public request).

Not covered: MQTT port 1883/9001 reached directly (only HTTP requests are seen), STREAM_BASE_URL, an active outside probe.
