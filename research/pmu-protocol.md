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

The fan commands come from vxWorks' `pmuFanDefault`, `pmuFanTarget` and `pmuFanActual`. Strings in the firmware name more PMU variables, which U-Boot's `pv <char>` can reach:
- CPU, disk and PMU temperatures, each with a set point and an over-temperature threshold
- "Fake Disk Temp" and "OverTemp Disable"
- Power Good Delay and Power Bounce Delay
- a boot-on-battery counter
- AC/DC voltage

Which character selects which variable isn't mapped yet. vxWorks' `bspThermalMonitor` logs "PMU is Signalling OVERTEMP".

Only one program should talk to the port at a time; two at once got jumbled replies. [`drobo-pmu.sh`](../kit/drobo-pmu.sh) takes a lock before it sends anything.

## Fan behaviour without Drobo OS

- With nothing talking to it, the PMU sits at its default of level 01, and the stock fan only twitches.
- A single `FT31` doesn't last. The PMU steps the target down by itself, roughly once a minute (31 → 25 → 18 … → 01), so something has to resend the target. Every 30 s is enough. [`drobo-fand.sh`](../kit/drobo-fand.sh) does this, setting the level from the SoC and disk temperatures.
- Under U-Boot nothing sets the fan, so it stays off until Linux starts.
- The stock fan is a Sunon MagLev MEC0251V3-0000-A99 (12 V, 1.9 W, two wires). It could stall at low levels and sometimes needed a nudge to start, so the guide uses a floor of 16.

## Power button

- Under vxWorks, one press logs `ISR: PMU PowerDownReq` and starts a clean shutdown. The LED blinks in threes, speeds up, then goes out.
- **Under mainline Linux the button is ignored**, probably because nothing answers the PMU the way vxWorks does. A Linux `poweroff` ends with "Power off not available: System halted instead", so the power has to be removed by hand. The shutdown handshake hasn't been worked out.
- From standby, a short press (300 ms) powers the board on.
