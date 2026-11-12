#!/bin/sh
# powertest.sh: log the PMU power-fail line (GPIO 32 = gpiochip1 line 0) and PMU state to disk, synced every line,
# so the record survives a power cut. Read-only: GPIO 32 is already an input; Y/B/R are PMU info requests.
L=/var/log/powertest.log
log() { printf '%s %s\n' "$(date +%T.%N | cut -c1-12)" "$*" >> $L; sync $L; }
log "start; uptime $(cut -d' ' -f1 /proc/uptime)"
gpiomon -c gpiochip1 -e both 0 2>&1 | while read -r ev; do log "GPIO32 $ev"; done &
i=0
while :; do
  log "PMU $(drobo-pmu Y B 2>&1 | tr -s ' ' | tr '\n' ' ')"
  [ $((i % 10)) -eq 0 ] && log "SYS load=$(cut -d' ' -f1 /proc/loadavg) soc=$(cat /sys/class/hwmon/hwmon0/temp1_input)"
  i=$((i + 1)); sleep 1
done
