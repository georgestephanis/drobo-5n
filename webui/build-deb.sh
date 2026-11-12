#!/bin/sh
# build-deb.sh : build the React bundle and package drobo-webui_VERSION_all.deb into dist/.
# Needs node/npm, and either dpkg-deb or Docker (it falls back to a debian:trixie container).
set -e
cd "$(dirname "$0")"
VERSION=$(sed -n "s/^const VERSION = '\(.*\)';/\1/p" lib/bootstrap.php)
[ -n "$VERSION" ] || { echo "no VERSION in lib/bootstrap.php" >&2; exit 1; }

if [ "${SKIP_UI:-}" != 1 ]; then
  (cd ui && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npx vite build)
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
P=$STAGE/pkg
install -d "$P/DEBIAN" "$P/usr/share/drobo-webui/lib" "$P/usr/share/drobo-webui/public/assets" \
  "$P/usr/lib/drobo-webui" "$P/usr/sbin" "$P/etc/drobo-webui" "$P/etc/sudoers.d" \
  "$P/lib/systemd/system" "$P/usr/share/doc/drobo-webui"
install -m 0644 lib/*.php "$P/usr/share/drobo-webui/lib/"
install -m 0644 public/*.php public/favicon.svg "$P/usr/share/drobo-webui/public/"
install -m 0644 public/assets/app.js public/assets/app.css "$P/usr/share/drobo-webui/public/assets/"
install -m 0755 helper/drobo-webui-helper "$P/usr/lib/drobo-webui/"
install -m 0755 sbin/drobo-webui-passwd "$P/usr/sbin/"
install -m 0644 etc/lighttpd.conf "$P/etc/drobo-webui/"
install -m 0440 etc/sudoers "$P/etc/sudoers.d/drobo-webui"
install -m 0644 systemd/*.service systemd/*.timer "$P/lib/systemd/system/"
install -m 0644 README.md "$P/usr/share/doc/drobo-webui/"
sed "s/@VERSION@/$VERSION/" debian/control > "$P/DEBIAN/control"
install -m 0644 debian/conffiles "$P/DEBIAN/"
install -m 0755 debian/postinst debian/prerm debian/postrm "$P/DEBIAN/"
echo "Installed-Size: $(du -sk "$P" | cut -f1)" >> "$P/DEBIAN/control"

mkdir -p dist
OUT=drobo-webui_${VERSION}_all.deb
if command -v dpkg-deb >/dev/null; then
  dpkg-deb --root-owner-group -Zxz --build "$P" "dist/$OUT"
else
  docker run --rm -v "$STAGE:/s" -v "$PWD/dist:/out" debian:trixie \
    dpkg-deb --root-owner-group -Zxz --build /s/pkg "/out/$OUT"
fi
ls -l "dist/$OUT"
