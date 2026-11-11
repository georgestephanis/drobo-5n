#!/bin/sh
# drobo-powerbtn: make the rear power button do a clean shutdown under Linux.
# Until the PMU hears "K" (vxWorks' pmuCpuAwake, sent once at start-up) it treats a press as
# "nothing is running" and cuts power at once. After "K", a press raises SoC GPIO 40
# (gpiochip1 line 8; vxWorks' bspIsPowerDownReq) and the PMU waits. We then run a normal
# poweroff, and /usr/lib/systemd/system-shutdown/drobo-pmu-poweroff sends "S" at the very end.
set -u
/usr/local/sbin/drobo-pmu K || echo "drobo-powerbtn: PMU didn't answer K" >&2
gpiomon -c gpiochip1 -e rising -n 1 8 > /dev/null
echo "drobo-powerbtn: power button pressed, shutting down"
exec systemctl poweroff
