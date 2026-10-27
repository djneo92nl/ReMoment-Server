import { invoke } from '@tauri-apps/api/core';
import { open } from '@tauri-apps/plugin-dialog';
import { initDisc } from './disc';
import './style.css';

type Uploaded = { path: string; track_id: number | null };

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;

function status(message = '', isError = false) {
  const el = $('status');
  el.textContent = message;
  el.className = isError ? 'error' : 'muted';
}

function show(connected: boolean, url = '') {
  $('connect').hidden = connected;
  $('home').hidden = !connected;
  $('server').textContent = url;
}

async function run<T>(busy: string, job: () => Promise<T>): Promise<T | undefined> {
  status(busy);
  try {
    const result = await job();
    status();
    return result;
  } catch (e) {
    status(String(e), true);
  }
}

$('connect-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const url = await run('Connecting…', () =>
    invoke<string>('connect', { url: $<HTMLInputElement>('url').value, password: $<HTMLInputElement>('password').value }),
  );
  if (url) {
    $<HTMLInputElement>('password').value = '';
    show(true, url);
  }
});

$('sign-out').addEventListener('click', async () => {
  await run('Signing out…', () => invoke('sign_out'));
  show(false);
});

$('pick').addEventListener('click', async () => {
  const path = await open({
    multiple: false,
    filters: [{ name: 'Audio', extensions: ['flac', 'mp3', 'm4a', 'ogg', 'opus', 'wav', 'aiff'] }],
  });
  if (typeof path !== 'string') return;

  const uploaded = await run('Uploading…', () => invoke<Uploaded>('upload_file', { path }));
  $('result').textContent = uploaded ? `Added to the library as ${uploaded.path}` : '';
});

// Ripping is the next milestone: for now a reviewed album only has to be well formed.
initDisc(run, (album) => console.log('album to rip', album));

(async () => {
  const url = await run('Checking sign-in…', () => invoke<string | null>('session_status'));
  show(!!url, url ?? '');
})();
