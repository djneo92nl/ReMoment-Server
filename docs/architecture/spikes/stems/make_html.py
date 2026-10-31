"""Builds out/<id>.html per track plus out/index.html from out/<id>.<method>.json and out/<id>.master.json.
usage (from storage/app/spikes/stems): python3 -I scripts/make_html.py   -- stdlib only, no audio embedded."""
import glob, html, json, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__))))
from instrumental import detect, DROP, MIN_LEN, percentile

STEMS = ["vocals", "drums", "bass", "other"]
rows = [l.rstrip("\n").split("|") for l in open("scripts/tracks.txt")]


def mmss(t):
    return f"{int(t) // 60}:{int(t) % 60:02d}"


TEMPLATE = """<!doctype html><meta charset=utf-8><title>__TITLE__ stems</title>
<style>body{font:14px system-ui;margin:24px;max-width:1100px}.row{margin:18px 0}.lab{font-weight:600;margin-bottom:4px}
canvas{width:100%;display:block;border-radius:4px}table{border-collapse:collapse;font-size:12px;margin-top:6px}td,th{padding:2px 8px;border-bottom:1px solid #ddd;text-align:left}.s{color:#666}</style>
<h2>__TITLE__ <span class=s>(__DUR__)</span></h2>
<p><a href="index.html">all tracks</a> · Each lane is the loudness (RMS dB, 0.5 s) of one separated stem, scaled to that lane's own loudest 1% down 40 dB, so a quiet stem is still readable. Hatched bands = instrumental ranges (vocals lane at least __DROP__ dB under the track's own vocal level for at least __MINLEN__ s). Open the file in your player and jump to the times in the tables.</p><div id=a></div>
<script>const D=__DATA__,W=1100,mm=t=>Math.floor(t/60)+':'+String(Math.floor(t%60)).padStart(2,'0');
const COL={mix:'#334',vocals:'#c0525e',drums:'#e0883e',bass:'#4f86c4',other:'#5aa469'};
const a=document.getElementById('a');
function lane(x,y,h,curve,color,label,dur,inst){x.fillStyle='#f3f3f3';x.fillRect(0,y,W,h);
 const s=curve.slice().sort((p,q)=>p-q),top=s[Math.floor(s.length*.99)],lo=top-40;x.fillStyle=color;
 for(let i=0;i<curve.length;i++){const v=Math.max(0,Math.min(1,(curve[i]-lo)/40)),hh=v*(h-4);x.fillRect(i/curve.length*W,y+h-2-hh,W/curve.length+.4,hh)}
 x.fillStyle='#000';x.font='11px system-ui';x.fillText(label,6,y+12)}
D.methods.forEach(m=>{const d=document.createElement('div');d.className='row';
 const l=document.createElement('div');l.className='lab';l.textContent=m.name+' — '+m.wall+' s wall, '+m.rss+' MB peak, vocal ratio '+m.vocal_ratio+', '+m.instrumental.length+' instrumental range(s)';d.appendChild(l);
 const H=58,lanes=['mix'].concat(__STEMS__),c=document.createElement('canvas');c.width=W*2;c.height=(lanes.length*H+18)*2;c.style.height=(lanes.length*H+18)+'px';
 const x=c.getContext('2d');x.scale(2,2);
 lanes.forEach((k,i)=>lane(x,i*H,H-4,k=='mix'?D.mix:m.stems[k],COL[k],k=='mix'?'master (mix)':k,D.dur,m.instrumental));
 m.instrumental.forEach(r=>{const X=r[0]/D.dur*W,w=(r[1]-r[0])/D.dur*W;x.fillStyle='rgba(60,60,200,.13)';x.fillRect(X,0,w,lanes.length*H-4);x.strokeStyle='rgba(60,60,200,.7)';x.beginPath();x.moveTo(X,0);x.lineTo(X,lanes.length*H-4);x.moveTo(X+w,0);x.lineTo(X+w,lanes.length*H-4);x.stroke()});
 x.fillStyle='#555';x.font='10px system-ui';for(let t=0;t<D.dur;t+=30){const X=t/D.dur*W;x.fillRect(X,lanes.length*H-3,1,4);x.fillText(mm(t),X+2,lanes.length*H+11)}
 d.appendChild(c);
 const t=document.createElement('table');t.innerHTML='<tr><th>instrumental from</th><th>to</th><th>length</th></tr>'+(m.instrumental.map(r=>'<tr><td>'+mm(r[0])+'</td><td>'+mm(r[1])+'</td><td>'+Math.round(r[1]-r[0])+' s</td></tr>').join('')||'<tr><td colspan=3>none</td></tr>');
 d.appendChild(t);a.appendChild(d)});
</script>"""

idx = []
summary = []
for tid, artist, title, p in rows:
    mf = f"out/{tid}.master.json"
    if not os.path.exists(mf):
        continue
    master = json.load(open(mf))
    methods = []
    for f in sorted(glob.glob(f"out/{tid}.*.json")):
        base = os.path.basename(f)[len(tid) + 1:-5]
        if base in ("master", "htdemucs.s4", "htdemucs.s7.8.c10") or (base == "htdemucs.s7.8" and os.path.exists(f"out/{tid}.htdemucs.s7.8.c60.json")):
            continue
        r = json.load(open(f))
        ratio, inst, thr = detect(r["stems"]["vocals"])
        label = {"htdemucs.s7.8": "htdemucs (default)", "htdemucs.s4": "htdemucs, segment 4",
                 "htdemucs.s7.8.c60": "htdemucs (default, 60 s windows)", "htdemucs_ft.s7.8": "htdemucs_ft", "htdemucs_ft.s7.8.c60": "htdemucs_ft (60 s windows)", "spleeter": "Spleeter 4stems"}.get(base, base)
        methods.append({"name": label, "wall": r["wall_s"], "rss": r["peak_rss_mb"], "stems": {k: r["stems"][k] for k in STEMS}, "vocal_ratio": ratio, "instrumental": inst})
        summary.append((tid, label, r["duration"], r["wall_s"], r["peak_rss_mb"], ratio, inst))
    if not methods:
        continue
    data = {"dur": master["duration"], "mix": master["mix"], "methods": methods}
    page = (TEMPLATE.replace("__TITLE__", html.escape(f"{artist} – {title}")).replace("__DUR__", mmss(master["duration"]))
            .replace("__DROP__", str(DROP)).replace("__MINLEN__", str(MIN_LEN)).replace("__STEMS__", json.dumps(STEMS)).replace("__DATA__", json.dumps(data)))
    open(f"out/{tid}.html", "w").write(page)
    idx.append(f'<li><a href="{tid}.html">{html.escape(artist)} – {html.escape(title)}</a> <span style="color:#666">({mmss(master["duration"])}, {len(methods)} run(s))</span></li>')
open("out/index.html", "w").write("<!doctype html><meta charset=utf-8><title>Stems spike</title><body style='font:15px system-ui;margin:24px'><h2>Stems spike (16.4)</h2><ul>" + "".join(idx) + "</ul>")
for s in summary:
    print(s[0], s[1], f"dur {s[2]:.0f} wall {s[3]} rss {s[4]} ratio {s[5]} inst {[(mmss(a), mmss(b)) for a, b in s[6]]}")
