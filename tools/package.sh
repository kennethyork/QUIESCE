#!/bin/sh
# Quiesce — build the things you would hand to somebody.
#
#   tools/package.sh                  build if needed, then package
#   tools/package.sh --no-build       package what is already in build/
#   tools/package.sh --update-manifest  also write dist/update.json
#
# Produces, in dist/:
#
#   quiesce-<version>-linux-amd64.tar.gz   the app, its tools, an installer
#   quiesce_<version>_amd64.deb            the same, as a Debian package
#   SHA256SUMS                             so a download can be checked
#   update.json                            what an updater reads (optional)
#
# What this does not do, and will not pretend to: **sign anything**. There are no
# keys here, so the checksums give you integrity (a corrupted or truncated
# download is caught) and not authenticity (a malicious one is not). Signing is a
# key you own and a step in this script; it is documented rather than faked.
#
# Linux is the only platform packaged here, because it is the only one that can be
# run and checked on this machine. packaging/ has scripts for macOS and Windows
# that have never been executed — they are labelled as such where you find them.

set -eu

HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo 0.0.0)"
TARGET="linux-amd64"
SOURCE="$HERE/build/linux/amd64"
DIST="$HERE/dist"
STAGE="$DIST/quiesce-$VERSION-$TARGET"
DO_BUILD=1
MANIFEST=0

for arg in "$@"; do
    case "$arg" in
        --no-build) DO_BUILD=0 ;;
        --update-manifest) MANIFEST=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; exit 1 ;;
    esac
done

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

if [ "$DO_BUILD" = 1 ]; then
    say "building $VERSION"
    (cd "$HERE" && php vendor/bin/boson compile >/dev/null) || die "the build failed"
fi

[ -x "$SOURCE/quiesce" ] || die "nothing built at $SOURCE/quiesce"

say "staging"
rm -rf "$STAGE"
mkdir -p "$STAGE/bin" "$STAGE/lib/quiesce/tools" "$STAGE/share/applications" "$STAGE/share/doc/quiesce"

cp "$SOURCE/quiesce" "$STAGE/lib/quiesce/quiesce"
chmod +x "$STAGE/lib/quiesce/quiesce"

