FROM debian:bookworm-slim
# python3 (not -minimal: it lacks the standard library the filler and beo need), ffmpeg for MP3 and resampling,
# git for pip to fetch beo. beo (djerik/beo, Apache-2.0) is pinned to one commit; bump BEO_REF deliberately.
ARG BEO_REF=47ca13b30b37c3b254bf00c988ea5c83b8818b58
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg python3 python3-pip python3-venv git ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/beo \
    && for p in packages/product packages/mozart packages/masterlink packages/networklink apps/beo-adapter; do \
         /opt/beo/bin/pip install --no-cache-dir "git+https://github.com/djerik/beo.git@${BEO_REF}#subdirectory=${p}"; \
       done \
    && apt-get purge -y git && apt-get autoremove -y
ENV PATH="/opt/beo/bin:${PATH}"
# Build context is ./docker, so the filler can be shared with the Spotify stream.
COPY beolink/run.sh beolink/receiver.py beolink/stream_playback.py librespot/pcm-filler.py /usr/local/bin/
RUN chmod +x /usr/local/bin/run.sh /usr/local/bin/receiver.py /usr/local/bin/pcm-filler.py
# beo imports stream_playback from the script's directory; python3 here is the venv's.
ENV PYTHONPATH=/usr/local/bin
ENTRYPOINT ["/usr/local/bin/run.sh"]
