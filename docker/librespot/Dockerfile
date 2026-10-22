FROM rust:1-bookworm AS build
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev pkg-config && rm -rf /var/lib/apt/lists/*
# Pipe backend only (no sound card); libmdns answers Spotify's zeroconf discovery itself.
RUN cargo install librespot --locked --no-default-features --features "native-tls with-libmdns"

FROM debian:bookworm-slim
# python3, not python3-minimal: the minimal package lacks termios, fcntl, socket and most of the standard library the filler needs.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg python3 curl ca-certificates libssl3 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /usr/local/cargo/bin/librespot /usr/local/bin/librespot
COPY run.sh hook.sh pcm-filler.py track_spool.py /usr/local/bin/
RUN chmod +x /usr/local/bin/run.sh /usr/local/bin/hook.sh /usr/local/bin/pcm-filler.py
VOLUME /cache
ENTRYPOINT ["/usr/local/bin/run.sh"]
