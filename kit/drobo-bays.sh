#!/bin/sh
# drobo-bays [on|off|status] : power the Drobo 5N bays that hold a disk, a few seconds apart.
# Uses the expander lines named in the device tree (bayN-power, bayN-detect; detect is low = present).
GAP=${GAP:-4}
cmd=${1:-on}
i=0  # at boot the expander driver may still be probing
until gpioinfo bay0-detect >/dev/null 2>&1; do
  i=$((i + 1)); [ $i -gt 20 ] && { echo "drobo-bays: no bay GPIO lines (device tree or gpio-pca953x missing?)" >&2; exit 1; }
  sleep 0.5
done
for b in 0 1 2 3 4; do
  present=no
  [ "$(gpioget --numeric "bay$b-detect" 2>/dev/null)" = 0 ] && present=yes
  case "$cmd" in
    status) echo "bay $b: disk=$present power=$(gpioget --numeric --as-is "bay$b-power" 2>/dev/null || echo ?)" ;;
    off) gpioset -t0 "bay$b-power=0" ;;
    on)
      if [ $present = yes ]; then
        echo "bay $b: disk present, powering on"; gpioset -t0 "bay$b-power=1"; sleep "$GAP"
      fi ;;
  esac
done
