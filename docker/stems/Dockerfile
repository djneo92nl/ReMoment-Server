# Stems service: per-stem loudness curves and instrumental ranges of ONE track, on demand (docs/architecture/stems-service.md).
# Demucs htdemucs on the CPU, about 1.5 GB RAM, about 0.45x real time on 4 fast threads. Image about 1.6 GB.
# The model weights (84 MB) are NOT in the image or the repo: they download from Hugging Face at first use into /models.
# amd64 and arm64 (arm64 built and run; amd64 unverified).
#   docker build docker/stems
FROM python:3.12-slim

ARG TARGETARCH
ENV PYTHONUNBUFFERED=1 OMP_NUM_THREADS=4 HF_HOME=/models TORCH_HOME=/models XDG_CACHE_HOME=/models

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN if [ "$TARGETARCH" = "amd64" ]; then pip install --no-cache-dir torch==2.5.1 --index-url https://download.pytorch.org/whl/cpu; \
    else pip install --no-cache-dir torch==2.5.1; fi \
    && pip install --no-cache-dir -r requirements.txt \
    && pip install --no-cache-dir --no-deps demucs==4.1.0

COPY separate.py app.py ./

VOLUME /models
EXPOSE 8092

HEALTHCHECK --interval=30s --timeout=5s --start-period=120s CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8092/health').status == 200 else 1)"

# One worker: one request at a time, one copy of the RAM.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8092", "--workers", "1"]
