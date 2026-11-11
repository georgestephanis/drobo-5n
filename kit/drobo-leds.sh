#!/bin/sh
# drobo-leds: drive the Drobo 5N front panel from system state.
#
# Hardware (mapped 2026-11-11, see research/front-panel-leds.md in github.com/georgestephanis/drobo-5n):
#   bay LEDs   PCA9555 0x20 on i2c-1 (gpio-pca953x, lines bayN-led-a = green, bayN-led-b = red; both = yellow)
#   power LED  SoC GPIO 28 = red, GPIO 35 = green (both = yellow), active-high
#   activity   SoC GPIO 26 = green, active-high
#   gauge      PCA9555 0x21 on i2c-0 (no kernel driver), outputs 0-9, left to right: bits 0 4 1 5 2 6 3 7 8 9
#
# Meanings:
#   bay       off = empty, yellow = disk present but bay unpowered, green = disk powered,
#             blinking red = disk listed in $FAILED_FILE (one bay number per line)
#   power     green = running, yellow = starting or degraded, red = stopping / other
#   activity  blinks while any disk reads or writes
#   gauge     percent used of $GAUGE_PATHS (summed), in tenths
GAUGE_PATHS="/"
FAILED_FILE=/run/drobo-leds/failed-bays
POLL=0.5          # activity poll, seconds
SLOW_EVERY=10     # bays/power/gauge every SLOW_EVERY polls
[ -r /etc/default/drobo-leds ] && . /etc/default/drobo-leds

GAUGE_BUS=0 GAUGE_ADDR=0x21
I2CSET="busybox i2cset"   # busybox, so i2c-tools (which pulls in perl) is not needed
GAUGE_MAP="0 4 1 5 2 6 3 7 8 9"
mkdir -p /run/drobo-leds

# --- helpers ---------------------------------------------------------------
# blinkers: one background gpioset per LED that is blinking; pid kept in /run/drobo-leds/<name>.pid
stop_blink() {  # kill the blinker and wait for it, so its line is released before we request it again
  [ -f "/run/drobo-leds/$1.pid" ] || return 0
  p=$(cat "/run/drobo-leds/$1.pid"); rm -f "/run/drobo-leds/$1.pid"
  kill "$p" 2>/dev/null; wait "$p" 2>/dev/null
}
start_blink() { name=$1; shift; "$@" & echo $! > "/run/drobo-leds/$name.pid"; }

bay_set() {  # bay_set N off|green|yellow|red|red-blink
  n=$1 mode=$2
  eval "last=\$bay_last_$n"; [ "$last" = "$mode" ] && return
  eval "bay_last_$n=$mode"
  stop_blink "bay$n"
  case $mode in
    off)    gpioset -t0 "bay$n-led-a=0" "bay$n-led-b=0" ;;
    green)  gpioset -t0 "bay$n-led-a=1" "bay$n-led-b=0" ;;
    yellow) gpioset -t0 "bay$n-led-a=1" "bay$n-led-b=1" ;;
    red)    gpioset -t0 "bay$n-led-a=0" "bay$n-led-b=1" ;;
    red-blink) gpioset -t0 "bay$n-led-a=0"; start_blink "bay$n" gpioset -t 500ms,500ms "bay$n-led-b=1" ;;
  esac
}

power_set() {  # green|yellow|red|off
  [ "$power_last" = "$1" ] && return; power_last=$1
  case $1 in
    green)  r=0 g=1 ;; yellow) r=1 g=1 ;; red) r=1 g=0 ;; *) r=0 g=0 ;;
  esac
  gpioset -t0 -c gpiochip0 28=$r; gpioset -t0 -c gpiochip1 3=$g
}

act_set() {  # on|off|blink
  [ "$act_last" = "$1" ] && return; act_last=$1
  stop_blink act
  case $1 in
    blink) start_blink act gpioset -t 60ms,60ms -c gpiochip0 26=1 ;;
    on)    gpioset -t0 -c gpiochip0 26=1 ;;
    *)     gpioset -t0 -c gpiochip0 26=0 ;;
  esac
}

gauge_init() { $I2CSET -y $GAUGE_BUS $GAUGE_ADDR 6 0 && $I2CSET -y $GAUGE_BUS $GAUGE_ADDR 7 0; }
gauge_set() {  # number of LEDs lit, 0..10
  [ "$gauge_last" = "$1" ] && return
  bits=0 i=0
  for b in $GAUGE_MAP; do [ $i -lt "$1" ] && bits=$((bits | (1 << b))); i=$((i + 1)); done
  $I2CSET -y $GAUGE_BUS $GAUGE_ADDR 2 $((bits & 0xff)) && $I2CSET -y $GAUGE_BUS $GAUGE_ADDR 3 $(((bits >> 8) & 0xff)) && gauge_last=$1
}

# --- state readers ---------------------------------------------------------
disk_io() { awk '$3 ~ /^(sd[a-z]+|nvme[0-9]+n[0-9]+)$/ { s += $6 + $10 } END { print s + 0 }' /proc/diskstats; }

update_slow() {
  for n in 0 1 2 3 4; do
    if grep -qx "$n" "$FAILED_FILE" 2>/dev/null; then bay_set $n red-blink
    elif [ "$(gpioget --numeric "bay$n-detect" 2>/dev/null)" != 0 ]; then bay_set $n off
    elif [ "$(gpioget --numeric --as-is "bay$n-power" 2>/dev/null)" = 1 ]; then bay_set $n green
    else bay_set $n yellow
    fi
  done
  case $(systemctl is-system-running 2>/dev/null) in
    running) power_set green ;;
    starting|initializing|degraded) power_set yellow ;;
    *) power_set red ;;
  esac
  # shellcheck disable=SC2086
  pct=$(df -P $GAUGE_PATHS 2>/dev/null | awk 'NR > 1 { u += $3; t += $3 + $4 } END { if (t) print int(u * 100 / t); else print 0 }')
  gauge_set $(( (pct + 5) / 10 ))
}

# --- main ------------------------------------------------------------------
shutdown() { act_set off; power_last=; power_set red; for n in 0 1 2 3 4; do stop_blink "bay$n"; done; exit 0; }
trap shutdown TERM INT

i=0  # the expander driver may still be probing at boot
until gpioinfo bay0-led-a >/dev/null 2>&1; do i=$((i + 1)); [ $i -gt 20 ] && { echo "drobo-leds: no bay LED lines" >&2; exit 1; }; sleep 0.5; done
gauge_init || echo "drobo-leds: gauge expander at $GAUGE_ADDR on i2c-$GAUGE_BUS not answering" >&2

last_io=$(disk_io) idle=0 tick=0
update_slow
while :; do
  sleep "$POLL" & wait $!
  io=$(disk_io)
  if [ "$io" != "$last_io" ]; then act_set blink; idle=0; else idle=$((idle + 1)); [ $idle -ge 2 ] && act_set off; fi
  last_io=$io
  tick=$((tick + 1)); [ $tick -ge "$SLOW_EVERY" ] && { tick=0; update_slow; }
done
