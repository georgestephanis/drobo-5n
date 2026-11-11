#!/bin/sh
# drobo-boot-ok: after a good boot, record checksums of the boot set in /boot/drobo/booted-ok.
# mkdrobokernel only moves the current set to /boot/drobo/prev if it matches this file,
# so prev is always a set that has actually booted.
OUT=/boot/drobo
[ "$(cat "$OUT/VERSION" 2>/dev/null)" = "$(uname -r)" ] || { echo "drobo-boot-ok: running $(uname -r), boot files are $(cat "$OUT/VERSION" 2>/dev/null); not marking" >&2; exit 0; }
cd "$OUT" && md5sum kernel.0 kernel.1 kernel.2 kernel.3 kernel.4 initrd drobo5n.dtb VERSION > booted-ok.tmp && mv booted-ok.tmp booted-ok && sync
echo "drobo-boot-ok: marked $(uname -r) as booted"
