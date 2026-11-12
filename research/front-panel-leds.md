# Front-panel LEDs

Mapped on 2026-11-11 under Debian, by driving each line and watching the panel, then checked against the stock firmware's own LED code (`vxWorks` keeps its symbols: `bspSetPowerLED`, `bspSetHDActivity`, `bspBlueLedCharWrite`, `bspSetCapacityLEDs`).

The faceplate's label gives Drobo's meanings: green = healthy, yellow = add a drive soon, blinking green/yellow = don't remove, red = add a drive now, blinking red = drive failed.

The kit's `drobo-leds` uses its own meanings for the bay lights. They follow Drobo's where they can: green/yellow is "don't remove", and red is "do something".

| Bay light | Meaning |
|---|---|
| Off | Empty, or the bay is switched off: nothing holds the disk, so it's safe to pull. Trust this only while the power light is green: at boot, before `drobo-leds` starts, every bay is dark. |
| Yellow | The disk has power but nothing has it mounted. Use Safely remove (or switch the bay off) before pulling it. |
| Green | In use: a filesystem on it is mounted, or md, LVM or dm-crypt holds it. |
| Green and red, alternating | In use, and a filesystem on it is at least 95% full (`FULL_PCT`). |
| Green and yellow, alternating | Busy, don't remove: a program has the disk open (a filesystem check, `dd`, a SMART test), or an md array it's in is syncing. |
| Red | Needs attention, with the reason in `/run/drobo-leds/attention-bays`. The dashboard marks a disk that won't mount and one with reallocated, pending or uncorrectable sectors. |
| Blinking red | Failed: the disk failed its SMART health check (`/run/drobo-leds/failed-bays`). |

`drobo-leds status` prints what each bay shows and why, and the dashboard shows the same reason on the bay's page.

## Summary

| LED | Driven by | Colours |
|---|---|---|
| Bay 0 (top) to bay 4 (bottom) | PCA9555 at `0x20`, TWSI1 (`i2c@11100`, Linux `i2c-1`) | line A green, line B red, both yellow |
| Power | SoC GPIO 28 (red), GPIO 35 (green) | both = yellow |
| Activity | SoC GPIO 26 | green |
| Capacity gauge, 10 blue | PCA9555 at `0x21`, TWSI0 (`i2c@11000`, Linux `i2c-0`) | on/off only |

All of them are active-high: a line driven high lights its LED.

## Bay LEDs (`0x20`)

| Bay | Green (A) | Red (B) |
|---|---|---|
| 0 (top) | port 0 bit 6 | port 0 bit 7 |
| 1 | port 0 bit 4 | port 0 bit 5 |
| 2 | port 0 bit 2 | port 0 bit 3 |
| 3 | port 0 bit 0 | port 0 bit 1 |
| 4 (bottom) | port 1 bit 0 | port 1 bit 1 |

Port 1 bits 2–7 (lines 10–15) were driven one at a time and nothing on the front panel changed.

At power-on, before anything drives them, this unit's chip came up with every line an output and the LED lines high, so all five bays lit (orange on the top two, yellow on the rest). With the [kit's device tree fixups](../kit/drobo5n-fixups.dtsi) the lines are named `bayN-led-a` and `bayN-led-b`:

```sh
gpioset -t0 bay2-led-a=1 bay2-led-b=0     # bay 2 green
```

## Power and activity (SoC GPIOs)

`bspSetPowerLED(n)` sets GPIO 28 to bit 0 of `n` and GPIO 35 to bit 1, using the GPIO set/clear registers (`0xD0018130`/`0xD0018134` for bank 0, `+0x40` for bank 1). `bspResetPowerLED` calls it with 3, both colours. `bspSetHDActivity` drives GPIO 26.

When Linux boots, these pins are inputs, and the board's pull-ups light both LEDs (power yellow, activity green). Requesting them as outputs low turns them off:

```sh
gpioset -t0 -c gpiochip0 28=0; gpioset -t0 -c gpiochip1 3=1    # power green (GPIO 35 is bank 1 line 3)
gpioset -t0 -c gpiochip0 26=1                                   # activity on
```

Other single-pin outputs in the same firmware: `bspSBReq` drives GPIO 30, `bspPsramBattery` GPIO 31, and `bspSetDrivesPowered` GPIO 66 (the pin this project first named `msata-power`).

## Capacity gauge (`0x21`)

The firmware's 5-bay I²C table puts the `0x20`/`0x23` expanders on TWSI channel 1. The blue LEDs are written separately, on channel 0, at the address `mvBoardBlueLedTwsiAddrGet` returns (`0x21`). At start-up, `bspBlueLedI2CInit` writes 0 to registers 2, 3, 6 and 7 (all outputs, all off).

There's no device-tree node for it, so it's driven with `i2cset` from `i2c-tools` or busybox (`busybox i2cset`), with `i2c-dev` loaded. The ten LEDs use outputs 0–9; `bspBlueLedMap` gives their order, confirmed on the panel:

| LED, left to right | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---|---|---|---|---|---|---|---|---|---|
| Output bit | 0 | 4 | 1 | 5 | 2 | 6 | 3 | 7 | 8 | 9 |

```sh
i2cset -y 0 0x21 6 0; i2cset -y 0 0x21 7 0     # all outputs
i2cset -y 0 0x21 2 0xff; i2cset -y 0 0x21 3 0x03   # all ten on
```

Bits 10–15 aren't connected to anything visible. On this unit the two leftmost LEDs are noticeably dimmer than the rest. The PCA9555 has no PWM, so brightness can't be set.

Notes on probing: an `i2cget` read of register 8 or above fails, and a write to register 1 (the read-only input port) is refused; neither means the chip is missing. Bus `i2c-0` also has devices at `0x50` (the boot EEPROM) and `0x6e` (a clock generator); see [hardware.md](hardware.md#ic).

## A status service

[`kit/drobo-leds.sh`](../kit/drobo-leds.sh) is a small shell service that drives all of these from system state: bays from presence and power, power from `systemctl is-system-running`, activity from `/proc/diskstats`, and the gauge from how full the bay disks' filesystems are (internal storage isn't counted).
