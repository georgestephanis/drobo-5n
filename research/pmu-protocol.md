# The power-management controller (PMU)

A separate microcontroller, on standby power, that owns the power button, the case fan and over-temperature shutdown. The SoC talks to it over **UART2** (`0xD0012200`, `ttyS2` under Linux) at **38400 8N1**. vxWorks opens it as `/tyCo/2`, and U-Boot sets the divisor to TCLK / 614400.

## Protocol

Commands are ASCII, each ending in a newline; without the newline there's no reply. Replies end in `!`. A trailing `.` queries a value.

| Command | Reply | Meaning |
|---|---|---|
| `V` | `1.0.0x307A` | Firmware version |
| `Y` | e.g. `0x0680,0:04.38,3,0,0` | Status. The first field reads `0x0680` under U-Boot after a cold start and `0x06C0` while mainline Linux runs; other values were seen in odd states. The timer-like field isn't decoded. |
| `FT.` | `FT16!` | Fan **target**, 01–31 |
| `FTnn` | `FTnn!` | Sets the fan target. Use **two decimal digits** (`FT31`); hex such as `FT1f` is ignored. |
| `FA.` | `FA24!` | Fan **actual** level. It ramps towards the target over a few seconds. |
| `FD.` | `FD01!` | Fan default |
| `K` | `k` | "CPU awake" (vxWorks' `pmuCpuAwake`, sent once from `usrAppInit2`). Until the PMU hears it, a press of the power button cuts power at once. |
| `S` | `s` | Shut down now (`pmuShutdownNow`): the PMU cuts main power about a second later. |
| `A` | — | Abort a shutdown (`pmuShutdownAbort`, used by `bspHibernate` and the thermal code). Not tried. |

The fan commands come from vxWorks' `pmuFanDefault`, `pmuFanTarget` and `pmuFanActual`. Strings in the firmware name more PMU variables, which U-Boot's `pv <char>` can reach:
- CPU, disk and PMU temperatures, each with a set point and an over-temperature threshold
- "Fake Disk Temp" and "OverTemp Disable"
- Power Good Delay and Power Bounce Delay
- a boot-on-battery counter
- AC/DC voltage

Which name selects which variable is mapped in [the variable map](#the-variable-map) below. vxWorks' `bspThermalMonitor` logs "PMU is Signalling OVERTEMP".

Only one program should talk to the port at a time; two at once got jumbled replies. [`drobo-pmu.sh`](../kit/drobo-pmu.sh) takes a lock before it sends anything.

## Fan behaviour without Drobo OS

- With nothing talking to it, the PMU sits at its default of level 01, and the stock fan only twitches.
- A single `FT31` doesn't last. The PMU steps the target down by itself, roughly once a minute (31 → 25 → 18 … → 01), so something has to resend the target. Every 30 s is enough. [`drobo-fand.sh`](../kit/drobo-fand.sh) does this, setting the level from the SoC and disk temperatures.
- Under U-Boot nothing sets the fan, so it stays off until Linux starts.
- The stock fan is a Sunon MagLev MEC0251V3-0000-A99 (12 V, 1.9 W, two wires). It could stall at low levels and sometimes needed a nudge to start, so the guide uses a floor of 16.

## Power button

- Under vxWorks, one press logs `ISR: PMU PowerDownReq` and starts a clean shutdown. The LED blinks in threes, speeds up, then goes out.
- The request is **SoC GPIO 40** (`bspIsPowerDownReq` reads GPIO bank 1, mask `0x100`; it's `gpiochip1` line 8 under Linux). It goes high on a press, but **only after the CPU has sent `K`**. Before that, a press makes the PMU cut power immediately. That was seen under Linux on 11 Nov: an instant power cut with the root mounted.
- The kit's `drobo-powerbtn` service sends `K` at boot, waits for GPIO 40 to rise, and runs `systemctl poweroff`. A systemd shutdown hook sends `S` once the filesystems are read-only. Tested on 11 Nov: one short press gives a red power LED, a clean shutdown, and power off in about 5 s. Linux's own power-off still says "Power off not available"; the hook cuts power before it gets there.
- From standby, a short press (300 ms) powers the board on.

## Reading PMU variables: voltages, flags, sensors

The stock firmware's `pmuVarDo` reads a variable in two steps: `X<name>` selects it (reply `X<name>!`), then a one-character operation plus `.` reads it. `=.` reads in decimal, `@.` in hex, and `~.+` reads and steps to the next entry. These only read. Both steps must go in one `drobo-pmu` call, so the lock stops anything else from changing the selection in between. Values seen on 11 Nov:

| Select, then read | Reply | Meaning |
|---|---|---|
| `Xa` `=.` | `=11965!` | Input voltage in mV (the firmware calls it "AC"; on the 5N it's the 12 V barrel input) |
| `Xv` `=.` | `=4159!` | Backup battery voltage in mV: one 18650 cell, here fully charged |
| `XX` `@.` | `@603!` | System flags. `0x40` set means running on battery (`pmuPowerTypeCheck`: "PMU is Running on DC Power") |
| `XR` `~.+` ×5 | `~21!` `~21!` `~21!` `~22!` `~26!` | Raw temperature sensors in °C. vxWorks reads five and labels them as slots. |
| `XCp` `=.` | `=45!` | PMU core temperature |

Reading `Xa` alone (or `a.`) returns no value, which looks like "not supported" but isn't.

Info requests, also read-only:
- `Y` gives the state flags and PMU uptime (minutes:seconds). The flags were `0x0680` before the CPU had sent `K` and `0x06C0` after, so `0x40` there is "CPU awake", not battery.
- `B` gives battery-backup statistics, six numbers (`18,0,0,0,0,1`; after a power cut the first went back to 1).
- `R` gives the shutdown reason (`0x0000,--`).
- `D` gives temperatures: maximum and average disk, fan, sampling period, five slots, CPU and PMU.

### The variable map

The names come from vxWorks' callers of `pmuGet`, `pmuSet` and `pmuVarDo`, each of which loads a name string first. The second argument is an index into `pOpList` (`=@~x`), so vxWorks reads most of these with `@.` (hex). Every value is 16 bits. A scan of every letter on 11 Nov (select, then `=.`) found these 21 single-letter names: `a b d e f g h o r s t v w x z B D L R X Y`. Any other letter is acknowledged but its read returns `#?`. The scan only read; nothing was set.

| Name | vxWorks function | Value on 11 Nov | Reading |
|---|---|---|---|
| `a` | `pmuVoltageGet` | 11965 | Input, mV |
| `v` | `pmuVoltageGet` | 4155 | Battery, mV |
| `b` | `vxUtilAMIT` ("PowerBounceDelay was: %d ms") | 3000 | Power bounce delay, ms |
| `h` | `sysPmuInit` | 4000 | Hibernation delay, PMU ticks (U-Boot's `pv` list names it) |
| `L` | `pmuSnoozeDelay` | 1500 | Snooze delay |
| `s` | `pmuShutdownTimerRemaining` | `0xFFFF` | No shutdown countdown running |
| `o` | `pmuThermalAbort` | 0 | Over-temperature abort not triggered |
| `D` | `pmuThermalConfig` | 0 | Thermal config flags |
| `B` | `pmuSelfRefreshConfigCheck` | 3 | Boot-on-battery counter. The same function prints "This Optimus Prime Board Supports Self-Refresh", which is the battery-backed DRAM cache. |
| `w` | `pmuGenWordGet` / `Set` | `0x0103` | A general-purpose word vxWorks keeps in the PMU |
| `X` | `pmuSysFlagGet` | `0x1603` | System flags (`0x40` = on battery) |
| `R` | `pmuTempSensorGet` | | Raw temperature table, read with `~.+` |
| `Cd` `Cc` `Cp` | `pmuTempDisk`, `pmuTempCPU`, `pmuTempPMU` | `0x2865`, `0x3B0A`, `0x2D` | Disk, CPU and PMU temperatures |
| `Sd` `Sc` `Sp` | `pmuSetPoint…` | `0x3231`, `0x4650`, `0x4650` | Fan set points |
| `Td` `Tc` `Tp` | `pmuWarnThresh…` | `0x0637`, `0x2865`, `0x5307` | Over-temperature warning thresholds |

`Cp` reads 45, a plain °C value that matches `D`. For the other two-letter names, the high byte looks like °C: `Cc` 59 (the CPU while vxWorks still fed it), `Sc` and `Sp` 70, `Tp` 83 and `Sd` 50. `Td` (6) and `Tc` (40, the same raw value as `Cd`) don't fit that reading, so treat these as unconfirmed. vxWorks sets each of them with a matching `…Give` function (`pmuTempDiskGive` and so on). Debian sends none of them, so the PMU runs on its defaults and on whatever it last heard.

The letters `d e f g r t x z Y` answer as well (192, 0, 7680, 46798, 49, 0, 200, 0, 129) but no vxWorks function names them. By U-Boot's `pv` list they're probably Power Good Delay, Fake Disk Temp (`f` = `0x1E00`, 30 °C in the high byte), Temp Read Error, Final Countdown, Dram Valid Flag and the like.

## The backup battery and power failure

- The battery is a single 18650. Under vxWorks it backs a write cache in RAM. On power loss vxWorks logs "AC Failure ... Instigating Hibernation Mode". It saves the cached journals ("Stache Success --> Turning Battery OFF") or keeps the RAM alive on battery. At the next boot the PMU reports whether "the DRAM data was VALID at bootup".
- The PMU tells the CPU through GPIO interrupts, set up in `bspGPIOInterruptSetup`:

| SoC GPIO | Signal |
|---|---|
| 32 | Power failure ("PMU Panic"; goes low on power loss) |
| 40 | Power-down request |
| 57 | PMU over-temperature |
| 29 | Disk inserted |
| 65 | The "Clear Disk" button |

  GPIO 31 is an output that turns the battery backup on.
- **Tested under Linux on 11 Nov, with no disks in the bays.** Pulling the DC plug dropped GPIO 32 and raised GPIO 40 in the same millisecond. `drobo-powerbtn` took it as a button press and ran `poweroff`. Linux finished a clean shutdown on battery 3.5 s later, and the board went off. The PMU restarted too: its uptime and the first `B` counter reset. How long the battery can carry the board isn't measured yet. The logger used is [`tools/drobo-powertest.sh`](tools/drobo-powertest.sh). Since then `drobo-powerbtn` reads GPIO 32 when the shutdown request arrives and logs and broadcasts "power failed, running on battery" instead of "button pressed".
- `drobo-webui` 0.1.2 shows the input voltage, the battery voltage with a rough charge, the on-battery flag and the five sensors. It alerts on a low battery (under 3.6 V), a sagging input (under 11.4 V) and running on battery.
