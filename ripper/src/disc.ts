import { invoke } from '@tauri-apps/api/core';

type Track = { position: number; title: string; artist: string; length_ms: number | null; mbid: string; recording_id: string };
type Release = {
  id: string; title: string; artist: string; date: string | null; country: string | null; label: string | null;
  disc_number: number; disc_count: number; cover_url: string | null; tracks: Track[];
};

/** What detect_disc found: the drive and where each track sits on the disc. */
export type Disc = {
  disc_id: string; track_count: number; device: string;
  tracks: { number: number; first_sector: number; sectors: number }[];
};

type LibraryAlbum = { id: number; name: string; artist: string; year: number | null; track_count: number | null };

/** Ripping needs the disc found with Detect disc, so the drive and track positions are known. */
const RIPPING_READY = true;

/** What the user is reviewing: the fields end up as tags, the unticked tracks are not ripped. */
export type Album = {
  albumArtist: string; album: string; year: string; discNumber: number; discCount: number;
  releaseId: string | null; coverUrl: string | null;
  /** The disc in the drive when it was detected here; without it there is nothing to rip. */
  disc: Disc | null;
  /** Albums of the library with this artist and title, found by name; ripping them again needs `ripAnyway`. */
  inLibrary: LibraryAlbum[]; ripAnyway: boolean;
  tracks: (Track & { include: boolean })[];
};

type Run = <T>(busy: string, job: () => Promise<T>) => Promise<T | undefined>;

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;

function el<T extends HTMLElement = HTMLElement>(tag: string, props: Record<string, unknown> = {}, ...kids: (Node | string)[]): T {
  const node = Object.assign(document.createElement(tag), props) as T;
  node.append(...kids);
  return node;
}

export function albumFromRelease(r: Release): Album {
  return {
    albumArtist: r.artist, album: r.title, year: r.date?.slice(0, 4) ?? '', discNumber: r.disc_number, discCount: r.disc_count,
    releaseId: r.id, coverUrl: r.cover_url, disc: null, inLibrary: [], ripAnyway: false, tracks: r.tracks.map((t) => ({ ...t, include: true })),
  };
}

export function blankAlbum(trackCount: number): Album {
  return {
    albumArtist: '', album: '', year: '', discNumber: 1, discCount: 1, releaseId: null, coverUrl: null, disc: null, inLibrary: [], ripAnyway: false,
    tracks: Array.from({ length: trackCount }, (_, i) => ({ position: i + 1, title: '', artist: '', length_ms: null, mbid: '', recording_id: '', include: true })),
  };
}

