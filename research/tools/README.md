# Research tools

Small scripts used while working things out. They aren't part of the kit and aren't installed by it.

- `drobo-powertest.sh`: logs the PMU's power-fail line (SoC GPIO 32) and the PMU's `Y`/`B` info once a second, syncing every line so the record survives a power cut. It only reads. This is what recorded the 11 Nov pull-the-plug test in [../pmu-protocol.md](../pmu-protocol.md#the-backup-battery-and-power-failure). Run it as root, pull the plug, and read `/var/log/powertest.log` after the next boot.
