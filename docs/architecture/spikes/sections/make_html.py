"""Builds out/<id>.html per track plus out/index.html. usage: make_html.py music_root"""
import sys, json, glob, os, html
import numpy as np, librosa
root = sys.argv[1]
METHODS = [("librosa", "A · librosa novelty (MFCC+chroma, beat-synced)", ".librosa.json"),
           ("librosa2", "A2 · librosa novelty, coarser (min 16 s, prominence 0.35)", ".librosa2.json"),
           ("sbic", "B · Essentia SBic", ".sbic.json"),
           ("allin1", "C · All-In-One (structure model)", ".allin1.json")]
PAL = ["#4f86c4", "#e0883e", "#5aa469", "#c0525e", "#8a6bc0", "#b8a23c", "#3fa7a7", "#a8627f", "#7a8b9c", "#c46f2d"]
def mmss(t): return f"{int(t)//60}:{int(t)%60:02d}"
rows = [l.rstrip("\n").split("|") for l in open("scripts/tracks.txt")]
idx = []
for tid, artist, title, p in rows:
    y, sr = librosa.load(os.path.join(root, p), sr=8000, mono=True)
    dur = len(y) / sr; n = 900; b = len(y) // n
    rms = np.array([np.sqrt(np.mean(y[i*b:(i+1)*b] ** 2)) for i in range(n)])
    db = 20 * np.log10(rms + 1e-6); lo, hi = np.percentile(db, 5), np.percentile(db, 99)
    wf = np.clip((db - lo) / (hi - lo + 1e-6), 0.03, 1).round(3).tolist()
    data = {"dur": dur, "wf": wf, "methods": []}
    for key, name, suf in METHODS:
        f = f"out/{tid}{suf}"
        if os.path.exists(f):
            r = json.load(open(f)); r.pop("novelty", None); r.pop("novelty_t", None)
            data["methods"].append({"name": name, "wall": r.get("wall_s"), "rss": r.get("peak_rss_mb"), "note": r.get("note"), "sections": r["sections"]})
    page = f"""<!doctype html><meta charset=utf-8><title>{html.escape(title)} sections</title>
<style>body{{font:14px system-ui;margin:24px;max-width:1100px}}.row{{margin:14px 0}}.lab{{font-weight:600;margin-bottom:4px}}
canvas{{width:100%;display:block;border-radius:4px}}table{{border-collapse:collapse;font-size:12px;margin-top:6px}}td,th{{padding:2px 8px;border-bottom:1px solid #ddd;text-align:left}}.s{{color:#666}}</style>
<h2>{html.escape(artist)} – {html.escape(title)} <span class=s>({mmss(dur)})</span></h2><p><a href="index.html">all tracks</a> · open the file in your player and jump to the times below. Waveform: RMS, per-track contrast.</p><div id=a></div>
<script>const D={json.dumps(data)},PAL={json.dumps(PAL)};const W=1100,mm=t=>Math.floor(t/60)+':'+String(Math.floor(t%60)).padStart(2,'0');
const a=document.getElementById('a');
function cv(h){{const c=document.createElement('canvas');c.width=W*2;c.height=h*2;c.style.height=h+'px';return c}}
D.methods.forEach(m=>{{const d=document.createElement('div');d.className='row';
 const l=document.createElement('div');l.className='lab';l.textContent=m.name+' — '+m.sections.length+' sections'+(m.wall!=null?', '+m.wall+' s, '+m.rss+' MB':'')+(m.note?' ('+m.note+')':'');d.appendChild(l);
 const c=cv(70),x=c.getContext('2d');x.scale(2,2);x.fillStyle='#f3f3f3';x.fillRect(0,0,W,70);
 m.sections.forEach((s,i)=>{{const lab=s.label||String(i+1);const k=s.label&&s.label.length==1?s.label.charCodeAt(0)-65:i;x.fillStyle=PAL[((k%PAL.length)+PAL.length)%PAL.length]+'cc';
  const X=s.start/D.dur*W,w=(s.end-s.start)/D.dur*W;x.fillRect(X+1,0,w-1,26);x.fillStyle='#fff';x.font='11px system-ui';x.fillText(lab,X+4,17);
  x.fillStyle='#334';const wf=D.wf,n=wf.length;for(let j=Math.floor(s.start/D.dur*n);j<Math.floor(s.end/D.dur*n);j++){{const h=wf[j]*38;x.fillRect(j/n*W,68-h,W/n+.3,h)}}}});
 x.strokeStyle='#000';x.lineWidth=1;m.sections.forEach(s=>{{const X=s.start/D.dur*W;x.beginPath();x.moveTo(X,0);x.lineTo(X,70);x.stroke()}});
 d.appendChild(c);
 const t=document.createElement('table');t.innerHTML='<tr><th>#</th><th>start</th><th>end</th><th>label</th><th>conf</th></tr>'+m.sections.map((s,i)=>'<tr><td>'+(i+1)+'</td><td>'+mm(s.start)+'</td><td>'+mm(s.end)+'</td><td>'+(s.label||'')+'</td><td>'+(s.confidence??'')+'</td></tr>').join('');
 const det=document.createElement('details');det.innerHTML='<summary>timestamps</summary>';det.appendChild(t);d.appendChild(det);a.appendChild(d)}});
</script>"""
    open(f"out/{tid}.html", "w").write(page)
    idx.append(f'<li><a href="{tid}.html">{html.escape(artist)} – {html.escape(title)}</a> <span style="color:#666">({mmss(dur)})</span></li>')
open("out/index.html", "w").write("<!doctype html><meta charset=utf-8><title>Sections spike</title><body style='font:15px system-ui;margin:24px'><h2>Track sections spike (16.2)</h2><ul>" + "".join(idx) + "</ul>")
print("ok")
