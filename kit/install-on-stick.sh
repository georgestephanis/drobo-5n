#!/bin/sh
# install-on-stick.sh MAC [KERNEL_VERSION]
# Set up a fresh Debian 13 install on the Drobo 5N's eUSB stick to boot by itself.
# Run as root inside the installed system: from the installer's shell, `chroot /target`,
# or later on the running system. MAC is the board's ethaddr from U-Boot (00:1a:62:..:..:..).
#
# It installs the kit's files, builds the device tree with your MAC, makes the initrd small
# (MODULES=dep plus the storage drivers), and builds /boot/drobo for U-Boot. Files it changes
# are copied to *.orig first.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
mac=${1:?usage: install-on-stick.sh MAC [KERNEL_VERSION]}
echo "$mac" | grep -Eq '^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$' || { echo "bad MAC: $mac" >&2; exit 1; }
ver=${2:-$(ls /lib/modules | grep armmp-lpae | sort -V | tail -1)}
[ -n "$ver" ] || { echo "no armmp-lpae kernel in /lib/modules" >&2; exit 1; }
LIB=/usr/local/lib/drobo
keep() { [ -e "$1" ] && [ ! -e "$1.orig" ] && cp -a "$1" "$1.orig"; return 0; }

echo "== packages"
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends \
  u-boot-tools device-tree-compiler xz-utils gpiod busybox avahi-daemon openssh-server
apt-mark manual openssh-server   # so removing the installer's task packages can't take it away
apt-get clean
echo 'APT::Keep-Downloaded-Packages "false";' > /etc/apt/apt.conf.d/99drobo-no-cache
printf '%s\n' 'Dir::Cache::pkgcache "";' 'Dir::Cache::srcpkgcache "";' > /etc/apt/apt.conf.d/99drobo-no-pkgcache   # saves ~84 MB
rm -f /var/cache/apt/pkgcache.bin /var/cache/apt/srcpkgcache.bin

echo "== tools"
mkdir -p $LIB /etc/kernel/postinst.d /etc/initramfs/post-update.d
install -m 644 "$here/stub.bin" "$here/drobo5n-fixups.dtsi" $LIB/
install -m 755 "$here/mkdrobokernel" /usr/local/sbin/mkdrobokernel
install -m 755 "$here/mkdrobodtb" /usr/local/sbin/mkdrobodtb
install -m 755 "$here/drobo-pmu.sh" /usr/local/sbin/drobo-pmu
install -m 755 "$here/drobo-fand.sh" /usr/local/sbin/drobo-fand
install -m 755 "$here/drobo-bays.sh" /usr/local/sbin/drobo-bays
install -m 755 "$here/drobo-leds.sh" /usr/local/sbin/drobo-leds
install -m 755 "$here/drobo-boot-ok.sh" /usr/local/sbin/drobo-boot-ok
install -m 755 "$here/drobo-boot-sync" /usr/local/sbin/drobo-boot-sync
install -m 755 "$here/drobo-powerbtn.sh" /usr/local/sbin/drobo-powerbtn
mkdir -p /usr/lib/systemd/system-shutdown /etc/systemd/system.conf.d
install -m 755 "$here/drobo-pmu-poweroff.sh" /usr/lib/systemd/system-shutdown/drobo-pmu-poweroff   # poweroff really cuts power
install -m 644 "$here/drobo-watchdog.conf" /etc/systemd/system.conf.d/drobo-watchdog.conf           # a hang resets the board
install -m 755 "$here/postinst-hook" /etc/kernel/postinst.d/zz-drobo
install -m 755 "$here/postinst-hook" /etc/initramfs/post-update.d/drobo
install -m 644 "$here/drobo-fan.service" "$here/drobo-bays.service" "$here/drobo-leds.service" "$here/drobo-boot-ok.service" "$here/drobo-powerbtn.service" /etc/systemd/system/
systemctl enable drobo-fan.service drobo-bays.service drobo-leds.service drobo-boot-ok.service drobo-powerbtn.service
echo i2c-dev > /etc/modules-load.d/i2c-dev.conf                     # the capacity gauge is driven from user space
echo drivetemp > /etc/modules-load.d/drivetemp.conf
echo blacklist pata_marvell > /etc/modprobe.d/drobo-no-pata.conf     # the 88SE9122s' unused PATA side
echo blacklist marvell_cesa > /etc/modprobe.d/drobo-no-cesa.conf     # its hash self-tests fail on this kernel

echo "== device tree (MAC $mac)"
bytes=$(echo "$mac" | tr 'A-F:' 'a-f ')
sed "s|/\* Put your board's MAC here.*|local-mac-address = [$bytes];|; /^[[:space:]]*local-mac-address = \[00 1a 62 xx xx xx\]; \*\//d" \
  "$here/drobo5n-base.dts" > /tmp/drobo5n-base.dts
grep -q "local-mac-address = \[$bytes\]" /tmp/drobo5n-base.dts || { echo "MAC not inserted" >&2; exit 1; }
dtc -q -I dts -O dtb -o $LIB/drobo5n-base.dtb /tmp/drobo5n-base.dts
mkdrobodtb --no-kernel

echo "== initrd"
keep /etc/initramfs-tools/initramfs.conf; keep /etc/initramfs-tools/modules
sed -i 's/^MODULES=.*/MODULES=dep/' /etc/initramfs-tools/initramfs.conf
grep -q '^sata_mv' /etc/initramfs-tools/modules || cat "$here/initramfs-modules" >> /etc/initramfs-tools/modules
update-initramfs -u -k "$ver"     # its post-update hook runs mkdrobokernel "$ver"
[ -f /boot/drobo/kernel.0 ] || mkdrobokernel "$ver"

echo "== done"
ls -l /boot/drobo
df -h / | tail -1
