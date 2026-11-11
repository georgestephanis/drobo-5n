#!/bin/sh
# pmu.sh CMD... : send each CMD + "\n" to the Drobo 5N PMU (ttyS2, 38400 8N1), print the reply
# Protocol (from vxWorks pmuDebug/pmuPWMCmd and U-Boot's UART2 setup): UART2 at 0xD0012200, 38400 8N1,
# ASCII command followed by a newline; replies end in "!".
#   V = version, Y = status line. Fan: FD / FT / FA = default / target / actual, 2-digit decimal 01..31
#   (FT31 = full; hex like FT1f is ignored). Append "." to query (FT.). LED brightness: LD / LT / LA likewise.
# Run with sudo on the Drobo. e.g. sh pmu.sh FT31 FA.
dev=/dev/ttyS2
# One talker at a time: two callers interleaving on the UART get each other's replies.
exec 9>/run/drobo-pmu.lock
flock -w 10 9 || { echo "drobo-pmu: PMU busy" >&2; exit 1; }
exec 3<>$dev
stty -F $dev 38400 cs8 -cstopb -parenb raw -echo -ixon -ixoff -crtscts clocal min 0 time 3
for c in "$@"; do
  timeout 1.2 cat <&3 > /tmp/pmu.out &
  sleep 0.15; printf '%s\n' "$c" >&3; wait
  printf '%-8s -> %s\n' "$c" "$(tr -d '\000\r\n' < /tmp/pmu.out)"
done
