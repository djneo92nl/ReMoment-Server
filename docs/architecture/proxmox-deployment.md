# Proxmox deployment (Ansible)

Runs ReMoment in an unprivileged LXC container (nesting on, so Docker works) on an existing Proxmox VE host, with a MiniDLNA server in the same container.

1. `ansible/inventory.yml`: set the `pve` host (root SSH) and `remoment_server` (the container's IP; with `proxmox_ct_ip: dhcp` the default, step 2 prints the address to fill in here: reserve it in your router). `ansible/group_vars/all.yml`: the `proxmox_*` and `dlna_*` variables.
2. `ansible-playbook -i ansible/inventory.yml ansible/proxmox.yml` — downloads the Debian template (`proxmox_template_pattern`, default Debian 13), creates the container, installs sshd/sudo and the deploy user + your public key. Skips an existing container id.
3. `ansible/setup.yml --ask-become-pass` — Docker, Avahi (`_remoment._tcp`) and MiniDLNA.
4. `ansible/deploy.yml --ask-vault-pass` — the app.

Skip step 2 to use any existing Debian VM/host instead.

## DLNA server

MiniDLNA runs on the container itself (not in Docker) so SSDP multicast works. It serves `dlna_media_dir` (`/srv/media`) as "`dlna_friendly_name`" on port `dlna_port` (8200). Media comes from `mp0`: either a dedicated volume (`proxmox_media_size` GB) or, with `proxmox_media_host_path`, a read-only bind mount of a PVE-host path (must be world-readable, since unprivileged containers map host uid 0 to 100000+). Copy music in, then add/scan the server at `/settings/dlna` (`php artisan library:scan`).

## Audio analysis sidecar

Set `analysis_enabled: true` in `ansible/group_vars/all.yml` and re-run `deploy.yml`. The `.env` template then sets `COMPOSE_PROFILES=analysis` (so `docker compose build/up` includes the Essentia `analysis` service on `127.0.0.1:8090`), `ANALYSIS_ENABLED`, `ANALYSIS_ESSENTIA_URL` and `ACOUSTID_API_KEY` (`acoustid_api_key`). The `analysis-queue` worker is already in `supervisord.prod.conf`. With `analysis_essentia_ml: true` the TensorFlow image is built and ~1 GB of models downloaded on first start into the `remoment-analysis-models` volume; give the container more RAM/CPU (`proxmox_ct_memory` 6144+) first. See `docs/architecture/audio-analysis.md`.