function minutes(ms: number | null) {
  if (ms === null) return '';
  const s = Math.round(ms / 1000);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function textInput(value: string, onInput: (v: string) => void, extra: Record<string, unknown> = {}) {
  const input = el<HTMLInputElement>('input', { type: 'text', value, ...extra });
  input.addEventListener('input', () => onInput(input.value));
  return input;
}

function field(label: string, value: string, onInput: (v: string) => void, onChange?: () => void) {
  const input = textInput(value, onInput);
  if (onChange) input.addEventListener('change', onChange);
  return el('label', {}, label, input);
}

function describe(a: LibraryAlbum) {
  const year = a.year ? ` (${a.year})` : '';
  const tracks = a.track_count === null ? '' : `, ${a.track_count} ${a.track_count === 1 ? 'track' : 'tracks'}`;
  return `${a.artist} – ${a.name}${year}${tracks}`;
}

/** Asks the server whether the reviewed album is already there and shows the answer in `note`. */
async function checkLibrary(album: Album, note: HTMLElement, refresh: () => void) {
  album.inLibrary = [];
  album.ripAnyway = false;
  refresh();
  if (!album.album.trim()) return note.replaceChildren();

  const asked = `${album.albumArtist}\u0000${album.album}`;
  note.replaceChildren(el('span', { className: 'muted' }, 'Checking your library…'));
  try {
    const found = await invoke<LibraryAlbum[]>('check_library', { albumArtist: album.albumArtist, album: album.album });
    if (asked !== `${album.albumArtist}\u0000${album.album}`) return; // edited meanwhile: a newer check is on its way
    album.inLibrary = found;
    refresh();
    if (found.length === 0) return note.replaceChildren();

    const anyway = el<HTMLButtonElement>('button', { type: 'button', textContent: 'Rip anyway' });
    anyway.addEventListener('click', () => {
      album.ripAnyway = true;
      refresh();
      note.replaceChildren(el('span', {}, 'Ripping anyway: a second copy will be added to the library.'));
    });
    note.replaceChildren(el('strong', {}, 'Already in your library: '), found.map(describe).join('; '), ' ', anyway);
  } catch (e) {
    note.replaceChildren(el('span', { className: 'error' }, `Could not check the library: ${e}`));
  }
}

/** The editable album: header fields, cover, and a table of tracks. Edits write straight into `album`. */
export function renderReview(album: Album, onNext: (album: Album) => void): HTMLElement {
  const root = el('div');
  const note = el('p', { className: 'library-note' });
  const next = el<HTMLButtonElement>('button', { type: 'button', textContent: 'Rip and add to library', disabled: true });
  const refresh = () => {
    next.disabled = !RIPPING_READY || !album.disc || (album.inLibrary.length > 0 && !album.ripAnyway);
    next.title = album.disc ? '' : 'Use Detect disc first: ripping needs the drive';
  };
  const recheck = () => void checkLibrary(album, note, refresh);
  root.append(note);

  const cover = album.coverUrl ? el('img', { src: album.coverUrl, alt: 'Cover' }) : el('div', { className: 'nocover' });
  const year = el('label', {}, 'Year', textInput(album.year, (v) => (album.year = v), { maxLength: 4 }));
  const discs = album.discCount > 1 ? el('p', { className: 'muted' }, `Disc ${album.discNumber} of ${album.discCount}`) : el('span');
  root.append(el('div', { className: 'review-head' }, cover, el('div', { className: 'fields' },
    field('Album artist', album.albumArtist, (v) => (album.albumArtist = v), recheck),
    field('Album', album.album, (v) => (album.album = v), recheck),
    el('div', { className: 'row' }, year), discs)));

  const body = el('tbody');
  for (const track of album.tracks) {
    const row = el('tr');
    const tick = el<HTMLInputElement>('input', { type: 'checkbox', checked: track.include, title: 'Rip this track' });
    tick.addEventListener('change', () => {
      track.include = tick.checked;
      row.className = tick.checked ? '' : 'off';
    });
    row.append(
      el('td', {}, tick),
      el('td', { className: 'num' }, String(track.position)),
      el('td', {}, textInput(track.title, (v) => (track.title = v))),
      el('td', {}, textInput(track.artist, (v) => (track.artist = v))),
      el('td', { className: 'time' }, minutes(track.length_ms)),
    );
    body.append(row);
  }
  root.append(el('table', {}, el('thead', {}, el('tr', {}, ...['', '#', 'Title', 'Artist', ''].map((h) => el('th', {}, h)))), body));

  next.addEventListener('click', () => onNext(album));
  root.append(el('p', {}, next));
  recheck();
  return root;
}

export function initDisc(run: Run, onNext: (album: Album, into: HTMLElement) => void) {
  let detected: Disc | null = null;

  const review = (album: Album) => {
    if (detected && detected.disc_id === $<HTMLInputElement>('disc-id').value.trim() && detected.track_count === album.tracks.length) {
      album.disc = detected;
    }
    $('choices').replaceChildren();
    $('review').hidden = false;
    $('review').replaceChildren(renderReview(album, (a) => onNext(a, $('review'))));
  };

  $('detect').addEventListener('click', async () => {
    const disc = await run('Reading the disc…', () => invoke<Disc>('detect_disc'));
    if (!disc) return;
    detected = disc;
    $<HTMLInputElement>('disc-id').value = disc.disc_id;
    $<HTMLFormElement>('lookup-form').requestSubmit();
  });

  $('lookup-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    $('review').hidden = true;
    $('choices').replaceChildren();

    const releases = await run('Looking the disc up on MusicBrainz…', () =>
      invoke<Release[]>('lookup_disc', { discId: $<HTMLInputElement>('disc-id').value }),
    );
    if (!releases) return;
    if (releases.length === 0) {
      $('choices').append(el('p', {}, 'MusicBrainz does not know this disc. Use "Enter by hand".'));
    } else if (releases.length === 1) {
      review(albumFromRelease(releases[0]));
    } else {
      // Several releases share a disc ID (other countries, reissues): the user picks one.
      $('choices').append(el('p', { className: 'muted' }, `${releases.length} releases match. Pick one:`));
      for (const r of releases) {
        const pick = el('button', { type: 'button', textContent: 'Use' });
        pick.addEventListener('click', () => review(albumFromRelease(r)));
        const detail = [r.date, r.country, r.label].filter(Boolean).join(' · ');
        $('choices').append(el('div', { className: 'choice' }, pick, el('span', {}, `${r.artist} – ${r.title}`, el('span', { className: 'muted' }, ` (${detail}, ${r.tracks.length} tracks)`))));
      }
    }
  });

  $('manual').addEventListener('click', () => {
    const count = Number(prompt('How many tracks are on the disc?', String(detected?.track_count ?? 12)));
    if (Number.isInteger(count) && count > 0 && count < 100) review(blankAlbum(count));
  });
}
