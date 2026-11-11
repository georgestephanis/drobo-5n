# Stock firmware: where to get it, and checksums

This repository doesn't host Drobo's firmware. Drobo has stopped trading, but its firmware is still under copyright, and the largest part, vxWorks, belongs to Wind River. A dump of a used unit also carries that unit's MAC, serial number and its previous owner's settings. So here are pointers and checksums instead.

## Archived official downloads

Drobo's download pages are gone, but the Internet Archive kept the Drobo 5N support page and its files:

| What | Archived link |
|---|---|
| Drobo 5N support page (as of December 2023) | <https://web.archive.org/web/20231207190710/https://www.drobo.com/docs/start-drobo-5n/> |
| **Firmware 4.2.1** (the last 5N release, about 40 MB) | <https://web.archive.org/web/20231207190710/https://files.drobo.com/webrelease/5N/Drobo5N_Firmware_4-2-1.zip> |
| Firmware 4.2.1 release notes (PDF) | <https://web.archive.org/web/20231207190710/https://files.drobo.com/webrelease/5N/Release-Notes-firmware-5N-4.2.1.pdf> |
| Manually updating firmware (Dashboard help) | <https://web.archive.org/web/20231207190710/http://dashboardhelp.drobo.com/guide/250/en/ALL_Manually_Updating_Firmware_from_the_Website.htm> |
| Drobo Dashboard 3.6.1 for macOS / release notes | <https://web.archive.org/web/20231207190710/https://files.drobo.com/webrelease/dashboard/Drobo-Dashboard-3.6.1.dmg>, <https://web.archive.org/web/20231207190710/https://files.drobo.com/webrelease/dashboard/Release-Notes-dashboard-3.6.1.pdf> |

The Archive doesn't seem to have kept 4.1.4, the version on the unit this guide was tested on. Users online report that 4.3.1 was pulled by Drobo and recommend 4.2.1 instead. These links were checked in November 2026, and the files weren't downloaded or inspected. Nothing in the Debian guide needs any of them.

## Checksums from the test unit (firmware 4.1.4)

These are the image files from the eUSB stick of the unit this guide was tested on. They're the same files Drobo shipped, with no per-unit data (the state and log files on the stick aren't listed). On this unit each `_prev` file was identical to its current one.

| File | Size (bytes) | SHA-256 |
|---|---|---|
| `vxWorks` | 7,693,484 | `a1d9abe59d103816eaf97e238f1536be96b42b8e2e15c7fd85408cb5ad73e638` |
| `runesa.vxe` | 11,023,256 | `d69f04f473a21fa559b44af1930d40cc8e295b0154dbadaa194974f3c6f32084` |
| `LxImage` (Linux 3.2.96) | 3,199,640 | `62969f9a79fadccc2742a2a24ea8cca4935af9d4ab47001fecffecf623260101` |
| `UBSW.bin` (U-Boot `shockwave-uboot-2.18`) | 801,356 | `38b8365a7b9018ff3f4723a37100c7a39f9c32a6a75e657ea5ebe515cfcf274e` |

To compare your own copy: on macOS, `shasum -a 256 vxWorks runesa.vxe LxImage UBSW.bin`; on Linux, the same with `sha256sum`.

Before replacing Drobo OS, image your own unit's eUSB stick (step 5 of the guide does this). The firmware is easiest to keep that way, and it's what lets you go back.
