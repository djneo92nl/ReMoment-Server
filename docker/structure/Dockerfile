# Structure service: sections of one track (docs/architecture/structure-service.md). Python 3.12+ and numpy 2 for
# librosa 1.0.0, hence not part of the Essentia sidecar. amd64 and arm64 (numba/llvmlite have aarch64 wheels).
#   docker build docker/structure
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 NUMBA_CACHE_DIR=/tmp/numba OMP_NUM_THREADS=2 OPENBLAS_NUM_THREADS=2

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY detect.py app.py ./

EXPOSE 8091

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8091/health').status == 200 else 1)"

# One worker: one request at a time, one copy of the RAM.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8091", "--workers", "1"]
