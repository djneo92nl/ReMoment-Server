import { Channel, invoke } from '@tauri-apps/api/core';
import type { Album } from './disc';

type RipEvent = { event: 'progress'; percent: number } | { event: 'encoding' };
type Ripped = { path: string; problems: number };
type Uploaded = { path: string; track_id: number | null };

function el<T extends HTMLElement = HTMLElement>(tag: string, props: Record<string, unknown> = {}, ...kids: (Node | string)[]): T {
  const node = Object.assign(document.createElement(tag), props) as T;
  node.append(...kids);
  return node;
}

/**
 * Rips the ticked tracks of the detected disc one by one: rip to FLAC, tag with the cover, upload, delete.
 * A track that fails stops the run and can be retried; a file already ripped is not ripped again, and one
 * already uploaded is not uploaded again. The drive may drop off: that shows as a failed track.
 */
export function ripAlbum(album: Album, into: HTMLElement) {
  const disc = album.disc;
  if (!disc) return;

  into.querySelector('.rip')?.remove();
  const panel = el('div', { className: 'rip' });
  const summary = el('p');
  const retry = el<HTMLButtonElement>('button', { type: 'button', textContent: 'Retry', hidden: true });
  const tracks = album.tracks.filter((t) => t.include);
  const rows = new Map(tracks.map((t) => {
    const status = el('span', { className: 'muted' }, 'Waiting');
    const bar = el<HTMLProgressElement>('progress', { max: 100, value: 0, hidden: true });
    panel.append(el('div', { className: 'rip-row' }, el('span', { className: 'rip-title' }, `${t.position}. ${t.title}`), bar, status));
    return [t.position, { status, bar }];
  }));
  panel.append(summary, retry);
  into.append(panel);

  const ripped = new Map<number, Ripped>();
  const uploaded = new Set<number>();
  let problems = 0;

  async function one(position: number): Promise<void> {
    const track = tracks.find((t) => t.position === position)!;
    const { status, bar } = rows.get(position)!;
    const span = disc!.tracks.find((t) => t.number === position);
    if (!span) throw new Error(`Track ${position} is not on the disc.`);

    if (!ripped.has(position)) {
      bar.hidden = false;
      bar.value = 0;
      status.textContent = 'Ripping 0%';
      const channel = new Channel<RipEvent>();
      channel.onmessage = (e) => {
        if (e.event === 'progress') {
          bar.value = e.percent;
          status.textContent = `Ripping ${e.percent}%`;
        } else {
          status.textContent = 'Encoding…';
        }
      };
      ripped.set(position, await invoke<Ripped>('rip_track', {
        device: disc!.device, track: span.number, firstSector: span.first_sector, sectors: span.sectors, onEvent: channel,
      }));
    }
    const file = ripped.get(position)!;

    status.textContent = 'Tagging…';
    await invoke('tag_file', {
      request: {
        path: file.path, albumArtist: album.albumArtist, album: album.album, year: album.year,
        discNumber: album.discNumber, discCount: album.discCount, trackTotal: album.tracks.length,
        releaseId: album.releaseId, coverUrl: album.coverUrl, position, title: track.title, artist: track.artist,
        recordingId: track.recording_id || null, releaseTrackId: track.mbid || null,
      },
    });

    status.textContent = 'Uploading…';
    await invoke<Uploaded>('upload_file', { path: file.path });
    uploaded.add(position);
    await invoke('discard_ripped', { path: file.path });

    problems += file.problems;
    bar.hidden = true;
    status.className = '';
    status.textContent = file.problems > 0 ? `✓ Added (${file.problems} read warnings, listen to it)` : '✓ Added';
  }

  async function run() {
    retry.hidden = true;
    summary.textContent = '';
    for (const track of tracks) {
      if (uploaded.has(track.position)) continue;
      try {
        await one(track.position);
      } catch (e) {
        const { status } = rows.get(track.position)!;
        status.className = 'error';
        status.textContent = String(e);
        summary.textContent = 'Stopped. If the drive disconnected, reconnect it and retry; the tracks already added stay in the library.';
        retry.hidden = false;
        return;
      }
    }
    summary.textContent = `Done: ${uploaded.size} ${uploaded.size === 1 ? 'track' : 'tracks'} added to your library${problems ? `, ${problems} read warnings in total` : ''}.`;
    try {
      await invoke('eject_disc');
    } catch {
      /* the disc stays in; not worth an error after a finished rip */
    }
  }

  retry.addEventListener('click', run);
  void run();
}