for file in "$SOURCE"/*.so; do
    [ -f "$file" ] && cp "$file" "$STAGE/lib/quiesce/"
done

for script in "$HERE"/tools/*.sh "$HERE"/tools/*.php; do
    [ -f "$script" ] || continue
    cp "$script" "$STAGE/lib/quiesce/tools/"
done

chmod +x "$STAGE"/lib/quiesce/tools/*.sh 2>/dev/null || true

cp "$HERE/assets/icon/quiesce-256.png" "$STAGE/share/doc/quiesce/icon.png"
cp "$HERE/README.md" "$HERE/LICENSE" "$STAGE/share/doc/quiesce/" 2>/dev/null || true
printf '%s\n' "$VERSION" >"$STAGE/lib/quiesce/VERSION"

for size in 48 128 256 512; do
    icon="$HERE/assets/icon/quiesce-$size.png"
    [ -f "$icon" ] || continue
    mkdir -p "$STAGE/share/icons/hicolor/${size}x${size}/apps"
    cp "$icon" "$STAGE/share/icons/hicolor/${size}x${size}/apps/quiesce.png"
done

cat >"$STAGE/bin/quiesce" <<'LAUNCHER'
#!/bin/sh
# Runs from the install directory, so the app finds the scripts it spawns.
here="$(cd "$(dirname "$0")/.." && pwd)"
cd "$here/lib/quiesce" || exit 1
exec "$here/lib/quiesce/quiesce" "$@"
LAUNCHER
chmod +x "$STAGE/bin/quiesce"

cat >"$STAGE/share/applications/quiesce.desktop" <<ENTRY
[Desktop Entry]
Type=Application
Name=Quiesce
Comment=Local models, on a machine that stays yours
Exec=quiesce
Icon=quiesce
Terminal=false
Categories=Utility;
Keywords=ollama;llm;local;ai;quiet;
StartupNotify=true
ENTRY

cat >"$STAGE/install.sh" <<INSTALLER
#!/bin/sh
# Install this extracted copy under ~/.local (or --prefix=/somewhere).
set -eu
here="\$(cd "\$(dirname "\$0")" && pwd)"
prefix="\$HOME/.local"

for arg in "\$@"; do
    case "\$arg" in
        --prefix=*) prefix="\${arg#--prefix=}" ;;
    esac
done

mkdir -p "\$prefix/lib" "\$prefix/bin" "\$prefix/share"
cp -r "\$here/lib/quiesce" "\$prefix/lib/"
rm -rf "\$prefix/lib/quiesce/tools"
cp -r "\$here/lib/quiesce/tools" "\$prefix/lib/quiesce/"

mkdir -p "\$prefix/bin"
cp "\$here/bin/quiesce" "\$prefix/bin/quiesce"
chmod +x "\$prefix/bin/quiesce"

mkdir -p "\$prefix/share/applications" "\$prefix/share/doc/quiesce"
cp "\$here/share/applications/quiesce.desktop" "\$prefix/share/applications/"

for size in 48 128 256 512; do
    [ -f "\$here/share/icons/hicolor/\${size}x\${size}/apps/quiesce.png" ] || continue
    mkdir -p "\$prefix/share/icons/hicolor/\${size}x\${size}/apps"
    cp "\$here/share/icons/hicolor/\${size}x\${size}/apps/quiesce.png" "\$prefix/share/icons/hicolor/\${size}x\${size}/apps/quiesce.png"
done

command -v update-desktop-database >/dev/null 2>&1 && update-desktop-database "\$prefix/share/applications" 2>/dev/null || true
command -v gtk-update-icon-cache >/dev/null 2>&1 && gtk-update-icon-cache -qtf "\$prefix/share/icons/hicolor" 2>/dev/null || true

echo "installed to \$prefix — run it with \$prefix/bin/quiesce"
INSTALLER
chmod +x "$STAGE/install.sh"

say "tar"
tar -czf "$DIST/quiesce-$VERSION-$TARGET.tar.gz" -C "$DIST" "quiesce-$VERSION-$TARGET"

DEB="$DIST/quiesce_${VERSION}_amd64.deb"

if command -v dpkg-deb >/dev/null 2>&1; then
    say "deb"
    DEBROOT="$DIST/.deb"
    rm -rf "$DEBROOT"
    mkdir -p "$DEBROOT/DEBIAN" "$DEBROOT/usr/lib" "$DEBROOT/usr/bin" \
             "$DEBROOT/usr/share/applications" "$DEBROOT/usr/share/doc/quiesce"

    cp -r "$STAGE/lib/quiesce" "$DEBROOT/usr/lib/"
    cp "$STAGE/bin/quiesce" "$DEBROOT/usr/bin/quiesce"

    for size in 48 128 256 512; do
        [ -f "$HERE/assets/icon/quiesce-$size.png" ] || continue
        mkdir -p "$DEBROOT/usr/share/icons/hicolor/${size}x${size}/apps"
        cp "$HERE/assets/icon/quiesce-$size.png" "$DEBROOT/usr/share/icons/hicolor/${size}x${size}/apps/quiesce.png"
    done

    sed 's|^Exec=.*|Exec=/usr/bin/quiesce|' "$STAGE/share/applications/quiesce.desktop" \
        >"$DEBROOT/usr/share/applications/quiesce.desktop"

    cp "$HERE/README.md" "$DEBROOT/usr/share/doc/quiesce/" 2>/dev/null || true
    gzip -9 -c "$HERE/README.md" >"$DEBROOT/usr/share/doc/quiesce/README.md.gz" 2>/dev/null || true

    SIZE_KB=$(du -sk "$DEBROOT/usr" | cut -f1)

    cat >"$DEBROOT/DEBIAN/control" <<CONTROL
Package: quiesce
Version: $VERSION
Section: utils
Priority: optional
Architecture: amd64
Installed-Size: $SIZE_KB
Maintainer: Kenneth York
Homepage: https://github.com/kennethyork
Description: Local models, on a machine that stays yours
 A desktop application for running Ollama models without the fans going mad.
 Every generation goes through one loopback endpoint that serialises requests,
 duty-cycles the card and holds above a temperature ceiling, so an agent looping
 tool calls is paced like anything else.
CONTROL

    dpkg-deb --root-owner-group --build "$DEBROOT" "$DEB" >/dev/null
    rm -rf "$DEBROOT"
else
    say "dpkg-deb is not installed: no deb this time"
fi

say "checksums"
(cd "$DIST" && sh -c 'sha256sum quiesce-*.tar.gz quiesce_*.deb 2>/dev/null' >"$DIST/SHA256SUMS")

if [ "$MANIFEST" = 1 ]; then
    say "update manifest"
    python3 - "$DIST" "$VERSION" <<'PY'
import hashlib, json, os, sys, time

dist, version = sys.argv[1], sys.argv[2]
artifacts = []

for name in sorted(os.listdir(dist)):
    path = os.path.join(dist, name)

    if not os.path.isfile(path) or name in ("SHA256SUMS", "update.json"):
        continue

    with open(path, "rb") as handle:
        digest = hashlib.sha256(handle.read()).hexdigest()

    artifacts.append({
        "name": name,
        "sha256": digest,
        "bytes": os.path.getsize(path),
    })

manifest = {
    "version": version,
    "released": time.strftime("%Y-%m-%d"),
    "artifacts": artifacts,
    "note": "checksums give integrity, not authenticity: this project has no signing keys yet",
}

with open(os.path.join(dist, "update.json"), "w") as handle:
    json.dump(manifest, handle, indent=2)
    handle.write("\n")

print(json.dumps(manifest, indent=2))
PY
fi

say ""
ls -la "$DIST" | grep -vE "^total|^d" | awk '{ printf "  %-46s %8.1f MB\n", $9, $5/1000000 }'
say ""
say "install the tarball with:  tar -xzf dist/quiesce-$VERSION-$TARGET.tar.gz -C /tmp && /tmp/quiesce-$VERSION-$TARGET/install.sh"
say "install the deb with:      sudo dpkg -i $DEB"
