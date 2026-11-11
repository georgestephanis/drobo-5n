#!/bin/sh
# baymap.sh: find the occupied bay (0x23 reg1, active-low), power it, report its SATA port, then power it off.
# Run with sudo. Assumes exactly one disk inserted.
set -e
det=$(( $(i2cget -y 1 0x23 1) ))
slot=""; for s in 0 1 2 3 4; do case $s in 0) b=8;; 1) b=4;; 2) b=2;; 3) b=1;; 4) b=16;; esac; [ $((det & b)) -eq 0 ] && { slot=$s; bit=$b; }; done
[ -n "$slot" ] || { echo "no disk detected (reg1=$(printf 0x%02x $det))"; exit 1; }
echo "detect reg1=$(printf 0x%02x $det) -> slot $slot (bit $bit)"
before=$(ls /sys/class/scsi_disk)
i2cset -y 1 0x23 0x02 $(printf 0x%02x $(( $(i2cget -y 1 0x23 2) | bit )))
i2cset -y 1 0x23 0x06 $(printf 0x%02x $(( $(i2cget -y 1 0x23 6) & ~bit & 255 )))
for i in $(seq 1 30); do sleep 1; new=$(ls /sys/class/scsi_disk | grep -vxF "$before" || true); [ -n "$new" ] && break; done
[ -n "$new" ] || { echo "no SATA disk appeared in 30 s"; dmesg | grep -i ata | tail -5; }
for n in $new; do dev=$(ls /sys/class/scsi_disk/$n/device/block); p=$(readlink -f /sys/class/scsi_disk/$n/device); ata=$(echo $p | grep -oE '/ata[0-9]+/' | tr -d / | head -1); pci=$(echo $p | grep -oE '[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9]' | tail -1); [ -n "$pci" ] || pci=$(echo $p | grep -oE '[0-9a-f]+.sata'); echo "slot $slot -> $ata ($pci) /dev/$dev $(cat /sys/block/$dev/device/model) $(dmesg | grep "$ata: SATA link up" | tail -1 | grep -o '[0-9.]* Gbps')"; sync; echo 1 > /sys/block/$dev/device/delete; done
sleep 2
i2cset -y 1 0x23 0x06 $(printf 0x%02x $(( $(i2cget -y 1 0x23 6) | bit )))
echo "slot $slot powered off (cfg=$(i2cget -y 1 0x23 6)); safe to pull"
