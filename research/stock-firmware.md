# Stock firmware (Drobo OS 4.1.4)

How Drobo's own firmware is laid out, as seen on one unit running 4.1.4. None of the binaries are included here.

## The eUSB stick

About 981 MB, MBR, one FAT16 partition starting at sector 8064, marked active. Files:

| File | What it is |
|---|---|
| `vxWorks`, `vxWorks_prev` | VxWorks 6.8 kernel plus Drobo's board support (ELF32 ARM, **unstripped**, about 7.3 MB each). This is the storage side. Its `bsp*` symbols explain much of the hardware. |
| `runesa.vxe`, `runesa_prev.vxe` | The BeyondRAID application, a vxWorks RTP (about 10.5 MB each) |
| `LxImage`, `LxImage_prev` | A u-boot legacy uImage of **Linux 3.2.96** (uncompressed, about 3.1 MB, load and entry at `0x20008000`) for the Linux side |
| `UBSW.bin` | A copy of U-Boot (Marvell `kwbimage`, SPI boot type `0x5A`), used for bootloader updates |
| `hdr`, `LxHdr`, `rtphdr`, `UBSW.hdr` (and `_prev`) | 556-byte headers that pair with each image, probably version and checksum |
| `ENVY.bin` (when present) | U-Boot environment updates, applied at boot. See [consoles-and-u-boot.md](consoles-and-u-boot.md#envybin-changing-the-environment-without-a-console). |
| `FlashConfigData`, `RebootReason`, `NoCrashMsg`, `bootCount`, `hibHdr`, `hibLog`, `amitLog`, `eventLog`, `Crashlogs/` | State and logs written by the firmware |

Every image has a `_prev` twin, an A/B scheme for rolling back a failed update. On another unit, a bad `LxImage` was reportedly fixed by copying `LxImage_prev` and `LxHdr_prev` over the current files.

## AMP: two operating systems on one chip

With `amp_enable=yes`, U-Boot's `amp_boot` starts **vxWorks on CPU0** (storage, BeyondRAID, bays, fan, I²C, SATA) and **Linux 3.2.96 on CPUs 1–3** (network services and DroboApps). The two talk through shared memory. The resource split appears in the boot logs:

- vxWorks: `uart0 - pex1 - pex2 - eth1 - xor0 - usb0 - i2c0 - i2c1 - sata - spi - gpio`
- Linux: `uart1 - eth0 - xor1 - spi - mstr`, about 1.5 GB of RAM

The stock Linux kernel command line (MAC and IPs left out):

```
rootfstype=jffs2 root=/dev/mtdblock2 rw
mtdparts=cfi_flash_0:1m@0m(nor_hdr),3m@1m(var),30m@4m(rfs0),30m@34m(rfs1)
mv_cpu_count=3 mv_rsrc=uart1:eth0:spi:mstr mv_sh_mem=0x80000000:0x100000 mv_ipc=0
vmalloc=550M console=ttyS0,115200 pm_level=off
```

## NOR layout

NOR chip 0 (64 MB) holds:
- `nor_hdr`, 1 MB
- `var`, 3 MB, jffs2, mounted read-write as `/var`
- two 30 MB root filesystems, `rfs0` and `rfs1` (A/B), jffs2

The live root is mounted read-only, with `/bin` and `/etc` as aufs overlays on tmpfs. NOR chip 1 shows up to Linux as `mtd4` (64 MB, almost all empty). U-Boot itself is in the SPI flash, which the stock Linux doesn't expose.

To back the NOR up from the stock Linux shell, stream each `/dev/mtdN` over the network (`cat /dev/mtdN | nc <host> 9000`) and compare `sha1sum` on both ends. For the SPI flash, `sf read` plus U-Boot's `crc32` lets you check a dump made any other way.

## Network services

- Out of the box the unit may have a static address left by its previous owner, and won't ask for DHCP. Look at the boot log or the U-Boot environment to find it.
- Only TCP **5000** and **5001** are open: Drobo Dashboard's `nasd`. Port 5000 sends an `ESATMUpdate` XML status blob unprompted, with the firmware version, serial number, disk and DroboApps state.
- There's no SSH; DroboApps, Dropbear included, live on the disk pack.
- The stock Linux also starts avahi, syslog, ntpd and a "recovery server" (`nc` on UDP 4999).
- The stock Linux console on `JP3` ends at a root shell with no password. It's useful for backing up the NOR before you replace anything.
