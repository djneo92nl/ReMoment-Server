# Install with Ansible (Proxmox or any Debian host)

For people who run Proxmox VE or a Debian server and have Ansible on their own computer. Three playbooks in `ansible/` create a container, prepare the host and deploy the app. The deploy builds the Docker images on the target ([install-docker.md](install-docker.md) explains the stack it runs). Background: [proxmox-deployment.md](../architecture/proxmox-deployment.md).

> **Not available yet:** published images; the deploy builds from source. The playbooks were written for the author's own Proxmox host; other setups are not tested.

## What you need

- Ansible on your computer, SSH access as root to the Proxmox host (for `proxmox.yml`), your SSH public key.
- Or skip `proxmox.yml` and use any existing Debian VM or host with sudo.
- The deploy clones the repository on the target with git over SSH (`app_repo` is a `git@github.com:` address) using your forwarded ssh-agent: run `ssh-add` first, or change `app_repo` to an `https://` URL.

## 1. Inventory

Edit `ansible/inventory.yml`: `pve` (`ansible_host`, `ansible_user: root`) and `remoment_server` (`ansible_host` = the container's IP; `ansible_user: debian`, the user `proxmox.yml` creates). With `proxmox_ct_ip: dhcp` the first playbook prints the address at the end; reserve it in your router.

## 2. Variables (`ansible/group_vars/all.yml`)

The file ships with the author's values (addresses, NAS paths, a contact address in `metadata_user_agent`). Change at least:

| Variable | Meaning |
|---|---|
| `app_repo`, `app_branch`, `app_dir` | what to clone, which branch, where (`/opt/remoment`) |
| `app_url` | how you reach the server (`http://remoment.local` or the IP); written to `APP_URL` |
| `app_port` | web port (default 80) |
| `profile` | `control`, `standard` or `full` (shipped value: `full`). Written as `PROFILE`; also decides MariaDB (not for control) and the analysis sidecar (full only) |
| `proxmox_ct_*` | container cores, memory (4096 MB; 6144+ with `analysis_essentia_ml`), disk, bridge, IP/gateway/DNS |
| `music_dir`, `library_dir` | folders mounted into the container and the app. Sources, in order: NFS export (`proxmox_music_nfs`, `proxmox_library_nfs`), existing PVE host path (`proxmox_*_host_path`), else a dedicated volume. The shipped NFS values are the author's: **empty or change them** |
| `proxmox_music_readonly` | `true` (default): music is indexed, never modified |
| `stream_base_url` | address speakers fetch your files from (shipped: the author's IP; empty = `app_url`) |
| `queue_workers`, `analysis_enabled`, `analysis_essentia_ml`, `structure_enabled`, `stems_enabled`, `librespot_enabled` | optional services; see the comments in the file. `librespot_enabled` only sets the `.env` switch; start the containers by hand (comment in the file) |
| `acoustid_api_key` | the shipped value is the author's application key: register your own at acoustid.org |

NAS export requirements for NFS (uid shift of the unprivileged container) are in [proxmox-deployment.md](../architecture/proxmox-deployment.md#nas-over-nfs).

## 3. Secrets: Ansible Vault

`ansible/group_vars/vault.yml` is encrypted and holds the author's secrets, so **create your own** (`ansible-vault create` after moving the old file away). Variable names used by `ansible/templates/env.j2`:

| Variable | Used for | If missing |
|---|---|---|
| `vault_app_key` | `APP_KEY` | empty key: required. Generate with `php artisan key:generate --show` (any PHP 8.4 checkout) |
| `vault_db_password` | MariaDB password | `remoment` |
| `vault_spotify_client_id`, `vault_spotify_client_secret` | Spotify | empty (you can enter them in the web UI) |
| `vault_lastfm_api_key`, `vault_lastfm_shared_secret`, `vault_discogs_token`, `vault_audiodb_api_key` | metadata services | empty, TheAudioDB `123` |
| `vault_librespot_event_secret`, `vault_icecast_source_password`, `vault_icecast_admin_password` | Spotify stream | `remoment` |

Keep the vault password and `vault_app_key` safe: the key is needed to read stored secrets after a restore.

## 4. Run

```bash
ansible-playbook -i ansible/inventory.yml ansible/proxmox.yml
ansible-playbook -i ansible/inventory.yml ansible/setup.yml --ask-become-pass
ansible-playbook -i ansible/inventory.yml ansible/deploy.yml --ask-vault-pass
```

- `proxmox.yml`: Debian template (default `debian-13-standard`), unprivileged LXC with nesting, deploy user and your key. Skips an existing container.
- `setup.yml`: Docker, Avahi advertising `_remoment._tcp` (so ESP32 and other clients find the server), the music folders.
- `deploy.yml`: clone, `.env` from the template, image build, start, `composer install`, `npm ci`, `npm run build`, `migrate`, admin seed, `storage:link`, restart. Safe to re-run; re-running it is also how you update (see [upgrading-and-backups.md](upgrading-and-backups.md)).

Then open `app_url` and continue with [first-run.md](first-run.md). After deploying, add `music_dir` and `library_dir` as storage locations at `/settings/storage`.
