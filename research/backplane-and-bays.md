# Backplane and drive bays

The backplane (`200-00023-005`, "Bumblebee & Shockwave BP V4.0") carries:
- straight-through SATA connectors
- a separate power switch for each bay
- the fan header `J7`
- two **PCA9555-style 16-bit I²C expanders** on the SoC's TWSI1 bus (`i2c@11100`): `0x20` for the bay LEDs, and `0x23` for bay power and presence

The expanders only answer while the backplane is connected.

## How the bit layout was found

The stock `vxWorks` image on the eUSB stick is an unstripped ELF with symbols:
- `bspConfigureI2CTable` checks `bspCheckPlatformIs5Slot` (true for Bumblebee, Shockwave, Baymax and Bev), then copies `I2CExpanderTableBumblebee` and `slotToI2CExpanderTableBumblebee`.
- Each expander entry is one word: byte 0 is the I²C address, byte 2 the port, byte 3 the TWSI channel. The four entries are `0x01000020`, `0x01010020`, `0x01000023` and `0x01010023`.
- The register table (input, output, polarity and config at 0/2/4/6 for port 0, and 1/3/5/7 for port 1) is the PCA9555 layout.
- At boot, vxWorks writes 0 to every output register, so all bays start off. It makes only the detect bits inputs, then switches power with a read-modify-write.

All of this has been confirmed on hardware under Linux: detect, power and SATA link for every bay.

## Bay map

Bays are numbered 0 (top) to 4 (bottom). Power is **active-high**; detect is **active-low** (0 means a disk is present).

| Bay | LEDs (`0x20`) | Power (`0x23` port 0 output, reg `0x02`) | Detect (`0x23` port 1 input, reg `0x01`) | SATA |
|---|---|---|---|---|
| 0 (top) | port 0 bits 6, 7 | bit 3 (`0x08`) | bit 3 | SoC `sata_mv` (`d00a0000.sata`) port 0, 3 Gbps |
| 1 | port 0 bits 4, 5 | bit 2 (`0x04`) | bit 2 | 88SE9122 on `pcie@9,0` (02:00.0) port 0, 6 Gbps |
| 2 | port 0 bits 2, 3 | bit 1 (`0x02`) | bit 1 | 88SE9122 on `pcie@9,0` port 1, 6 Gbps |
| 3 | port 0 bits 0, 1 | bit 0 (`0x01`) | bit 0 | 88SE9122 on `pcie@8,0` (01:00.0) port 0, 6 Gbps |
| 4 (bottom) | port 1 bits 0, 1 | bit 4 (`0x10`) | bit 4 | 88SE9122 on `pcie@8,0` port 1, 6 Gbps |
| mSATA | none | **SoC GPIO 66** (bank 2, mask `0x04`; set at `0xD00181AC`, clear at `0xD00181B0`) | none | probably SoC `sata_mv` port 1 (untested) |

Register state at power-on: `0x20` reads `ff 03 ff 03 00 00 00 00` (registers 0–7), and `0x23` reads `e0 ff ff ff 00 00 ff ff`.

Linux's `ataN` numbers change with probe order (they moved when the device tree changed), so the table leaves them out. Identify a bay by its controller and port, never by `ataN` or `sdX`.

## Using it from Linux

With the device tree fixups in [`kit/drobo5n-fixups.dtsi`](../kit/drobo5n-fixups.dtsi), `gpio-pca953x` binds both chips and the lines get names (`bay0-power`, `bay0-detect`, `bay0-led-a` and so on), so libgpiod can find them by name:

```sh
gpioget --numeric bay2-detect     # 0 = disk present
gpioset -t0 bay2-power=1          # spin it up; the SATA link comes up about 5 s later
```

Without the fixups, raw I²C works too (with `i2c-tools`). For bay 2, `i2cget -y 1 0x23 1` reads detect. To power it on, clear bit 1 of config register `0x06`: the pin becomes an output and drives its default, high. [`tools/baymap.sh`](tools/baymap.sh) powers each bay in turn and reports which SATA port its disk appears on.

Disks from a Drobo disk pack (BeyondRAID) can't be read as ordinary filesystems; `blkid` finds nothing on them.

Each bay has two LED lines: `a` is green, `b` is red, both together yellow, and high lights them. See [front-panel-leds.md](front-panel-leds.md).
