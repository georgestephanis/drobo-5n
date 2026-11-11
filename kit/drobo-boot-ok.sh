#!/bin/sh
# drobo-boot-ok: runs a minute after each boot.
# U-Boot boots /boot/drobo (current) and falls back to /boot/drobo/prev when current won't load, or when a
# trial boot of a new set (flag file /boot/drobo/trial) didn't come back. Each set's DTB carries
# /chosen/drobo,set-id, so we can tell which one booted:
#   current -> record its checksums in booted-ok (mkdrobokernel then keeps it as prev) and clear the trial flag
#   prev    -> if a trial flag is present, the new set failed its trial: move it to /boot/drobo/failed,
#              copy prev back as current, clear the flag. Without a flag (prev booted by hand, or current
#              failed to load) leave everything alone and only warn.
# Afterwards drobo-boot-sync copies /boot/drobo to the mSATA boot partition, if there is one.
OUT=/boot/drobo
SET="kernel.0 kernel.1 kernel.2 kernel.3 kernel.4 initrd drobo5n.dtb VERSION SETID"
booted=$(tr -d '\0' < /proc/device-tree/chosen/drobo,set-id 2>/dev/null)
cur=$(cat "$OUT/SETID" 2>/dev/null); prv=$(cat "$OUT/prev/SETID" 2>/dev/null)
say() { echo "drobo-boot-ok: $*"; }
mark() {
  (cd "$OUT" && ls $SET 2>/dev/null | xargs md5sum > booted-ok.tmp && mv booted-ok.tmp booted-ok)
  rm -f "$OUT/trial"; sync
}
if [ -n "$booted" ] && [ "$booted" = "$cur" ]; then
  mark; say "marked $booted as booted"
elif [ -n "$booted" ] && [ "$booted" = "$prv" ] && [ ! -e "$OUT/trial" ]; then
  say "WARNING: booted prev ($prv) without a failed trial (by hand, or current didn't load); not changing anything"
elif [ -n "$booted" ] && [ "$booted" = "$prv" ]; then
  say "WARNING: set $cur did not boot; U-Boot fell back to $prv. Restoring $prv as current, failed set in $OUT/failed"
  rm -rf "$OUT/failed"; mkdir -p "$OUT/failed"
  for f in $SET; do [ -e "$OUT/$f" ] && mv "$OUT/$f" "$OUT/failed/$f"; done
  for f in $SET; do
    [ -e "$OUT/prev/$f" ] || continue
    cp -l "$OUT/prev/$f" "$OUT/$f" 2>/dev/null || cp -p "$OUT/prev/$f" "$OUT/$f"
  done
  mark
elif [ -z "$booted" ] && [ "$(cat "$OUT/VERSION" 2>/dev/null)" = "$(uname -r)" ] && [ -z "$cur" ]; then
  mark; say "marked $(uname -r) as booted (set without an id)"
else
  say "booted set '${booted:-none}' is neither current ('$cur') nor prev ('$prv'); not marking"
fi
[ -x /usr/local/sbin/drobo-boot-sync ] && /usr/local/sbin/drobo-boot-sync
exit 0
