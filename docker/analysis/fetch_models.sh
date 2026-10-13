#!/bin/sh
# Downloads the Essentia TensorFlow models the sidecar uses into $MODEL_DIR; files already there are kept.
# URLs follow https://essentia.upf.edu/models.html — check them against that page when a download fails
# (the sidecar then simply skips the model and /health lists what it has).
set -u
DIR="${MODEL_DIR:-/models}"
BASE="https://essentia.upf.edu/models"
mkdir -p "$DIR"

get() {
    name="$(basename "$1")"
    [ -f "$DIR/$name" ] && return 0
    echo "fetching $1"
    # Written under a temporary name and moved into place only when complete.
    if curl -fsSL --retry 3 -o "$DIR/$name.part" "$BASE/$1" && [ -s "$DIR/$name.part" ]; then
        mv "$DIR/$name.part" "$DIR/$name"
    else
        echo "could not fetch $1" >&2
    fi
}

# Embeddings
get feature-extractors/discogs-effnet/discogs-effnet-bs64-1.pb
get feature-extractors/discogs-effnet/discogs-effnet-bs64-1.json
get feature-extractors/musicnn/msd-musicnn-1.pb

# Classification heads on the Discogs-EffNet embeddings (each with its metadata json: class names)
for head in mood_happy mood_sad mood_aggressive mood_relaxed mood_party mood_acoustic mood_electronic danceability voice_instrumental genre_discogs400; do
    get "classification-heads/$head/$head-discogs-effnet-1.pb"
    get "classification-heads/$head/$head-discogs-effnet-1.json"
done

# Arousal / valence regression on MusiCNN embeddings
get classification-heads/deam/deam-msd-musicnn-2.pb
get classification-heads/deam/deam-msd-musicnn-2.json
