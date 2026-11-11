#!/bin/sh
# /usr/lib/systemd/system-shutdown/drobo-pmu-poweroff
# systemd-shutdown runs this last, after every filesystem is unmounted or read-only. $1 is
# halt, poweroff, reboot or kexec. On poweroff, ask the PMU to cut main power: the "S" command
# (vxWorks' pmuShutdownNow sends it and expects "s"). Without this, Linux can only halt and the
# board stays powered with its LEDs frozen.
[ "$1" = poweroff ] || exit 0
stty -F /dev/ttyS2 38400 cs8 -cstopb -parenb raw -echo -ixon -ixoff -crtscts clocal 2>/dev/null
printf 'S\n' > /dev/ttyS2
sleep 10
