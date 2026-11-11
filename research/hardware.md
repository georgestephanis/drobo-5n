# Hardware

Mainboard `100-00041-001 Rev 3.0` (assembly `200-00038-005`). Firmware strings show the board design and firmware are shared across the 5N, 5N2, 5C, 5D, 5D3, 8D and DroboElite. Internally the board is "Shockwave", and its backplane is "Bumblebee & Shockwave BP V4.0".

## Main parts

| Part | Details | Confidence |
|---|---|---|
| SoC | Marvell Armada XP **MV78460** (package marked `MV78-BJR2 … MW78460-B0`; U-Boot reports `MV88F7846`). 4× PJ4B ARMv7 cores at 1.2 GHz, L2 at 600 MHz, TCLK 250 MHz. Supported by mainline Linux (`mvebu`). | confirmed |
| RAM | 2 GB DDR3, 64-bit, no ECC (4× SK hynix `H5TQ4G63MFR`), one bank at `0x0` | confirmed |
| SPI flash | Macronix **MX25L6405D**, 8 MB. Holds U-Boot (a Marvell `kwbimage` for SPI boot) and its environment at `0x0D0000`. A Drobo region with a `TDF` header sits at `0x200000`–`0x2CFFFF`. | confirmed |
| NOR flash | **Two** Spansion S29GL512-class chips, 64 MB each (CFI), at `0xD8000000` and `0xDC000000`. Chip 0 holds the stock Linux side; chip 1 is almost all erased. | confirmed |
| eUSB stick | Apacer "USB DISK MODULE" (`1005:b155`), about 981 MB, USB 2.0, plugged into a **2.0 mm-pitch** internal USB header. Stock: FAT16 holding the vxWorks firmware. To image it on a PC, a standard USB cable re-terminated with 2.0 mm crimp pins plugs straight in. | confirmed |
| Ethernet | One port: `mvneta` (`ethernet@70000`) with a Marvell **88E1318S** PHY at MDIO address 0, 1 Gbps. The reference board's other three ports aren't wired. | confirmed |
| SATA | 5 bays plus mSATA. Two Marvell **88SE9122** AHCI controllers (PCI ID `1b4b:9122`; one package was read as `88SE9120-NAA2` from a photo), 2 ports each at 6 Gbps, on `pcie@8,0` (U-Boot's PEX 1.3) and `pcie@9,0` (PEX 2.0). The SoC's own `sata_mv` has two ports: one is the top bay (3 Gbps), and the other is probably the mSATA slot. | confirmed, except mSATA |
| Temperature | The SoC thermal sensor (`armada_thermal`) works, and `drivetemp` reads disk temperatures under Linux. An LM75B-style part is visible on the board but hasn't been found on a bus yet. | confirmed / likely |
| Power controller (PMU) | A separate microcontroller on standby power for the power button, fan and temperatures. See [pmu-protocol.md](pmu-protocol.md). | confirmed |
| Pinhole button | A tactile switch beside the power socket, pressed with a pin through a small hole in the case: **SoC GPIO 65**, active-low (bit 1 of the register at `0xD0018190`). U-Boot has Marvell's recovery feature (`rcvr`, `enaAutoRecovery`), which this button probably triggers at power-on. That hasn't been tried. | GPIO confirmed |

## Memory map quirks

- This U-Boot leaves the internal registers at **`0xD0000000`**. Mainline device trees expect `0xF1000000`, so the DT has to say `0xD0000000` (the ReadyNAS RN2120 tree does the same).
- **Writing to RAM below 16 MB hangs the board shortly afterwards.** Even rewriting the word already at `0x600000` (U-Boot's vector table) does it, while `0x600100` is fine. The cause is unknown: no debug watchpoints are set, no CPU is in the coherency fabric, and no address window covers it. The workaround is to run Linux from `0x01208000` and leave the first 16 MB unused; Debian's kernel is relocatable.
- Of the reference board's 8 PCIe ports, only `pcie@8,0` and `pcie@9,0` have devices. With the others enabled in the DT, the kernel hung while probing them.

## I²C

| Bus | Address | Notes |
|---|---|---|
| TWSI0 (`0x11000`) | `0x21` | Registers 0–7 read `00`. Unidentified; possibly an expander. |
| | `0x50` | EEPROM-like. From U-Boot it starts `01 80 10 11 11 11 41 d0 00 14 00 72 00 c4`, then `ff`. Under Linux with a 1-byte pointer it reads `ff`. Probably board ID or config. |
| | `0x6E` | Answers `07` for most registers. Unidentified. |
| TWSI1 (`0x11100`) | `0x20`, `0x23` | PCA9555 expanders on the **backplane**, only present when it's connected. See [backplane-and-bays.md](backplane-and-bays.md). |

## Headers and footprints

| Ref | What | Status |
|---|---|---|
| `JP2` (front, 4-pin) | U-Boot and vxWorks console (UART0). Its `J3` jumper must be seated or input is lost. See [consoles-and-u-boot.md](consoles-and-u-boot.md). | populated, confirmed |
| `JP3` (rear, 4-pin) | Stock Linux console (UART1) | footprint; solder a header on |
| Front-panel cable | 3 wires: red, white and blue. Red drives the button's LED (up to about 1.8 V, a ramping blink). White and blue idle near 3.17 V on standby, and the button joins them. **Neither is ground.** An optocoupler across white (collector) and blue (emitter) gives a clean remote "press". | confirmed |
| `J7` (backplane) | Fan header: 3 pins, of which the stock fan uses 2 (power and ground). No tach or PWM: the PMU varies the supply voltage. | confirmed |
| `J19` (underside, 2×4 female, about 2.0 mm pitch) | Reachable by removing the mSATA cover, without opening the case. Unidentified. Candidates: a second internal USB header (the eUSB stick's header is also 2.0 mm female), PMU programming, a UART or test header. | populated, unidentified |
| `J6` (2×5) | Sized like an ARM JTAG header | footprint, unconfirmed |
| `J9`, `J10` | 9 signal pads plus shield tabs, the **USB 3.0 Type-A** pinout, next to an unpopulated BGA `U23` (probably a PCIe→USB 3 controller), crystal `Y6` and chokes `L3`–`L6`. Probably USB 3 ports used on another model; adding them would need BGA rework, not just connectors. | footprints, likely |
| `J15` (60-pin), `J13`/`S1` | Probably a mezzanine connector and its config switch for another model | footprints |

## Power

- Rated 12 V, 8.33 A (100 W) through a **6.5 × 3.0 mm barrel jack**, sized for five disks spinning up together. The board alone draws about 10–15 W. Each bay has its own power switch on the backplane, so software can stagger spin-up.
- Two apparent shutdowns on the bench first looked like overheating or back-powering through the serial adapter. Both turned out to be the bench setup: a bad ground crimp, and the stock Linux console's normal silence. Still, keep a fan on the heatsink with the case open, and never drive the board's RX pins while it's off. With airflow, the SoC idles between the high 30s and mid 40s °C.
