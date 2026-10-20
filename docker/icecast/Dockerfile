FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends icecast2 gettext-base ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /etc/icecast2 /var/log/icecast2 \
    && chown -R icecast2:icecast /var/log/icecast2

COPY icecast.xml /etc/icecast2/icecast.xml.template
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER icecast2
EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
