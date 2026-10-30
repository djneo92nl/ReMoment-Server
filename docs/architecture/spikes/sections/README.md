Throw-away scripts of the track-sections spike (16.2); see `../../track-sections-spike.md`. No audio is stored here.

    python -m venv venv && venv/bin/pip install -r requirements.txt
    # tracks.txt: id|artist|title|path relative to the music root (storage/app/music-test)
    venv/bin/python m_librosa.py "<file>" out/<id>.librosa.json            # A  (min 12 s, prominence 0.2)
    venv/bin/python m_librosa.py "<file>" out/<id>.librosa2.json 16 0.35   # A2 (coarser)
    docker cp m_essentia_sbic.py remomentserver-analysis-1:/tmp/ && docker exec remomentserver-analysis-1 python /tmp/m_essentia_sbic.py /tmp/<file> /tmp/o.json   # B
    venv/bin/python make_html.py <music root>                              # needs scripts/ cwd layout: run from a folder holding scripts/tracks.txt and out/
