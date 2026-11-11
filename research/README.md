# Drobo 5N research notes

What we learned about the Drobo 5N's hardware, firmware and boot process while getting Debian onto it. It's written for anyone else poking at this board, whether or not they want Debian. Everything here was observed on one unit: mainboard `100-00041-001 Rev 3.0`, with stock firmware 4.1.4 installed.

| File | What's in it |
|---|---|
| [hardware.md](hardware.md) | Chips, memory, flash, PCIe/SATA layout, headers (populated and not), power |
| [consoles-and-u-boot.md](consoles-and-u-boot.md) | The two serial headers, U-Boot 2009.08's quirks, `amp_enable`, the `ENVY.bin` env-update file, what does and doesn't boot a modern kernel |
| [pmu-protocol.md](pmu-protocol.md) | The separate power/fan microcontroller: UART, commands, the fan's behaviour |
| [backplane-and-bays.md](backplane-and-bays.md) | The two PCA9555 expanders, bay power/detect/LED bits, bay-to-SATA-port map, mSATA power |
| [front-panel-leds.md](front-panel-leds.md) | Bay, power, activity and capacity-gauge LEDs: chips, pins, colours, bit order |
| [firmware.md](firmware.md) | Archived official download links (firmware 4.2.1) and SHA-256 checksums of the 4.1.4 image files |
| [stock-firmware.md](stock-firmware.md) | How Drobo OS is laid out: eUSB stick files, the vxWorks/Linux AMP split, NOR partitions, network services |
| [tools/](tools/) | Bench tools: a two-channel Pico UART bridge with power-gated TX and an optocoupler "button press", and a bay-mapping script |

A few things are left out on purpose:
- **Drobo's firmware binaries** (`vxWorks`, `UBSW.bin`, the NOR and SPI dumps). They're not ours to redistribute; [firmware.md](firmware.md) links archived official copies and gives checksums instead.
- **Unit-specific identifiers:** serial numbers and MAC addresses. Example values use `00:1a:62:12:34:56`.

Confidence varies, and each note says which: "confirmed" means measured or exercised on the board; "likely" or "probably" means inferred from photos, strings or disassembly. Corrections are welcome as issues.
