# Proxmox deployment (Ansible)

Runs ReMoment in an unprivileged LXC container (nesting on, so Docker works) on an existing Proxmox VE host. ReMoment serves the music itself (`/stream`); there is no separate DLNA server.

1. `ansible/inventory.yml`: set the `pve` host (root SSH) and `remoment_server` (the container's IP; with `proxmox_ct_ip: dhcp` the default, step 2 prints the address to fill in here: reserve it in your router). `ansible/group_vars/all.yml`: the `proxmox_*` and `music_dir` variables.
2. `ansible-playbook -i ansible/inventory.yml ansible/proxmox.yml` — downloads the Debian template (`proxmox_template_pattern`, default Debian 13), creates the container, installs sshd/sudo and the deploy user + your public key. Skips an existing container id.
3. `ansible/setup.yml --ask-become-pass` — Docker, Avahi (`_remoment._tcp`) and the music folder (removes MiniDLNA if an older run installed it).
4. `ansible/deploy.yml --ask-vault-pass` — the app.

Skip step 2 to use any existing Debian VM/host instead.

## Music folders

Two folders are mounted into the container (`mp0`, `mp1`), and from there into the app container at the same paths (`MUSIC_DIR`, `LIBRARY_DIR` in `.env`, `docker-compose.prod.yml`):

- `music_dir` (`/srv/music`): the existing music, e.g. on a NAS. Read-only by default (`proxmox_music_readonly`), so scanning indexes it in place and never touches it; set it to `false` to let ReMoment move or reorganize those files.
- `library_dir` (`/srv/library`): an empty folder ReMoment stores its own files in (imports, uploads, the organized layout).

Each comes from a PVE host path (`proxmox_music_host_path`, `proxmox_library_host_path`) or, when empty, a dedicated volume (`proxmox_music_size`, `proxmox_library_size`). After deploying, add both as local storage locations at `/settings/storage` (`docs/architecture/file-storage.md`).

### NAS over NFS

Set `proxmox_music_nfs` / `proxmox_library_nfs` (e.g. `192.168.1.50:/volume1/music`). `proxmox.yml` installs `nfs-common` on the PVE host, mounts them under `proxmox_nfs_mount_base` (`/mnt/remoment/music`, `/mnt/remoment/library`, in the host's fstab) and bind-mounts those into the container; an unprivileged container can't mount NFS itself. To use a path that is already mounted on the host, set `proxmox_music_host_path` / `proxmox_library_host_path` instead.

On the NAS, export both folders to the **PVE host's IP**. Unprivileged containers shift uids by 100000, so the deploy user (uid 1000) is uid **101000** on the host. The library export must let that uid write: map every user to it, e.g. `/export/library 192.168.1.127(rw,sync,all_squash,anonuid=101000,anongid=101000,no_subtree_check)`. The music export only needs to be readable: `/export/music 192.168.1.127(ro,sync,all_squash,no_subtree_check)`.

## Audio analysis sidecar

Set `analysis_enabled: true` in `ansible/group_vars/all.yml` and re-run `deploy.yml`. The `.env` template then sets `COMPOSE_PROFILES=analysis` (so `docker compose build/up` includes the Essentia `analysis` service on `127.0.0.1:8090`), `ANALYSIS_ENABLED`, `ANALYSIS_ESSENTIA_URL` and `ACOUSTID_API_KEY` (`acoustid_api_key`). The `analysis-queue` worker is already in `supervisord.prod.conf`. With `analysis_essentia_ml: true` the TensorFlow image is built and ~1 GB of models downloaded on first start into the `remoment-analysis-models` volume; give the container more RAM/CPU (`proxmox_ct_memory` 6144+) first. See `docs/architecture/audio-analysis.md`.

## Database

`deploy.yml` starts a MariaDB container (`docker-compose.prod.yml`, data in the `remoment-mariadb` volume, published on `127.0.0.1:3306` only) and writes the `DB_*` settings into `.env` (`vault_db_password` in `vault.yml`, default `remoment`). An install that still has `database/database.sqlite` has its data copied in once, on the first deploy (`db:import-sqlite`, marker `storage/app/.sqlite-imported`); the streaming library data (DLNA, Spotify) is then dropped (`library:drop-streaming`) and comes back through the daily Spotify sync and a `php artisan library:scan`; the old file stays as a backup. Back up the `remoment-mariadb` volume (or `mariadb-dump`), it now holds the library, history, favorites and playlists. See `docs/architecture/database.md`.
