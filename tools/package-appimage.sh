#!/bin/sh
# Quiesce — an AppImage: one file, double-clicked, nothing installed.
#
#   tools/package-appimage.sh                build if needed, then the AppImage
#   tools/package-appimage.sh --no-build     use what is already in build/
#
# An AppImage is three things concatenated, which is why this does not need
# `appimagetool` or `linuxdeploy` — both of which are themselves AppImages, and neither
# of which can run where FUSE is unavailable:
#
#   1. the runtime, a small static ELF that mounts what follows it;
#   2. a squashfs image of an AppDir;
#   3. nothing else.
#
# The AppDir is the install layout from `install-app.sh`, moved under usr/, and AppRun
# cds into usr/bin before exec — because the app resolves the scripts it spawns by
# looking beside itself, and an AppImage starts wherever the reader is standing.

set -eu

HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo 0.1.0)"
DIST="$HERE/dist"
SOURCE="$HERE/build/linux/amd64"
CACHE="$DIST/.appimage"
DO_BUILD=1

for arg in "$@"; do
    case "$arg" in
        --no-build) DO_BUILD=0 ;;
        -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; exit 1 ;;
    esac
done

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v mksquashfs >/dev/null 2>&1 || die "mksquashfs is not installed (squashfs-tools)"

[ -x "$SOURCE/quiesce" ] || DO_BUILD=1

if [ "$DO_BUILD" = 1 ]; then
    command -v php >/dev/null 2>&1 || die "php is required to build the app"
    say "building (php vendor/bin/boson compile)"
    (cd "$HERE" && php vendor/bin/boson compile >/dev/null) || die "the build failed"
fi

[ -x "$SOURCE/quiesce" ] || die "no built binary at $SOURCE/quiesce"

# --- the runtime ---------------------------------------------------------------
#
# Fetched once and kept in dist/. Its checksum is *printed*, not asserted: the AppImage
# project publishes no manifest to assert it against, and inventing one would be worse
# than saying plainly what came down the wire.

mkdir -p "$CACHE"
RUNTIME="$CACHE/runtime-x86_64"

if [ ! -x "$RUNTIME" ]; then
    say "fetching the AppImage runtime (once)"
    curl -fsSL -o "$RUNTIME.tmp" \
        "https://github.com/AppImage/type2-runtime/releases/download/continuous/runtime-x86_64" \
        || die "could not fetch the AppImage runtime — no network, or GitHub is blocked"
    chmod 0755 "$RUNTIME.tmp"
    mv "$RUNTIME.tmp" "$RUNTIME"
fi

say "runtime sha256: $(sha256sum "$RUNTIME" | cut -c1-16)…"

# --- the AppDir ----------------------------------------------------------------

APPDIR="$DIST/.AppDir"
rm -rf "$APPDIR"
mkdir -p "$APPDIR/usr/bin/tools"

cp "$SOURCE/quiesce" "$APPDIR/usr/bin/quiesce"
chmod 0755 "$APPDIR/usr/bin/quiesce"

for file in "$SOURCE"/*.so; do
    [ -f "$file" ] && cp "$file" "$APPDIR/usr/bin/"
done

for script in "$HERE"/tools/*.php "$HERE"/tools/*.sh; do
    [ -f "$script" ] || continue
    cp "$script" "$APPDIR/usr/bin/tools/"
done
chmod 0755 "$APPDIR/usr/bin/tools"/*.sh 2>/dev/null || true

for size in 256 128 512; do
    [ -f "$HERE/assets/icon/quiesce-$size.png" ] || continue
    cp "$HERE/assets/icon/quiesce-$size.png" "$APPDIR/quiesce.png"
    break
done

cat >"$APPDIR/quiesce.desktop" <<'ENTRY'
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

cat >"$APPDIR/AppRun" <<'RUN'
#!/bin/sh
cd "$(dirname "$0")/usr/bin" || exit 1
exec ./quiesce "$@"
RUN
chmod 0755 "$APPDIR/AppRun"

# --- assemble ------------------------------------------------------------------

say "squashfs"
rm -f "$CACHE/quiesce.squashfs"
mksquashfs "$APPDIR" "$CACHE/quiesce.squashfs" -root-owned -noappend -comp zstd -quiet \
    || die "mksquashfs failed"

APPIMAGE="$DIST/Quiesce-${VERSION}-x86_64.AppImage"
cat "$RUNTIME" "$CACHE/quiesce.squashfs" >"$APPIMAGE"
chmod 0755 "$APPIMAGE"

say ""
say "appimage: $APPIMAGE ($(du -h "$APPIMAGE" | cut -f1))"
say ""
say "verified here by extracting it, which needs no FUSE:"
say "  $APPIMAGE --appimage-extract"
say ""
say "running it as intended needs FUSE on the reader's machine — or:"
say "  $APPIMAGE --appimage-extract-and-run"
