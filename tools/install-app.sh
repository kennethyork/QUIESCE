#!/bin/sh
# Quiesce — install the app: binary, icon, menu entry, and the scripts it spawns.
#
#   tools/install-app.sh                 build if needed, install under ~/.local
#   tools/install-app.sh --prefix=/usr   somewhere else
#   tools/install-app.sh --no-build      use what is already in build/
#   tools/install-app.sh --uninstall     remove what this installed
#
# What it does, and why each part is needed:
#
#   <prefix>/lib/quiesce/quiesce   the app itself — one file, no runtime to install
#   <prefix>/lib/quiesce/tools/    the scripts the app spawns: the image worker, the
#                                  engine starter and its installer. The compiled
#                                  binary cannot carry shell scripts it has to hand
#                                  to /bin/sh, so they live next to it on disk.
#   <prefix>/share/icons/…         the icon, in the sizes a desktop asks for
#   <prefix>/share/applications/   the menu entry
#   <prefix>/bin/quiesce           a launcher, so the entry and the shell agree
#
# Nothing is put outside <prefix>, and --uninstall removes exactly these paths.

set -eu

PREFIX="$HOME/.local"
BUILD=1
DO_BUILD=1

for arg in "$@"; do
    case "$arg" in
        --prefix=*) PREFIX="${arg#--prefix=}" ;;
        --no-build) DO_BUILD=0 ;;
        --uninstall) BUILD=0 ;;
        -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; exit 1 ;;
    esac
done

HERE="$(cd "$(dirname "$0")/.." && pwd)"
APP="$PREFIX/lib/quiesce"
BIN="$PREFIX/bin/quiesce"
ICONS="$PREFIX/share/icons/hicolor"
ENTRY="$PREFIX/share/applications/quiesce.desktop"

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

if [ "$BUILD" = 0 ]; then
    say "removing what this installed under $PREFIX"
    rm -f "$BIN" "$ENTRY"
    rm -rf "$APP"

    for size in 48 128 256 512; do
        rm -f "$ICONS/${size}x${size}/apps/quiesce.png"
    done

    command -v update-desktop-database >/dev/null 2>&1 && update-desktop-database "$PREFIX/share/applications" 2>/dev/null || true
    command -v gtk-update-icon-cache >/dev/null 2>&1 && gtk-update-icon-cache -qtf "$ICONS" 2>/dev/null || true

    say "removed. Your conversations, sessions, settings and image engine are untouched,"
    say "because those are yours and live in ~/.config/quiesce and ~/.local/share/quiesce."
    exit 0
fi

[ -f "$HERE/build/linux/amd64/quiesce" ] || DO_BUILD=1

if [ "$DO_BUILD" = 1 ]; then
    command -v php >/dev/null 2>&1 || die "php is required to build the app"
    say "building (php vendor/bin/boson compile) — this takes a minute the first time"
    (cd "$HERE" && php vendor/bin/boson compile >/dev/null) || die "the build failed"
fi

SOURCE="$HERE/build/linux/amd64"

[ -x "$SOURCE/quiesce" ] || die "no built binary at $SOURCE/quiesce — run without --no-build"

mkdir -p "$APP/tools" "$PREFIX/bin" "$PREFIX/share/applications"

cp "$SOURCE/quiesce" "$APP/quiesce"
chmod +x "$APP/quiesce"

# The shared library the binary needs, and the scripts it spawns.
for file in "$SOURCE"/*.so; do
    [ -f "$file" ] && cp "$file" "$APP/"
done

for script in "$HERE"/tools/*.php "$HERE"/tools/*.sh; do
    [ -f "$script" ] || continue
    cp "$script" "$APP/tools/"
done

chmod +x "$APP"/tools/*.sh 2>/dev/null || true

cat >"$BIN" <<LAUNCHER
#!/bin/sh
# Installed by Quiesce's own installer. Runs from the install directory so the
# app finds the scripts it spawns.
cd "$APP" || exit 1
exec "$APP/quiesce" "\$@"
LAUNCHER
chmod +x "$BIN"

for size in 48 128 256 512; do
    icon="$HERE/assets/icon/quiesce-$size.png"
    [ -f "$icon" ] || continue
    mkdir -p "$ICONS/${size}x${size}/apps"
    cp "$icon" "$ICONS/${size}x${size}/apps/quiesce.png"
done

cat >"$ENTRY" <<ENTRYFILE
[Desktop Entry]
Type=Application
Name=Quiesce
Comment=Local models, on a machine that stays yours
Exec=$BIN
Icon=quiesce
Terminal=false
Categories=Utility;
Keywords=ollama;llm;local;ai;quiet;
StartupNotify=true
ENTRYFILE

command -v update-desktop-database >/dev/null 2>&1 && update-desktop-database "$PREFIX/share/applications" 2>/dev/null || true
command -v gtk-update-icon-cache >/dev/null 2>&1 && gtk-update-icon-cache -qtf "$ICONS" 2>/dev/null || true

say ""
say "installed:"
say "  app      $APP/quiesce"
say "  launcher $BIN"
say "  entry    $ENTRY"
say "  icon     $ICONS/<size>/apps/quiesce.png"
say ""

if command -v desktop-file-validate >/dev/null 2>&1; then
    desktop-file-validate "$ENTRY" && say "the menu entry validates"
fi

case ":$PATH:" in
    *":$PREFIX/bin:"*) say "run it:  quiesce" ;;
    *) say "run it:  $BIN   (add $PREFIX/bin to PATH to get 'quiesce')" ;;
esac
