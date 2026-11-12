#!/bin/sh
# drobo-powerbtn: make the rear power button do a clean shutdown under Linux.
# Until the PMU hears "K" (vxWorks' pmuCpuAwake, sent once at start-up) it treats a press as
# "nothing is running" and cuts power at once. After "K", a press raises SoC GPIO 40
# (gpiochip1 line 8; vxWorks' bspIsPowerDownReq) and the PMU waits. We then run a normal
# poweroff, and /usr/lib/systemd/system-shutdown/drobo-pmu-poweroff sends "S" at the very end.
#
# A power cut raises the same GPIO 40 request, and also pulls GPIO 32 (gpiochip1 line 0,
# "PMU Panic") low. The backup battery then carries the board for a few seconds, which is
# plenty for a clean shutdown (3.5 s measured). Both cases shut down; GPIO 32 only decides
# what gets logged and broadcast.
set -u
/usr/local/sbin/drobo-pmu K || echo "drobo-powerbtn: PMU didn't answer K" >&2
gpiomon -c gpiochip1 -e rising -n 1 8 > /dev/null
if [ "$(gpioget -c gpiochip1 --numeric 0 2>/dev/null)" = 0 ]; then
  msg="drobo-powerbtn: power failed, running on battery: shutting down now"
else
  msg="drobo-powerbtn: power button pressed, shutting down"
fi
echo "$msg"
wall "$msg" 2>/dev/null || true
exec systemctl poweroff
