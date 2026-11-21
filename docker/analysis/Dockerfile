# Essentia analysis sidecar (see docs/architecture/audio-analysis.md).
#
# x86-64 ONLY. PyPI has Linux wheels of `essentia` and `essentia-tensorflow` for x86_64 and nothing else: no aarch64
# (Raspberry Pi 4/5, arm64 NAS or LXC), no armv7 (checked on pypi.org, 2026-11-21, every release up to 2.1b6.dev1438;
# `essentia` has no source archive newer than 2.1b6.dev234, `essentia-tensorflow` has none at all). Both builds below
# therefore fail to install on arm64, so the build stops early with a message instead of failing after a long pip run.
# On an Apple Silicon Mac the dev compose pins `platform: linux/amd64` (emulated). An arm64 host runs tier 0 analysis
# (ffmpeg, Chromaprint, quality, waveform) without this sidecar, or points ANALYSIS_ESSENTIA_URL at an x86-64 machine.
#   docker build --build-arg ESSENTIA_ML=0 docker/analysis   # BPM, key, danceability (small)
#   docker build --build-arg ESSENTIA_ML=1 docker/analysis   # plus mood, arousal/valence, instrumental, genre (TensorFlow, large)
FROM python:3.11-slim

ARG ESSENTIA_ML=0
ENV ESSENTIA_ML=${ESSENTIA_ML} MODEL_DIR=/models PYTHONUNBUFFERED=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsndfile1 ffmpeg curl ca-certificates \
    && apt-get clean

# Stop on anything but x86-64 (uname -m inside an emulated linux/amd64 build says x86_64, so that still works).
RUN arch="$(uname -m)" \
    && if [ "$arch" != "x86_64" ]; then \
        echo "ERROR: Essentia has no Linux wheel for $arch (x86_64 only); see docs/architecture/audio-analysis.md, 'Architectures'." >&2; \
        exit 1; \
    fi

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    && if [ "$ESSENTIA_ML" = "1" ]; then pip install --no-cache-dir essentia-tensorflow; else pip install --no-cache-dir essentia; fi

COPY app.py fetch_models.sh ./
RUN chmod +x fetch_models.sh

VOLUME /models
EXPOSE 8090

# The ML models are downloaded into the volume on first start (skipped when already there).
CMD ["sh", "-c", "if [ \"$ESSENTIA_ML\" = \"1\" ]; then ./fetch_models.sh; fi; exec uvicorn app:app --host 0.0.0.0 --port 8090"]
