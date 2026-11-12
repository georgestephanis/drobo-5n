# drobo-webui: an optional web dashboard

A small web dashboard for a Drobo 5N running Debian with this kit, in the spirit of the old Drobo Dashboard: the front panel with its lights, how full the storage is, the disks and their SMART health, temperatures and the fan, and a few settings. It's **optional**. The kit boots, shares and runs fine without it, and everything it shows is also available over SSH. It just makes the box friendlier.

It needs about 25 MB with its dependencies (lighttpd, PHP). On a system running from the 1 GB internal USB stick, you can simply skip it.

![Status page](screenshots/status.png)

| | |
|---|---|
| ![Disks](screenshots/disks.png) | ![System](screenshots/system.png) |
| ![Settings](screenshots/settings.png) | ![Logs](screenshots/logs.png) |
| ![Status, dark](screenshots/status-dark.png) | ![Sign in](screenshots/login.png) |

<img src="screenshots/status-mobile.png" alt="Status on a phone" width="260">

## What it does

- **Status:** a picture of the front panel whose bay, power and blue capacity lights follow the same rules as `drobo-leds`. It also shows a list of bays plus the mSATA and USB disks, capacity, and a status line ("Your Drobo is working normally" or what's wrong). Select a bay for its disk's details, or to power the bay on or off.
- **Disks:** every disk by slot (bay 0–4, mSATA, USB stick) with model, size, temperature, SMART health, hours powered on and bad-sector counts. It also lists filesystems and any Linux RAID (md) arrays. Sleeping disks aren't woken to read SMART.
- **System:** OS, kernel and boot files, whether the boot copy on the SSD is in place, load, memory, processor temperature, fan level from the PMU, network, and the state of the kit's services.
- **Power:** the 12 V input voltage and the backup battery (a single 18650 cell) as read by the PMU, with a rough charge level. If the power fails, the PMU signals Linux and the battery carries the box through a clean shutdown; the dashboard shows when it's running on battery.
- **Settings:**
  - Device name (`NAME.local`).
  - Date and time: time zone, network time on or off, your own time servers, and setting the clock by hand when network time is off.
  - Fan curve (`/etc/default/drobo-fan`).
  - Which filesystems the capacity lights count (`/etc/default/drobo-leds`).
  - E-mail alerts through any SMTP server (STARTTLS, SSL or plain), with a test button.
  - Temperature unit, refresh rate, and an option to let anyone on the network see the status pages without signing in.
  - Dashboard password.
  - Disk sleep: spin the bay disks down after 10 minutes to 2 hours idle (a udev rule that runs `hdparm -S`, so it also covers disks inserted later).
  - SMART self-tests: off, a short test every Sunday, or that plus a long test on the 1st of the month (the `-s` schedule on smartd's `DEVICESCAN` line in `/etc/smartd.conf`, which keeps a `.bak`).
  - Software updates: how many updates are waiting and which are security fixes, "Check now" (`apt-get update`), and "Install updates" (`apt-get upgrade`, keeping your config files). Both run in the background as `drobo-webui-apt`, and the card shows the output. It says when a new kernel needs a restart.
  - HTTPS: whether this page is encrypted, the certificate's names, dates and SHA-256 fingerprint, a download button, and "Make a new one".
  - Restart and shut down.
- **Logs:** the journal for the kit's services, SSH, Samba and the kernel.
- **Alerts:** a timer runs every 5 minutes and mails a problem once when it starts and once when it clears. It covers a failing SMART check, bad sectors, hot disks or processor, a full filesystem, a degraded RAID array, a failed bay, failed services, a low backup battery, a sagging power input, and running on battery.
  A disk that fails its SMART health check also gets its bay light blinking red: the check writes the bay to `/run/drobo-leds/failed-bays`. It only adds and removes the bays it marked itself, so a bay you list there by hand stays.

## Install

Download the package from the [releases page](https://github.com/georgestephanis/drobo-5n/releases/tag/webui-v0.3.0), or build it yourself (see [Building](#building)). Then, on the Drobo with the kit already installed:

```sh
wget https://github.com/georgestephanis/drobo-5n/releases/download/webui-v0.3.0/drobo-webui_0.3.0_all.deb
sudo apt install ./drobo-webui_0.3.0_all.deb
sudo drobo-webui-passwd          # set the dashboard password
```

Then open `https://drobo5n.local/` (or the device's name or IP address). The certificate is self-signed, so the browser warns the first time; Settings > HTTPS shows its SHA-256 fingerprint to compare and offers it for download, so you can trust it on your computer (on a Mac: Keychain Access, Always Trust). Plain `http://` still works.

To remove it: `sudo apt remove drobo-webui` (add `--purge` to delete its settings too). Nothing else in the kit depends on it. Afterwards, `apt autoremove` offers to remove lighttpd and PHP; dry-run it first (`apt-get -s autoremove`) to check that it lists only those.

## How it's built

- **Web server:** its own lighttpd instance (`drobo-webui.service`, config `/etc/drobo-webui/lighttpd.conf`) on port 80 (HTTP) and 443 (HTTPS), with PHP through FastCGI. Both run as `www-data`. Installing it disables Debian's own `lighttpd.service`. To use other ports, change `server.port` and the `:443` sockets there.
- **HTTPS:** a self-signed certificate (P-256, 825 days, the longest Apple accepts) in `/etc/drobo-webui/tls/`; only root can read the key. `drobo-webui-cert ensure` runs before each start of the service and makes a new one when it's missing, within 30 days of expiry, or doesn't name the current device name, so changing the name in Settings restarts the dashboard with a new certificate. Settings can also make a new one on request; the previous pair is kept as `*.old`. TLS 1.2 is the minimum.
- **PHP** (`/usr/share/drobo-webui/`):
  - `public/api.php` is a small JSON API.
  - `lib/` reads everything that needs no special rights straight from `/proc`, `/sys` and `systemctl`.
  - Bay, slot and LED rules match `research/backplane-and-bays.md` and `kit/drobo-leds.sh`. Slots are found by SATA controller and port, never by `sdX` name.
- **Root helper** (`/usr/lib/drobo-webui/drobo-webui-helper`, PHP CLI): the only thing `www-data` may run through sudo (`/etc/sudoers.d/drobo-webui`).
  - It reads the bay GPIO lines, the PMU (through `drobo-pmu`, cached for 30 s since each command takes over a second) and SMART (cached for 2 min).
  - It writes the few settings above, and runs restart and shut down.
  - Each verb checks its own arguments, and no input ever reaches a shell. The verbs are listed in the file itself.
- **Sign-in:** one admin password, hashed in `/etc/drobo-webui/config.json` (root:www-data, 0640).
  - Sessions use a `SameSite=Strict` HttpOnly cookie (also `Secure` when signed in over HTTPS), and every change needs a CSRF token and a same-origin request.
  - Five wrong passwords lock that address out for 5 minutes.
  - The web UI can change the password only by giving the current one; `sudo drobo-webui-passwd` resets it.
- **Alerts:** `drobo-webui-alerts.timer` runs `drobo-webui-helper alert-check` as root every 5 minutes. It uses a built-in SMTP client, so no mail server is needed on the box.
- **Front end:** React 18, built with Vite into `public/assets/app.js` and `app.css` (about 57 KB gzipped). The built files are committed, so the package builds without Node.

Over HTTPS the password and session are encrypted on the network, but a self-signed certificate only protects you once you've trusted it (or checked the fingerprint). Keep it on your home network, and don't forward ports 80 or 443 to it from the internet.

## Building

```sh
cd webui
./build-deb.sh                # builds the UI (npm), then dist/drobo-webui_VERSION_all.deb
SKIP_UI=1 ./build-deb.sh      # package the committed UI build without Node
```

`dpkg-deb` comes from the system, or from a `debian:trixie` Docker container when it isn't installed (macOS).

For UI work, `cd ui && npm install && DROBO=http://drobo5n.local npm run dev` serves the React app locally and proxies the API to a real board. Sign in through the dev server's page.

`tools/screenshots.mjs` takes the screenshots above with headless Chrome (Node 22+, no dependencies). It masks IPv6 addresses, since SLAAC addresses contain the board's MAC:

```sh
ssh -L 8080:localhost:80 drobo5n.local     # in another terminal
DROBO_URL=https://drobo5n.local DROBO_PASSWORD=... node tools/screenshots.mjs
```

## Files

| Path | What |
|---|---|
| `lib/` | Shared PHP: hardware readers, config, sign-in, root-side readers, alerts, SMTP |
| `public/` | `index.php` (page shell), `api.php` (JSON API), built assets |
| `helper/drobo-webui-helper` | The root helper |
| `sbin/drobo-webui-passwd` | Set or reset the password |
| `etc/` | lighttpd config and the sudoers rule |
| `systemd/` | Web server service, alert service and timer |
| `debian/` | Package metadata and maintainer scripts |
| `ui/` | React source |
| `tools/screenshots.mjs` | Screenshot script |
| `screenshots/` | The pictures above |
