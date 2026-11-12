#!/bin/sh
# drobo-fand : set the Drobo 5N case fan from temperatures, through the PMU (drobo-pmu FTnn).
#
# The PMU lowers its fan target by itself (about one step a minute) unless the host keeps
# setting it, so this resends the target every PERIOD seconds even when it hasn't changed.
# The level is the highest of:
#   SoC  (hwmon "*thermal*"):  SOC_LO C -> FLOOR,  SOC_HI C -> 31
#   disks (hwmon "drivetemp"): DISK_LO C -> FLOOR, DISK_HI C -> 31
# FLOOR keeps the fan from stalling (its bearing is sticky at low speed). Any read error -> 31.
# A disk that is asleep (hdparm -C says standby) is skipped: reading drivetemp can wake some
# drives or reset their spin-down timer, and a sleeping disk isn't warming up anyway.
# Settings can be overridden in /etc/default/drobo-fan.
FLOOR=16 SOC_LO=50 SOC_HI=75 DISK_LO=35 DISK_HI=50 PERIOD=30
[ -r /etc/default/drobo-fan ] && . /etc/default/drobo-fan
PMU=/usr/local/sbin/drobo-pmu

ramp() {  # ramp TEMP LO HI -> level FLOOR..31
  t=$1 lo=$2 hi=$3
  if [ "$t" -le "$lo" ]; then echo "$FLOOR"
  elif [ "$t" -ge "$hi" ]; then echo 31
  else echo $(( FLOOR + (31 - FLOOR) * (t - lo) / (hi - lo) ))
  fi
}

level() {
  best=0 seen=0
  for h in /sys/class/hwmon/hwmon*; do
    name=$(cat "$h/name" 2>/dev/null) || continue
    case "$name" in
      *thermal*) lo=$SOC_LO hi=$SOC_HI ;;
      drivetemp) lo=$DISK_LO hi=$DISK_HI
        if command -v hdparm >/dev/null; then
          for b in "$h"/device/block/*; do
            [ -e "$b" ] && case "$(hdparm -C "/dev/${b##*/}" 2>/dev/null)" in *standby*|*sleeping*) continue 2 ;; esac
          done
        fi ;;
      *) continue ;;
    esac
    for f in "$h"/temp*_input; do
      [ -r "$f" ] || continue
      m=$(cat "$f" 2>/dev/null) || { echo 31; return; }
      case "$m" in ''|*[!0-9-]*) echo 31; return ;; esac
      l=$(ramp $((m / 1000)) "$lo" "$hi"); seen=1
      [ "$l" -gt "$best" ] && best=$l
    done
  done
  [ "$seen" = 1 ] || { echo 31; return; }
  echo "$best"
}

last=
while :; do
  lvl=$(level)
  two=$(printf %02d "$lvl")
  "$PMU" "FT$two" >/dev/null
  case "$("$PMU" FT.)" in
    *"FT$two!"*) [ "$two" = "$last" ] || echo "fan target $two"; last=$two ;;
    *) echo "PMU did not confirm FT$two, retrying"; last=; sleep 3; continue ;;
  esac
  sleep "$PERIOD"
done
