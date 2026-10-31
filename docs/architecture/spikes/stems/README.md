Throw-away scripts of the stems spike (16.4); see `../../track-stems-spike.md`. No audio is stored here, only loudness curves.

    # layout: a working folder holding scripts/ (the .py files and tracks.txt: id|artist|title|path), out/, hfcache/
    docker build -f Dockerfile.demucs -t stems-spike-demucs .        # python 3.12, torch 2.5.1 (CPU), demucs 4.1.0 --no-deps (sphn has no aarch64 wheel; unused)
    docker build -f Dockerfile.spleeter -t stems-spike-spleeter .    # python 3.11, tensorflow 2.12.1, spleeter 2.4.2
    MUSIC_ROOT=/path/to/music sh run.sh htdemucs <id> 7.8 3g 60      # model, track id, segment, RAM cap, window seconds (0 = whole track in memory)
    MUSIC_ROOT=/path/to/music sh run_spleeter.sh <id> 3g
    # master curves: docker run ... python -I /s/master.py <file> /out/<id>.master.json
    python3 -I scripts/make_html.py                                  # stdlib only: out/<id>.html + out/index.html
