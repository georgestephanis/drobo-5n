# Drobo 5N to Debian

> [!NOTE]
> **AI disclosure.** This project was done by George Stephanis working with Claude (Anthropic's **Claude Opus 5.5**, in Claude Code). Claude wrote most of the scripts, the boot stub and this guide, and ran commands on the Drobo over its serial console and SSH. George did the hardware side: opening the case, wiring the serial consoles and a Raspberry Pi Pico bridge, power-cycling and recovering the board, swapping fans, and checking results by eye and ear. He set the goals and constraints and reviewed what was published.
>
> The working sessions add up to roughly **13–14 hours over three days (9–11 November 2026)**, with George at the bench for nearly all of it (about 240 messages back and forth), plus hardware time of his outside the sessions. From the session logs, Claude produced about **1.15 million output tokens** over about 1,450 model calls, and took in about **3 million new input tokens**.[^cache] Every step in this guide has run on real hardware: one Drobo 5N, across a few dozen boots, many of them failures along the way. The full log of what was tried, including the dead ends, is in the [project write-up](https://celestial-star.space.fast/).

How to replace Drobo OS on a Drobo 5N with plain Debian 13 that boots by itself, from the Drobo's own U-Boot, with no flashing of the bootloader. This is the short path. The long version, with every dead end, is in the [project write-up](https://celestial-star.space.fast/).

> [!TIP]
> **What you end up with.** Debian 13 (trixie, armhf, kernel `armmp-lpae`) on the Drobo's internal 1 GB eUSB stick. It boots by itself in about 2½ minutes, gets its address by DHCP, and answers SSH at `drobo5n.local`. All five bays work as ordinary SATA disks. The case fan follows the SoC and disk temperatures, and kernel updates rebuild the boot files on their own.

> [!WARNING]
> **Before you start.** This erases Drobo OS from the eUSB stick; step 5 makes a full backup first so you can go back. It doesn't touch the SPI flash (U-Boot) or the NOR flash. The only lasting changes outside the stick are a few U-Boot environment variables, all listed in step 7. You'll be opening the case and connecting a serial adapter. Keep a fan on the heatsink if the case is open. You do this at your own risk.

For what we learned about the hardware, U-Boot, the power controller and the stock firmware, see [`research/`](research/).

## Steps

1. [What you need](#1-what-you-need)
2. [Get a console on U-Boot](#2-get-a-console-on-u-boot)
3. [Prepare the installer files](#3-prepare-the-installer-files-on-your-computer)
4. [Boot the Debian installer](#4-boot-the-debian-installer)
5. [Install Debian on the stick (and back it up)](#5-install-debian-on-the-stick-and-back-it-up)
6. [Run the Drobo kit inside the new system](#6-run-the-drobo-kit-inside-the-new-system)
7. [Make U-Boot boot Debian](#7-make-u-boot-boot-debian)
8. [First boot and everyday use](#8-first-boot-and-everyday-use)
9. [Known limitations](#9-known-limitations)
10. [Troubleshooting](#10-troubleshooting)
11. [Going back to Drobo OS](#11-going-back-to-drobo-os)
12. [How it works](#12-how-it-works)

## 1. What you need

- A Drobo 5N (Marvell Armada XP MV78460, 2 GB RAM, internal eUSB stick).
- A **3.3 V** USB serial adapter (not 5 V) and jumper wires. 115200 baud, 8N1.
- A computer on the same wired LAN, with Python 3 and `dtc`/`fdtput` (macOS: `brew install dtc`; Debian/Ubuntu: `apt install device-tree-compiler`). A terminal program for the serial port (`screen`, `picocom`, `minicom`…).
- Two free IP addresses on that LAN for U-Boot to use during the network boot, and a DHCP server (your router) for Debian.
- The kit: the [`kit/`](kit/) folder of this repository.

| Kit file | Runs on | What it does |
|---|---|---|
| [`make-boot-files.py`](kit/make-boot-files.py) | your computer | Turns Debian's `vmlinuz`, an initrd and the device tree into the three files U-Boot loads |
| [`tftpd.py`](kit/tftpd.py) | your computer | A minimal read-only TFTP server, if you don't have one |
| [`drobo5n-base.dts`](kit/drobo5n-base.dts) | both | The board's device tree (no MAC in it; you add yours) |
| [`stub.bin`](kit/stub.bin) ([`stub.S`](kit/stub.S)) | U-Boot | A small trampoline that cleans up after U-Boot and enters the kernel. Build with `arm-none-eabi-gcc -march=armv7-a -marm -DCOPY -DIMG_LEN=0 -DDEST=0x01208000 -DDTB_ADDR=0x03f00000` |
| [`install-on-stick.sh`](kit/install-on-stick.sh) | the Drobo | Installs everything below and builds `/boot/drobo` |
| [`mkdrobokernel`](kit/mkdrobokernel), [`postinst-hook`](kit/postinst-hook), [`drobo-boot-ok`](kit/drobo-boot-ok.sh) | the Drobo | Rebuild the boot files whenever Debian installs a kernel or initrd, keeping the last set that booted in `prev` |
| [`mkdrobodtb`](kit/mkdrobodtb), [`drobo5n-fixups.dtsi`](kit/drobo5n-fixups.dtsi) | the Drobo | Device tree fixups: drive-bay expanders, mSATA power, unused Ethernet ports off |
| [`drobo-pmu.sh`](kit/drobo-pmu.sh), [`drobo-fand.sh`](kit/drobo-fand.sh), [`drobo-fan.service`](kit/drobo-fan.service) | the Drobo | Talk to the power-management chip; temperature-controlled fan |
| [`drobo-bays.sh`](kit/drobo-bays.sh), [`drobo-bays.service`](kit/drobo-bays.service) | the Drobo | Power the bays that hold a disk at boot, a few seconds apart |
| [`initramfs-modules`](kit/initramfs-modules) | the Drobo | The USB, SATA and ext4 drivers the small initrd needs |

## 2. Get a console on U-Boot

1. Open the case. The console U-Boot uses is **J2** (UART0): four bare holes, under the RF shield, with no header fitted at the factory. Solder three wires to it (pin 2 = board RX, pin 3 = board TX, pin 4 = ground; 3.3 V logic) and route them out through the case, so you don't need to open it again. You'll need to cut a small hole in the RF shield to reach it. The factory-fitted 4-pin header **J3** is a different UART: the stock Linux console, not this one.

   ![J2, the console header: four holes at the board's edge beside the factory J3 header. Pin 1 is the square pad; pin 2 board RX, pin 3 board TX, pin 4 ground.](images/j2-console.svg)
2. Wire it: header pin 2 is the board's RX (to your adapter's TX), pin 3 is the board's TX (to your adapter's RX), pin 4 is ground. Add a separate ground wire to the board if your input seems to be ignored. A bad ground crimp gives a console that prints fine but ignores input.
3. Open the port at 115200 8N1 and power the Drobo on.
4. The stock `bootdelay` is 0, so **tap the space bar continuously from the moment you press power** until you see the `Marvell>>` prompt. Then type slowly or paste in short pieces. This U-Boot drops characters if you send them too fast.
5. Save a copy of the environment by copying the output of `printenv` into a file. Note `ethaddr`, the board's MAC address; you'll need it. Then give yourself time to stop it in future:

   ```
   setenv bootdelay 3
   saveenv
   ```

> [!NOTE]
> Don't run `flinfo`: it crashes this U-Boot. Avoid `bootm` for Linux too: this Drobo build can't start a modern kernel that way. Everything here uses `go`.

## 3. Prepare the installer files (on your computer)

```sh
git clone https://github.com/georgestephanis/drobo-5n.git
cd drobo-5n
B=https://deb.debian.org/debian/dists/trixie/main/installer-armhf/current/images
curl -LO $B/netboot/vmlinuz -LO $B/netboot/initrd.gz -LO $B/SHA256SUMS
grep -E ' \./netboot/(vmlinuz|initrd.gz)$' SHA256SUMS        # compare with:
shasum -a 256 vmlinuz initrd.gz
```

Put your MAC into the device tree, so Debian always gets the same DHCP lease. In `kit/drobo5n-base.dts`, replace the comment under `ethernet@70000 {` with a line like this, using your `ethaddr`:

```
local-mac-address = [00 1a 62 12 34 56];
```

Then build the boot files and serve them over TFTP:

```sh
python3 kit/make-boot-files.py vmlinuz initrd.gz kit/drobo5n-base.dts
# writes out/kernel.bin, out/initrd, out/drobo5n.dtb
python3 kit/tftpd.py --root out --bind 0.0.0.0     # UDP port 69; keep it running
```

On macOS use Apple's `/usr/bin/python3` for the TFTP server. Local Network privacy can block other Pythons from reaching the LAN.

## 4. Boot the Debian installer

At the `Marvell>>` prompt, set an address for U-Boot and your computer's address as the server. These live in RAM only; don't `saveenv` here. Then load the three files and jump:

```
setenv ipaddr 192.168.1.99
setenv serverip 192.168.1.50
setenv netmask 255.255.255.0
tftpboot 3f00000 drobo5n.dtb
tftpboot 4000000 initrd
tftpboot 8000000 kernel.bin
usb stop
go 8000000
```

The stub prints a few register lines, then the kernel boots, and the installer's "Select a language" screen appears on the serial console within seconds.

> [!NOTE]
> Those addresses must stay as they are: `3f00000` for the DTB, `4000000` for the initrd, `8000000` for the kernel. `usb stop` before `go` matters; without it the USB controller keeps writing into memory under Linux's feet.

## 5. Install Debian on the stick (and back it up)

Go through the installer as usual, over the serial console. Pick the network interface `eth0` and let it use DHCP. Notes for each step:

- **Users:** `admin` is a reserved name in Debian; pick another. You can leave root locked.
- **Back up the stick before partitioning.** When you reach "Partition disks", switch to a shell. The installer runs in `screen` on the serial console, so press `Ctrl-A` then `2`, or choose "Execute a shell" from the main menu. The stick is `/dev/sda` (about 981 MB). On your computer run `nc -l 9000 > eusb-drobo-os.img` (some systems need `nc -l -p 9000`). Then, in the installer shell:

  ```sh
  dd if=/dev/sda bs=1M | nc 192.168.1.50 9000
  ```

  Check that the image is the stick's full size before going on. Keep it: it's your way back to Drobo OS.
- **Partitioning:** guided partitioning says the stick is too small, so use manual. Create **one partition over the whole stick, ext2, mounted at `/`, no swap**. ext2 suits the small flash stick. It has to be `/dev/sda1`, because that's what the device tree's command line expects.
- **Software selection:** tick **only "SSH server"**. Untick "standard system utilities" and any desktop. The stick is only 1 GB.
- **Boot loader:** there's nothing to install. Choose "Continue without boot loader" and dismiss the warning.
- **Stop before the final reboot.** At "Finish the installation", don't continue yet. Go to step 6 first.

## 6. Run the Drobo kit inside the new system

Still in the installer's shell (`Ctrl-A 2`), with the installed system at `/target`. The installer has no `git`, so fetch the kit as a tarball; it unpacks to `kit/`:

```sh
cd /target/root
wget http://drobo5n-debian.view.homefast.stephanis.me/drobo5n-kit.tar.gz
tar xzf drobo5n-kit.tar.gz
chroot /target sh /root/kit/install-on-stick.sh 00:1a:62:12:34:56     # your ethaddr
```

That tarball holds the same files as `kit/` here. If you'd rather not download it, serve this repository's `kit/` folder from your computer (`python3 -m http.server 8000` in the repo) and `wget` each file from there into `/target/root/kit/`.

If `apt-get` fails inside the chroot, mount the system directories first: `for d in dev proc sys; do mount --bind /$d /target/$d; done`, and copy `/etc/resolv.conf` into `/target/etc/`.

It takes a few minutes. It installs `u-boot-tools`, `device-tree-compiler`, `xz-utils`, `gpiod`, `busybox` (its `i2cget`/`i2cset` avoid `i2c-tools`, which pulls in Perl), `openssh-server` and `avahi-daemon`, and copies the tools in. It builds the device tree with your MAC, shrinks the initrd to about 10 MB (`MODULES=dep` plus the USB, SATA and ext4 drivers), and writes the boot files to `/boot/drobo`. It ends by listing them:

```
drobo5n.dtb  initrd  kernel.0  kernel.1  kernel.2  kernel.3  kernel.4  VERSION  prev/
```

Then go back to the installer (`Ctrl-A 1`) and let it finish and reboot. Be ready to tap space again to catch U-Boot.

> [!NOTE]
> Running the kit from the installer's chroot is the shortcut. On the test board, the same script ran on the installed system, and that boot set boots. If the chroot gives you trouble, finish the install, then boot the installed system once over TFTP: copy `/boot/vmlinuz-*` and `/boot/initrd.img-*` off the stick, and use `make-boot-files.py` with them. Then run `sudo sh install-on-stick.sh <MAC>` there.

## 7. Make U-Boot boot Debian

At the `Marvell>>` prompt, type these one line at a time:

```
setenv bootcmd_stock amp_boot
setenv amp_enable no
setenv autostart no
setenv dbusb usb start
setenv dbstop usb stop
setenv dbgo go 8000000
setenv dbdtb ext2load usb 0:1 3f00000 /boot/drobo/drobo5n.dtb
setenv dbini ext2load usb 0:1 4000000 /boot/drobo/initrd
setenv dbk0 ext2load usb 0:1 b000000 /boot/drobo/kernel.0
setenv dbk1 ext2load usb 0:1 b800000 /boot/drobo/kernel.1
setenv dbk2 ext2load usb 0:1 c000000 /boot/drobo/kernel.2
setenv dbk3 ext2load usb 0:1 c800000 /boot/drobo/kernel.3
setenv dbk4 ext2load usb 0:1 d000000 /boot/drobo/kernel.4
setenv dbu0 bootm b000000
setenv dbu1 bootm b800000
setenv dbu2 bootm c000000
setenv dbu3 bootm c800000
setenv dbu4 bootm d000000
setenv bootcmd run dbusb dbdtb dbini dbk0 dbk1 dbk2 dbk3 dbk4 dbu0 dbu1 dbu2 dbu3 dbu4 dbstop dbgo
printenv bootcmd amp_enable
saveenv
reset
```

> [!WARNING]
> **`amp_enable no` is essential.** With it set to `yes` (the stock value), Marvell's start-up code puts `bootcmd=amp_boot` back on every boot, whatever you saved. `amp_boot` then looks for vxWorks, doesn't find it, and hangs the board, and the power button may not get it out of that. Before trusting the change, catch U-Boot after the `reset` and check that `printenv bootcmd` still shows your line.

From now on the Drobo boots Debian by itself. U-Boot reads about 20 MB from the stick (around 80 seconds; it's slow), unpacks the kernel pieces, and jumps. Debian is on SSH about 2½ minutes after power-on.

## 8. First boot and everyday use

```sh
ssh you@drobo5n.local
systemctl --failed                       # should be empty
sudo drobo-bays status                   # disk present / powered, per bay
sudo journalctl -u drobo-fan -b          # "fan target 16" etc.
cat /sys/class/hwmon/hwmon*/temp1_input  # SoC (and disk) temperatures, millidegrees
```

- **Drive bays.** Bays are numbered 0 (top) to 4 (bottom). At boot, `drobo-bays` powers every bay that holds a disk, 4 s apart. The disks then appear as ordinary SATA disks (`/dev/sdb`…). Use them with whatever you like: ext4, mdadm, mergerfs and SnapRAID, or OpenMediaVault. Drobo's BeyondRAID disk packs are not readable. `sudo drobo-bays on` rescans after you insert a disk; `off` powers all bays down.
- **Fan.** `drobo-fand` sets the speed (01–31) from the hottest sensor, resent every 30 s. The power controller winds the speed down by itself if nobody keeps setting it. It never goes below 16, because the stock fan's bearing can stall at low speed. Tune it in `/etc/default/drobo-fan`, for example `FLOOR=12 SOC_HI=70`. If the fan doesn't start after a cold boot, give it a nudge, or replace it.
- **Kernel updates.** After `apt upgrade` installs a kernel or rebuilds an initrd, the hook rebuilds `/boot/drobo`. A minute after each good boot, `drobo-boot-ok` records that set's checksums, and `mkdrobokernel` only moves a set into `/boot/drobo/prev` if it has booted, so `prev` is always a known-good fallback even though Debian runs the hook twice per kernel install. It also refuses to build with less than 50 MB free. Tested on 11 Nov by reinstalling the kernel package through apt and rebooting; a new kernel version hasn't come out since.
- **Stick wear.** Root is on a 1 GB USB flash stick. Keep writes down: no swap, `noatime` (the installer's default), and consider `Storage=volatile` or a small `SystemMaxUse` in `/etc/systemd/journald.conf`. You can also move root to a disk: an mSATA SSD in the slot under the board, or a bay disk. The kernel, DTB and initrd stay on the stick (U-Boot can only read USB), and only `root=` in the DTB changes.

## 9. Known limitations

- **Power button.** Under Linux the power controller sits in a state where it ignores the power button on the back of the unit. To turn the Drobo off, run `sudo poweroff`, wait for the console to go quiet, then unplug it.
- **The first 16 MB of RAM** are left unused, because writes below 16 MB hang this board early in boot. That leaves 1.98 GB visible.
- **Front panel and bay LEDs** aren't driven yet, although the bay LED lines are named in the device tree (`bay0-led-a`…).
- **Hardware crypto (CESA) is off.** Its hash self-tests fail on Debian's 6.12 kernel, so it's blacklisted; the kernel uses software crypto instead.
- **Boot is slow**, about 2½ minutes, almost all of it U-Boot reading the stick.
- **The mSATA slot** is powered from boot (SoC GPIO 66, held high by the device tree). It's expected to be the SoC's second SATA port, `ata2`, but no SSD has been tested in it yet. It isn't hot-swap.

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| U-Boot runs `amp_boot` / "Checking for vxWorks" and hangs | `amp_enable` is still `yes`. Unplug the power for 10 s, catch U-Boot, and redo step 7. |
| Typing at `Marvell>>` does nothing | The ground wire, a loose connection on J2, or TX/RX swapped. Output working only means RX is fine. |
| Nothing after `go 8000000` | Make sure you ran `usb stop` first, and that the initrd ends below `0x8000000` (`make-boot-files.py` checks this). |
| `inflate() returned -5` | A kernel piece is too big for this U-Boot's 8 MB unpack limit. `mkdrobokernel` splits at 7 MiB, so rerun it. |
| Boots, but can't find root | Make sure root is `/dev/sda1`, ext2. Or, from a TFTP boot of the installer, chroot and set `MODULES=most` in `/etc/initramfs-tools/initramfs.conf`, then `update-initramfs -u`. |
| A different IP every boot | The MAC isn't in the DTB. Check with `fdtget /usr/local/lib/drobo/drobo5n-base.dtb /soc/internal-regs/ethernet@70000 local-mac-address`. |
| Need the old kernel | In U-Boot, point the load commands at `/boot/drobo/prev/…` for one boot, e.g. `setenv dbk0 ext2load usb 0:1 b000000 /boot/drobo/prev/kernel.0` (and so on), then `boot`. |

## 11. Going back to Drobo OS

1. Boot the installer once more over TFTP (step 4), open a shell, and write your backup back: `nc -l -p 9000 | dd of=/dev/sda bs=1M` in the installer, then `nc <drobo-ip> 9000 < eusb-drobo-os.img` on your computer. Run `sync` before rebooting.
2. In U-Boot: `setenv amp_enable yes`, `setenv bootcmd amp_boot`, `saveenv`, `reset`.

## 12. How it works

For the curious. The details are in the [write-up](https://celestial-star.space.fast/).

- The Drobo's U-Boot (2009.08, Marvell's AMP build) normally boots vxWorks on one core. It can't start a modern kernel with `bootm`, so we use `go` on a small stub. The stub flushes and turns off the caches and MMU, holds the other cores in reset, stops the USB controller, copies the uncompressed kernel to `0x1208000`, and enters it with the DTB pointer in `r2`.
- Debian's multi-platform kernel expects to sit at RAM + `0x208000`. Copying it 16 MB higher works around the board hanging on writes below 16 MB.
- U-Boot's `bootm` is still used, but only as a gzip unpacker. The 26 MB kernel is cut into 7 MiB pieces, each wrapped as a gzip "standalone" image with `autostart=no`. That keeps each piece under this U-Boot's 8 MB unpack limit, and it cuts what U-Boot has to read from the slow stick from 27 MB to about 9 MB.
- The device tree is mainline's `armada-xp-db` (the reference board this U-Boot believes it is). The internal registers are moved to `0xd0000000`, where this U-Boot leaves them. Flash, SD and NAND are off, and only the two PCIe ports that are wired (`pcie@8,0`, `pcie@9,0`, a Marvell 88SE9122 SATA controller each) are on. The fixups add the backplane's two PCA9555 I²C expanders (0x20 for bay LEDs; 0x23 for bay power and the active-low detect lines), hold the mSATA power line on, and drop three Ethernet ports the Drobo doesn't have.
- The fan and power live on a separate controller reached through the SoC's third UART (`ttyS2`, 38400 baud), with ASCII commands such as `FT31` (fan target), `FA.` (actual) and `V` (version).

---

November 2026 · written by George Stephanis with Claude. Tested on one Drobo 5N: every step has run on that board, and the kit's `install-on-stick.sh` was run on its installed system and booted. The whole sequence has not yet been repeated on a second, fresh Drobo, so corrections and issues are welcome.

## License

GPL-2.0, the same as the Linux kernel and U-Boot. See [LICENSE](LICENSE). `kit/drobo5n-base.dts` is derived from the mainline kernel's Armada XP device trees, which are GPL-2.0 or MIT.

[^cache]: Not counted above: each model call also re-reads the conversation so far from the prompt cache, which adds up to about 317 million cached input tokens. Those are cheap re-reads of the same context, not new work.
