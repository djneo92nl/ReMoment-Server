#!/bin/sh
# run.sh model id segment [mem] [chunk_s] ; reads scripts/tracks.txt, writes out/<id>.<model>.s<seg>[.c<chunk>].json
cd "$(dirname "$0")"
model=$1; id=$2; seg=$3; mem=${4:-3g}; chunk=${5:-0}
path=$(grep "^$id|" scripts/tracks.txt | cut -d'|' -f4)
suffix=s$seg; [ "$chunk" != "0" ] && suffix=$suffix.c$chunk
docker run --rm --memory=$mem --memory-swap=$mem -e HF_HOME=/hf -e XDG_CACHE_HOME=/hf -e TORCH_HOME=/hf \
  -v "$PWD/hfcache:/hf" -v "$PWD/scripts:/s:ro" -v "$PWD/out:/out" -v "${MUSIC_ROOT:?set MUSIC_ROOT}":/music:ro \
  stems-spike-demucs python -I /s/sep_demucs.py "$model" "/music/$path" "/out/$id.$model.$suffix.json" "$seg" "$chunk"
