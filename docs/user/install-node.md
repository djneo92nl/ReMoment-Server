# Add a helper to your ReMoment (the Node)

A **Node** is a small program for a Raspberry Pi or another Linux machine that does a job for your ReMoment server: record a line-in source, rip a CD with a drive that is attached to it, or fill a USB stick for offline listening. It is optional and comes with ReMoment 1.1, not 1.0. The server itself is installed with one of the methods in [the install list](README.md).

> **Not tried on a real Raspberry Pi yet.** The package is built and checked automatically; installing it on a real Pi has not been done.

## Install

1. Download the package for your Pi from the ReMoment release page: `armhf` for a 32-bit Pi (Pi 2, Pi 3 with 32-bit Raspberry Pi OS), `arm64` for a 64-bit one.
2. In a terminal on the Pi: `sudo apt install ./remoment-node_<version>_armhf.deb`
3. Start it: `sudo systemctl start remoment-node`. (It is switched on for every boot, but the install does not start it, so you can change the settings first.)

The node looks for your server by itself, as long as the server and the Pi are on the same network and the server runs the `avahi-daemon` (the Ansible install does this). If it cannot find it, open `/etc/remoment-node/config.json` and put the address of your server between the quotes after `"server"`, for example `"http://192.168.1.20"`, then run `sudo systemctl restart remoment-node`.

## Approve it

Open ReMoment in your browser, go to Settings › Clients and approve the new node (it shows a pairing code, also visible with `journalctl -u remoment-node`). After approval you can switch on the jobs it may do (line-in, ripper, sync target). A job that needs hardware has one extra step on the Pi, described in the [node README](../../node/README.md#install-on-a-pi-deb).

## Update and remove

Install a newer package the same way; your settings stay. `sudo apt remove remoment-node` removes the program and keeps the settings, `sudo apt purge remoment-node` removes those too.

Technical details: [node-agent.md](../architecture/node-agent.md).
