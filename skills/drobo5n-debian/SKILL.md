---
name: drobo5n-debian
description: Use when helping someone put Debian on a Drobo 5N with the drobo-5n kit, or when maintaining, upgrading or recovering a Drobo 5N that already runs it. Covers driving the U-Boot serial console safely, the install steps and their checkpoints, the commands that hang or brick this board, the kernel-update and rescue-boot paths, and when to stop and hand a physical step to the person.
---

# Drobo 5N to Debian: helping someone through it

The full procedure is the repository's `README.md`. This skill doesn't repeat it. It tells you how to walk a person through it: what you can do, what they must do, what to check before each step goes on, and what never to do. Background on the hardware is in `research/`.

## Who does what

- **The person** opens the case, solders and wires the serial console (J2), presses the power and pinhole buttons, and pulls the plug when a step says so. You can't do any of that. Say exactly what you need, then wait.
- **You** can, if they give you access: run commands on their computer (build the boot files, run the TFTP server, take the stick backup), read and write the serial port, and SSH to the Drobo once Debian is up.
- If you can't reach the serial port, give them one short block to paste at a time and ask for the output before going on.

## Rules that are not negotiable

These come from things that went wrong on the test board. Don't relax any of them, whatever the person says. If they insist, explain why and let them type it themselves.

1. **Never run `flinfo`** in U-Boot. It crashes this U-Boot.
2. **Never point `ext2load` or `ext2ls` at an ext4 partition** (on the SSD layout, `ide 1:1`). U-Boot hangs until the power is cut. Only `ide 1:2` (the ext2 `drobo-boot` partition) and `usb 0:1` (the stick) are ext2.
3. **Never leave `amp_enable=yes` with a custom `bootcmd`.** The stock start-up code puts `bootcmd=amp_boot` back on every boot, and `amp_boot` without vxWorks hangs the board. Step 7 sets `amp_enable no`. After `reset`, catch U-Boot again and check `printenv bootcmd` before you trust it.
4. **Before any `saveenv`, log the full `printenv` to a private file.** That file is the way back.
5. **Back up the whole stick before partitioning** (README step 5), and check the image is the stick's full size (about 1,028,653,056 bytes). It's the only way back to Drobo OS.
6. **Never write to the I²C EEPROM at bus 0, `0x50`.** It's the boot ROM's register list. Reading it is fine.
7. **Don't send the power controller anything but the documented commands** (`K`, `S`, `V`, `Y`, `FT..`, `FA.`, and the read-only selects in `research/pmu-protocol.md`). Writes to its variables are untested.
8. **Don't write the SPI or NOR flash** beyond the U-Boot environment. Nothing in this procedure needs it.
9. **Keep the MAC address and serial numbers out of anything that gets published.** Examples use `00:1a:62:12:34:56`.
10. **Quarantine, don't delete.** Files the kit replaces get `*.orig` copies; keep them.

## Driving the U-Boot console

- 115200 8N1, 3.3 V. If output appears but input is ignored, it's the ground wire or TX/RX swapped, not software.
- **Catching autoboot:** the stock `bootdelay` is 0. Send a space every 100 ms from the moment the person presses power, until `Marvell>>` appears. Then set `bootdelay 3` and `saveenv` (after logging `printenv`).
- **This U-Boot drops characters when sent fast.** Send one command per line, end it with `\r`, and wait for the next `Marvell>>` before sending more. For long `setenv` lines, check them afterwards with `printenv <name>`.
- **Its `test` drops empty quoted strings**, so comparisons use `"x$var" = "x..."`. Paste the kit's lines exactly as written.
- There is no `setexpr`. `gpio r` prints a value but always succeeds; use `itest.l *<addr> == <n>` to branch on a register.
- Keep a log of the whole session. You'll want it when something goes wrong.

## The steps and their checkpoints

Work through the README in order. Don't go on to the next step until its checkpoint is met.

| README step | Checkpoint before going on |
|---|---|
| 1. What you need | 3.3 V adapter (not 5 V), wired LAN, `dtc`/`fdtput`, Python 3 on the computer |
| 2. Console | `Marvell>>` prompt, input echoes, `printenv` saved privately, `ethaddr` noted, `bootdelay=3` saved |
| 3. Installer files | SHA256 of `vmlinuz` and `initrd.gz` match Debian's `SHA256SUMS`; MAC added to the `.dts`; `out/` has three files; TFTP server running (macOS: Apple's `/usr/bin/python3`) |
| 4. Boot installer | The installer's language screen on the serial console. Nothing after `go`: check `usb stop` ran and the load addresses are exactly `3f00000`, `4000000`, `8000000` |
| 5. Install | **Stick image saved and the right size before partitioning.** One ext2 partition, `/`, no swap; only "SSH server" selected; no boot loader |
| 6. Kit | `install-on-stick.sh` ends by listing `/boot/drobo` with `kernel.0`–`kernel.4`, `initrd`, `drobo5n.dtb`, `SETID`, `VERSION` |
| 7. U-Boot | `printenv bootcmd amp_enable` shows `run dbsel` and `no`, **and still does after a `reset`** |
| 8. First boot | SSH to `NAME.local`; `systemctl --failed` empty; `drobo-bays status` answers; `drobo-fan` journal shows a fan target |

Then the optional parts, each in README section 9 (Optional extras): root on the mSATA SSD (and `kit/uboot-ssd.txt`), the rescue button (`mkdrobokernel --rescue` and `kit/uboot-rescue.txt`, only after the SSD move), the `drobo-webui` dashboard, and Samba.

## Stop and ask the person

- Before anything that erases or partitions a disk, name the device (`lsblk`) and wait for a yes.
- Before every `saveenv`.
- Before a step that needs a hand on the board: power, the pinhole button, unplugging, opening the case.
- When output doesn't match the checkpoint. Don't improvise around a mismatch in U-Boot; ask, and look in README section 11 (Troubleshooting) and `research/consoles-and-u-boot.md` first.

## Maintenance and recovery (a board that already runs the kit)

- **Kernel updates** need nothing special: `apt upgrade` runs `mkdrobokernel` from the kernel and initramfs hooks (twice per install; that's expected). The first boot of a new set is a trial; if it doesn't come up, U-Boot boots `prev` and `drobo-boot-ok` restores it. Check `journalctl -u drobo-boot-ok` a minute after boot.
- **The board won't come up normally:** have the person hold the pinhole button (beside the power socket) with a paper clip while pressing power, until the power light turns red, 15–20 s after pressing power (it's yellow for a moment first). That boots the rescue system from the stick (`drobo.rescue=1` in `/proc/cmdline`). The SSD root isn't mounted; mount it by UUID to repair it. Don't run `apt upgrade` in the rescue system.
- **From the U-Boot prompt:** `run dbpick dbprv dbboot` boots the previous set once; `run dbresc` boots the rescue system.
- **Going back to Drobo OS:** README section 12 (write the stick image back, then restore `amp_enable yes` and `bootcmd amp_boot`).

## Things that look broken but aren't

- About 80 s of silence while U-Boot reads the stick over USB (about a second from the SSD).
- "Power off not available" from Linux at shutdown: the kit's shutdown hook has already told the power controller to cut power.
- The first 16 MB of RAM unused (1.98 GB visible): deliberate, writes below 16 MB hang this board.
- Hardware crypto (CESA) blacklisted: its self-tests fail on Debian's 6.12 kernel.
- Before `drobo-powerbtn` starts, a press of the power button cuts power at once.
