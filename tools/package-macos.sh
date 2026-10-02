#!/bin/sh
# Quiesce — package a macOS .app bundle.
#
# ⚠ NEVER EXECUTED. Written on Linux; there is no macOS machine here to run it on.
# Treat it as a plan with the details filled in, not as a tested product. What is
# certain: the compiler already produces build/macos/{amd64,arm64}/quiesce, and the
# layout below is what a bundle needs. What is not: whether the binary runs on a
# Mac, and whether the icon converts without complaint.
#
#   tools/package-macos.sh [--arch=arm64|amd64] [--no-build]
#
# Also not done here, and required for a Mac to open it without a warning:
#   codesign --deep --force --sign "Developer ID Application: …" Quiesce.app
#   xcrun notarytool submit … --wait
# Both need an Apple Developer certificate this project does not have.

set -eu

ARCH="arm64"
DO_BUILD=1

for arg in "$@"; do
    case "$arg" in
        --arch=*) ARCH="${arg#--arch=}" ;;
        --no-build) DO_BUILD=0 ;;
        *) echo "unknown argument: $arg" >&2; exit 1 ;;
    esac
done

HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo 0.0.0)"
SOURCE="$HERE/build/macos/$ARCH"
APP="$HERE/dist/Quiesce.app"

[ "$DO_BUILD" = 1 ] && (cd "$HERE" && php vendor/bin/boson compile >/dev/null)

[ -x "$SOURCE/quiesce" ] || { echo "nothing built at $SOURCE/quiesce" >&2; exit 1; }

rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS/tools" "$APP/Contents/Resources"

cp "$SOURCE/quiesce" "$APP/Contents/MacOS/quiesce"
chmod +x "$APP/Contents/MacOS/quiesce"

for file in "$SOURCE"/*.dylib "$SOURCE"/*.so; do
    [ -f "$file" ] && cp "$file" "$APP/Contents/MacOS/"
done

for script in "$HERE"/tools/*.sh "$HERE"/tools/*.php; do
    [ -f "$script" ] || continue
    cp "$script" "$APP/Contents/MacOS/tools/"
done
chmod +x "$APP"/Contents/MacOS/tools/*.sh 2>/dev/null || true
printf '%s\n' "$VERSION" >"$APP/Contents/MacOS/VERSION"

if command -v iconutil >/dev/null 2>&1; then
    ICONSET="$HERE/dist/quiesce.iconset"
    mkdir -p "$ICONSET"
    for size in 16 32 64 128 256 512; do
        cp "$HERE/assets/icon/quiesce-512.png" "$ICONSET/icon_${size}x${size}.png"
    done
    iconutil -c icns "$ICONSET" -o "$APP/Contents/Resources/quiesce.icns" || true
    rm -rf "$ICONSET"
else
    echo "note: iconutil is not here (it is macOS only), so the bundle has no .icns" >&2
fi

cat >"$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key><string>Quiesce</string>
    <key>CFBundleDisplayName</key><string>Quiesce</string>
    <key>CFBundleIdentifier</key><string>cc.studytools.quiesce</string>
    <key>CFBundleVersion</key><string>$VERSION</string>
    <key>CFBundleShortVersionString</key><string>$VERSION</string>
    <key>CFBundleExecutable</key><string>quiesce</string>
    <key>CFBundleIconFile</key><string>quiesce</string>
    <key>CFBundlePackageType</key><string>APPL</string>
    <key>LSMinimumSystemVersion</key><string>12.0</string>
    <key>NSHighResolutionCapable</key><true/>
</dict>
</plist>
PLIST

echo "wrote $APP (arch $ARCH, version $VERSION)"
echo "run it: open '$APP'"
echo "not signed, not notarised: a Mac will ask before opening it."
