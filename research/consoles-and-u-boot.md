# Consoles and U-Boot

## Serial headers

Both are 3.3 V, 115200 8N1.

| Header | UART | What it carries |
|---|---|---|
| `J2` (four bare holes under the RF shield; no header fitted) | UART0, `0xD0012000` | BootROM, U-Boot, the vxWorks console (`[vxWorks *]#`), and mainline Linux (`ttyS0`, with the registers left at `0xD0000000`) |
| `J3` (4-pin header fitted at the factory) | UART1, `0xD0012100` | The stock Linux 3.2 console: a `shockwave:/ #` root shell, no login |

Notes before 11 November 2026 had `J2` and `J3` the other way round, and called them `JP2`/`JP3` in places. The factory-fitted header is the less useful one: the console that matters is `J2`, which needs wires soldered on. Solder wires straight to its holes and route them out through the case, rather than fitting pins that must stay reachable.

`J2` pinout in the working setup: pin 2 = board RX, pin 3 = board TX, pin 4 = ground. Notes taken before a wiring fault was found had these the other way round, so check with a scope or a listen-only adapter first. A bad ground crimp gave a console that printed perfectly but ignored everything typed, which looked like the board freezing. If input is ignored, check the jumper and the ground first.

UART2 (`0xD0012200`) goes to the PMU at 38400 baud ([pmu-protocol.md](pmu-protocol.md)).

**Send to U-Boot slowly.** Its UART buffers about 16 bytes, and a longer burst loses the tail. Pace pasted text by character, or paste in short pieces.

## U-Boot

`U-Boot 2009.08`, Marvell 2.3.4, `shockwave-uboot-2.18`, built for the `DB-78460-BP` reference board. `arch_number` 3036 (`0xBDC`). It passes ATAGs and has no FDT support. It has `ext2load` but no `ext4load`, and `fatload` but no `fatwrite`.

### Stock behaviour

- **Catching autoboot:** with `bootdelay=0` it checks for a key only once, at "Hit any key to stop autoboot". To catch it reliably, send a space continuously from power-on (every 100 ms worked), then run `setenv bootdelay 3; saveenv`.
- **`amp_enable=yes` rewrites `bootcmd=amp_boot` on every boot**, whatever you saved. With `amp_enable=no`, a saved `bootcmd` survives a reset. `amp_boot` with no vxWorks to load hangs the board, and the power button may not get it out of that.
- **Drobo's AMP rules:** with `amp_enable=yes`, `bootm` enforces Drobo's AMP layout ("Load address != mem base + 0x8000"). Even with AMP off, it takes the kernel command line from `bootargs_g0`, not `bootargs`.
- **`bootm` never reaches a modern kernel's entry point:** a probe stub placed at the entry point never ran.
- **`go` works.** At `go`:
  - The CPU is in SVC mode with IRQ and FIQ masked.
  - The MMU and D-cache are off, the I-cache is on, and L2 is off.
  - It runs on CPU0, with CPUs 1–3 held in reset (reset registers read `0x101`).
  - VBAR is 0.
- **Stop USB before `go`.** `go` doesn't stop USB the way `bootm` does. The EHCI controllers keep doing DMA through U-Boot's data structures, so anything that overwrites RAM must run `usb stop` first, and ideally halt all three EHCI controllers.
- **8 MB unpack limit:** `bootm` on a gzip image over 8 MB fails with `inflate() returned -5` and "must RESET". An image built with `mkimage -T standalone -C gzip`, with `autostart=no` set, turns `bootm` into a plain unpacker that returns to the prompt. The main guide loads the kernel in pieces this way.
- **Slow USB reads:** reading from the eUSB stick is slow, about 27 MB in 2 minutes.

### Useful and dangerous commands

| Command | Notes |
|---|---|
| `pmu <string>` | Sends a raw string to the PMU |
| `pv <char> [val]` | Gets or sets a PMU variable (which character means what isn't worked out) |
| `gpio s\|c\|r <0-66>` | Sets, clears or reads a SoC GPIO |
| `temp` | SoC junction temperature |
| `i2c probe`, `i2c md` | Read-only probing works |
| `sf probe 0; sf read …` | Reads the SPI flash, for example to check CRCs |
| `td_eusb_boot[_lx]`, `td_rootfs_select`, `td_bootargs_confab`, `bootvx`, `amp_boot` | Drobo's boot commands |
| `td_debug` | Its help text gives the password `qwer` and calls it "esoteric" |
| `rcvr` | Marvell recovery: loads an image from USB or TFTP and boots it with `root=/dev/ram0` |
| **`flinfo`** | **Crashes**: a data abort at `0xDD000000` while listing NOR chip 2. It leaves the board powered off. Don't run it. |
| **`bubt`** | Writes U-Boot to the SPI flash. Avoid it unless you mean to. |

`tftpboot` works, at about 2 MB/s, with `ipaddr` and `serverip` set in RAM.

### `ENVY.bin`: changing the environment without a console

On every boot, before the autoboot check, U-Boot runs `usb start` and `fatload usb 0 2000000 ENVY.bin` (the file name comes from `vxEnvUpdateFile`). The file is plain text with one command per line:

```
set NAME VALUE
set NAME 'value with spaces'
```

For each line, U-Boot prints either "already … Not Changing" or "Changing Value from … to …", and saves the environment if anything changed. Unchanged values aren't written again, so leaving the file in place does no harm. This only works while the stick is FAT; on a Debian stick formatted ext2 the step quietly fails. The format comes from disassembling `UBSW.bin` (the code around `0x623AC8`, with its parser at `0x622E94`), and it matches what the boot log prints.

## Booting mainline Linux: what it took

The main guide's [How it works](../README.md#12-how-it-works) has the summary. Here are the dead ends, for anyone trying a different route:

1. **`bootm`:** a uImage, with and without AMP. The kernel never starts.
2. **`go` to a zImage with an appended DTB:** silent. Debian's multi-platform kernel can't print from its decompressor (`DEBUG_UNCOMPRESS` isn't available there).
3. **A copy trampoline:** it hung partway through the copy. Halting USB fixed that, and then the stub's own debug printing clobbered the copy loop's registers (our bug).
4. **Writes near `0x600000`:** they hang the board (see [hardware.md](hardware.md#memory-map-quirks)). Running the kernel from `0x01208000` fixed it.
5. **Where the Image goes:** Debian's armmp kernel is linked with `TEXT_OFFSET` `0x208000`, so the uncompressed Image must sit at RAM + `0x208000` (plus 16 MB here), not RAM + `0x8000`.
6. **Early debug output:** use `DEBUG_MVEBU_UART0` (`0xD0012000`), not the `_ALTERNATE` option, which assumes mainline's `0xF1012000`.
