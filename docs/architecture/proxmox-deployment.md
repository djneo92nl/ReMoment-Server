# Proxmox deployment (Ansible)

Runs ReMoment in an unprivileged LXC container (nesting on, so Docker works) on an existing Proxmox VE host, with a MiniDLNA server in the same container.

1. `ansible/inventory.yml`: set the `pve` host (root SSH) and `remoment_server` (the container's IP). `ansible/group_vars/all.yml`: the `proxmox_*` and `dlna_*` variables.
2. `ansible-playbook -i ansible/inventory.yml ansible/proxmox.yml` — downloads the Ubuntu 24.04 template, creates the container, installs sshd/sudo and the deploy user + your public key. Skips an existing container id.
3. `ansible/setup.yml --ask-become-pass` — Docker, Avahi (`_remoment._tcp`) and MiniDLNA.
4. `ansible/deploy.yml --ask-vault-pass` — the app.

Skip step 2 to use any existing Ubuntu VM/host instead.

## DLNA server

MiniDLNA runs on the container itself (not in Docker) so SSDP multicast works. It serves `dlna_media_dir` (`/srv/media`) as "`dlna_friendly_name`" on port `dlna_port` (8200). Media comes from `mp0`: either a dedicated volume (`proxmox_media_size` GB) or, with `proxmox_media_host_path`, a read-only bind mount of a PVE-host path (must be world-readable, since unprivileged containers map host uid 0 to 100000+). Copy music in, then add/scan the server at `/settings/dlna` (`php artisan library:scan`).
