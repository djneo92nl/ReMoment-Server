# ReMoment on a Raspberry Pi

## Where this stands

**There is no ReMoment Raspberry Pi image yet.** It is planned for 1.0 (an SD-card image you write with the Raspberry Pi Imager, switch on, and open in your browser), but it has not been built. Please do not look for a download: there is none. This page says what a Pi can do and how a technical person can already install it by hand.

## What a Pi can run

ReMoment comes in three sizes, called profiles ([overview](README.md#profiles)):

| Profile | What you get | Pi it suits |
|---|---|---|
| **Control** | Control your speakers and TVs from a web page, the REST API and wall panels. No music library. | Pi 3 (Pi 2 maybe, see below) |
| **Standard** | Control plus your music library: browse albums and artists, playlists, covers. | Pi 3 with 1 GB, and up |
| **Full** | Everything, including audio analysis (loudness, tempo, mood). | Works on a Pi 3, but analysis is very slow. A small PC is a better home. |

Be aware of these limits:

- **Nothing has been measured on a real Pi yet.** The memory and speed figures in the docs are estimates. The test that proves the Control profile is safe on an SD card (running for 24 hours) has not been done.
- **Pi 2:** it is a 32-bit computer. The software would need 32-bit builds of every part, which has not been checked. If one is missing, a Pi 3 is the smallest Pi that works.
- **SD cards wear out and can break when the power goes off suddenly.** Use a good card, a proper power supply, and keep backups somewhere else (see [upgrading-and-backups.md](upgrading-and-backups.md)).

## Installing it by hand today (technical users)

You can already run the **Control** profile on a Pi with Docker. This needs a 64-bit Raspberry Pi OS (or Debian), Docker with the Compose plugin, a terminal and some patience: the program is built on the Pi itself, which takes a long time.

1. Install Docker and Git on the Pi.
2. Follow [install-docker.md](install-docker.md), and in the `.env` file choose:
   ```ini
   PROFILE=control
   DB_CONNECTION=sqlite
   DB_DATABASE=/data/sqlite/database.sqlite
   ```
   and leave `COMPOSE_PROFILES` empty, so no database server is started.
3. Open the Pi's address in a browser and continue with [first-run.md](first-run.md).

For Standard use `PROFILE=standard` with MariaDB as described on the Docker page. This has not been tried on a Pi: *unverified*.

## Moving up later

A Control install can later move to Standard or Full with the command `php artisan profile:upgrade`, which makes a backup first and copies your data into MariaDB. The other direction is not supported.

## Sending your music

Your own music files do not need to be on the Pi. Put them on a USB disk or a network drive and mount them where the Docker page says (`MUSIC_DIR`). The Control profile has no library, so this only matters for Standard and Full.
