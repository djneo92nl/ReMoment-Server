# Essentia analysis sidecar (see docs/architecture/audio-analysis.md).
#   docker build --build-arg ESSENTIA_ML=0 docker/analysis   # BPM, key, danceability (small)
#   docker build --build-arg ESSENTIA_ML=1 docker/analysis   # plus mood, arousal/valence, instrumental, genre (TensorFlow, large)
FROM python:3.11-slim

ARG ESSENTIA_ML=0
ENV ESSENTIA_ML=${ESSENTIA_ML} MODEL_DIR=/models PYTHONUNBUFFERED=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsndfile1 ffmpeg curl ca-certificates \
    && apt-get clean

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
