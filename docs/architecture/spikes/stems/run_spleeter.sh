#!/bin/sh
# run_spleeter.sh id [mem] ; writes out/<id>.spleeter.json
cd "$(dirname "$0")"
id=$1; mem=${2:-3g}
path=$(grep "^$id|" scripts/tracks.txt | cut -d'|' -f4)
docker run --rm --memory=$mem --memory-swap=$mem -e MODEL_PATH=/hf/spleeter \
  -v "$PWD/hfcache:/hf" -v "$PWD/scripts:/s:ro" -v "$PWD/out:/out" -v "${MUSIC_ROOT:?set MUSIC_ROOT}":/music:ro \
  stems-spike-spleeter python -I /s/sep_spleeter.py "/music/$path" "/out/$id.spleeter.json" 30
echo "spleeter $id exit $?"
