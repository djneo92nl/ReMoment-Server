# Reverse proxy and remote access

## Read this first

ReMoment is built for a home network. **The REST API and MQTT have no authentication, by design.** Anyone who can reach the server can control your devices through `/api`, read what plays, and publish to or subscribe to MQTT (Mosquitto is configured with `allow_anonymous true`, `docker/mosquitto/config/mosquitto.conf`). Only `/settings/*` and a few setup actions need the one shared admin login. Nothing blocks access from outside your network. A visible warning when the server is reachable from a non-private address is planned for the setup wizard and `/settings/health` but not built yet; the rest is up to you.

So do not forward ports 80, 1883 or 9001 on your router. For remote access, the recommended way is a **VPN** such as WireGuard or Tailscale: you reach the server as if at home, and nothing is exposed. A reverse proxy with TLS is for people who want HTTPS inside the home network, a friendly name, or who accept the exposure above (adding authentication in the proxy, for example basic auth or an auth provider, is then your job and also blocks apps and wall panels that call the API).

> **The configurations below are untested.** They were written from the project's requirements (the Caddyfile and php.ini in `docker/frankenphp/`, the MQTT WebSocket on port 9001, `config/mqtt.php`), not run against a real install. Check each before relying on it.

## What a proxy must do

- Terminate TLS and proxy HTTP to the app on `127.0.0.1:APP_PORT` (default 80; if the proxy runs on the same host, change `APP_PORT` to something like 8080 so it can take 80 and 443).
- **Upgrade WebSockets** for the MQTT-over-WebSocket path to Mosquitto on `127.0.0.1:9001` (the browser's live updates). An HTTPS page cannot use a plain `ws://` broker, so give the browser a `wss://` URL: set `MQTT_WS_URL=wss://remoment.example.com/mqtt` in `.env` and restart. Whether Mosquitto accepts WebSocket connections on the path `/mqtt` as proxied is *unverified*; if not, strip or change the path in the proxy.
- Allow request bodies of **300 MB**: uploads at `/settings/storage` are single requests, and the app's limit is 300 MB (`max_size 300MB` in `docker/frankenphp/Caddyfile`, `upload_max_filesize` and `post_max_size` 300M in `docker/frankenphp/php.ini`).
- Pass the real client address (`X-Forwarded-For`, `X-Forwarded-Proto`, `Host`). **The application does not yet trust forwarded headers**: no trusted-proxy configuration exists in the repository, so Laravel will see the proxy's address and may treat HTTPS requests as plain HTTP. Whether this breaks anything visible (links, assets, redirects) is *unverified*; it needs a trusted-proxy setting in the app, which is not built.
- Keep MQTT TCP (1883) off the internet; the proxy only handles the WebSocket.
- Speakers fetch music from `STREAM_BASE_URL` (or `APP_URL`); keep that a plain LAN `http://` address, not the proxy's, since many speakers cannot do TLS.

## Caddy (untested)

```caddyfile
remoment.example.com {
    request_body {
        max_size 300MB
    }

    # MQTT over WebSocket (Mosquitto, port 9001). Caddy upgrades WebSockets automatically.
    handle /mqtt {
        reverse_proxy 127.0.0.1:9001
    }

    handle {
        reverse_proxy 127.0.0.1:8080 {
            # Caddy sends X-Forwarded-For and X-Forwarded-Proto by default.
            header_up Host {host}
        }
    }
}
```

For a name only used inside your network, Caddy can use its internal CA (`tls internal`) instead of a public certificate.

## nginx (untested)

```nginx
map $http_upgrade $connection_upgrade { default upgrade; '' close; }

server {
    listen 443 ssl;
    server_name remoment.example.com;
    ssl_certificate     /etc/ssl/remoment/fullchain.pem;
    ssl_certificate_key /etc/ssl/remoment/privkey.pem;

    client_max_body_size 300m;
    proxy_request_buffering off;          # let large uploads stream through

    location /mqtt {
        proxy_pass http://127.0.0.1:9001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 1h;            # idle WebSocket
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;          # the speaker playback calls can take a while
    }
}
```

Redirect port 80 to HTTPS as you prefer. The longer read timeout is because the app waits up to 30 s on some speakers (for example a TV opening its media player) before answering.
