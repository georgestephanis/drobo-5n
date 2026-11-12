#!/bin/sh
# drobo-bays [on|off|status|watch] : power the Drobo 5N bays that hold a disk, a few seconds apart.
#   watch: keep running, power a bay when a disk is pushed in, and switch it off when one is pulled out
#          (drobo-bays-watch.service). It only acts on a change, so a bay switched off on purpose stays off.
# Uses the expander lines named in the device tree (bayN-power, bayN-detect; detect is low = present).
GAP=${GAP:-4}
cmd=${1:-on}
i=0  # at boot the expander driver may still be probing
until gpioinfo bay0-detect >/dev/null 2>&1; do
  i=$((i + 1)); [ $i -gt 20 ] && { echo "drobo-bays: no bay GPIO lines (device tree or gpio-pca953x missing?)" >&2; exit 1; }
  sleep 0.5
done
if [ "$cmd" = watch ]; then
  POLL=${POLL:-2}
  while :; do
    for b in 0 1 2 3 4; do
      now=no
      [ "$(gpioget --numeric "bay$b-detect" 2>/dev/null)" = 0 ] && now=yes
      eval "was=\${seen_$b:-}"
      if [ -n "$was" ] && [ "$was" != "$now" ]; then
        if [ $now = yes ]; then
          sleep 1   # let the disk seat
          echo "bay $b: disk inserted, powering on"; gpioset -t0 "bay$b-power=1"
        else
          echo "bay $b: disk removed, powering off"; gpioset -t0 "bay$b-power=0"
        fi
      fi
      eval "seen_$b=$now"
    done
    sleep "$POLL"
  done
fi

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
