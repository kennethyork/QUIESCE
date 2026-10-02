#!/bin/sh
# Quiesce — a Windows installer, built on Linux with makensis.
#
#   tools/package-windows.sh                build if needed, then Quiesce-<version>-setup.exe
#   tools/package-windows.sh --no-build     use what is already in build/
#
# `boson compile` already cross-builds the Windows target — build/windows/amd64 holds
# quiesce.exe (with PHP inside it), the library it links, and its assets — so the only
# thing missing was the wrapper that a person can double-click. NSIS is that wrapper,
# and it runs here, which means this artefact is built and inspected rather than
# described.
#
# Per-user, deliberately: it installs into %LOCALAPPDATA%\Programs\Quiesce, registers
# its uninstaller under HKCU, and asks for no administrator. A local-only application
# that asks to write to Program Files is asking for a permission it does not need, and
# the Linux side of this project already installs into ~/.local for the same reason.
#
# What is *not* claimed: no Windows machine was available, so the installer has been
# built and its contents listed, and has never been run. It is the same shape as the
# deb — binary, library, the scripts it spawns, a shortcut, an uninstaller — and it is
# labelled as untested wherever it is mentioned.

set -eu

HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo 0.1.0)"
DIST="$HERE/dist"
SOURCE="$HERE/build/windows/amd64"
DO_BUILD=1

for arg in "$@"; do
    case "$arg" in
        --no-build) DO_BUILD=0 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; exit 1 ;;
    esac
done

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v makensis >/dev/null 2>&1 || die "makensis is not installed (apt install nsis)"

[ -f "$SOURCE/quiesce.exe" ] || DO_BUILD=1

if [ "$DO_BUILD" = 1 ]; then
    command -v php >/dev/null 2>&1 || die "php is required to build the app"
    say "building the Windows target (php vendor/bin/boson compile)"
    (cd "$HERE" && php vendor/bin/boson compile >/dev/null) || die "the build failed"
fi

[ -f "$SOURCE/quiesce.exe" ] || die "no built binary at $SOURCE/quiesce.exe — does boson.json still list a windows target?"

# --- the files the installer ships ---------------------------------------------

STAGE="$DIST/.windows"
rm -rf "$STAGE"
mkdir -p "$STAGE/tools"

cp "$SOURCE/quiesce.exe" "$STAGE/quiesce.exe"

for file in "$SOURCE"/*.dll; do
    [ -f "$file" ] && cp "$file" "$STAGE/"
done

# The scripts the app spawns. The .php workers are the ones that matter on Windows; the
# .sh ones are the image engine's starter and installer, which need a POSIX shell — an
# honest gap, stated here and in the README rather than discovered by a reader.
for script in "$HERE"/tools/*.php "$HERE"/tools/*.sh; do
    [ -f "$script" ] || continue
    cp "$script" "$STAGE/tools/"
done

if [ -d "$SOURCE/assets/public" ] && [ -n "$(ls -A "$SOURCE/assets/public" 2>/dev/null)" ]; then
    cp -a "$SOURCE/assets/public" "$STAGE/assets-public"
fi

# NSIS wants a .ico; the repository has PNGs. Converted here rather than committed as a
# second copy of the same artwork, and skipped with a note if ImageMagick is missing.
ICO="$STAGE/quiesce.ico"
ICON_DEFINE=""

if command -v convert >/dev/null 2>&1 && [ -f "$HERE/assets/icon/quiesce-256.png" ]; then
    convert "$HERE/assets/icon/quiesce-256.png" -define icon:auto-resize=256,128,64,48,32,16 "$ICO" 2>/dev/null \
        && ICON_DEFINE="!define MUI_ICON \"$ICO\""
    say "icon: $(du -h "$ICO" | cut -f1) .ico made from the PNG"
else
    say "no ImageMagick: the installer gets NSIS's default icon"
fi

# --- the installer itself ------------------------------------------------------

NSI="$STAGE/quiesce.nsi"

cat >"$NSI" <<NSISCRIPT
!define APP_NAME "Quiesce"
!define APP_VERSION "$VERSION"
!define APP_PUBLISHER "Kenneth York"
!define APP_URL "https://github.com/kennethyork/QUIESCE"
!define STAGE "$STAGE"
$ICON_DEFINE

Name "\${APP_NAME} \${APP_VERSION}"
OutFile "$DIST/Quiesce-\${APP_VERSION}-windows-x86_64-setup.exe"
InstallDir "\$LOCALAPPDATA\\Programs\\Quiesce"
InstallDirRegKey HKCU "Software\\Quiesce" "InstallDir"
RequestExecutionLevel user
SetCompressor /SOLID lzma
Unicode true

!include "MUI2.nsh"
!define MUI_ABORTWARNING
!define MUI_FINISHPAGE_RUN "\$INSTDIR\\quiesce.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Start Quiesce, and see if it stays quiet"
!define MUI_FINISHPAGE_SHOWREADME ""
!define MUI_FINISHPAGE_SHOWREADME_TEXT "Put a shortcut on the desktop"
!define MUI_FINISHPAGE_SHOWREADME_FUNCTION DesktopShortcut

!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"

Function DesktopShortcut
    CreateShortcut "\$DESKTOP\\\${APP_NAME}.lnk" "\$INSTDIR\\quiesce.exe"
FunctionEnd

Section "Quiesce" SecMain
    SetOutPath "\$INSTDIR"

    File "\${STAGE}\\quiesce.exe"
    File /nonfatal "\${STAGE}\\*.dll"
    SetOutPath "\$INSTDIR\\tools"
    File "\${STAGE}\\tools\\*.*"

    CreateDirectory "\$SMPROGRAMS\\\${APP_NAME}"
    CreateShortcut "\$SMPROGRAMS\\\${APP_NAME}\\\${APP_NAME}.lnk" "\$INSTDIR\\quiesce.exe"

    WriteUninstaller "\$INSTDIR\\uninstall.exe"

    ; Registered under HKCU because this is a per-user install: it shows up in the
    ; reader's own Apps list and nowhere else, which is what a per-user install means.
    WriteRegStr HKCU "Software\\Quiesce" "InstallDir" "\$INSTDIR"
    WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "DisplayName" "\${APP_NAME}"
    WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "DisplayVersion" "\${APP_VERSION}"
    WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "Publisher" "\${APP_PUBLISHER}"
    WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "URLInfoAbout" "\${APP_URL}"
    WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "UninstallString" "\$INSTDIR\\uninstall.exe"
    WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "DisplayIcon" "\$INSTDIR\\quiesce.exe"
    WriteRegDWORD HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "NoModify" 1
    WriteRegDWORD HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce" "NoRepair" 1
SectionEnd

Section "Uninstall"
    Delete "\$INSTDIR\\quiesce.exe"
    Delete "\$INSTDIR\\uninstall.exe"
    Delete "\$INSTDIR\\*.dll"
    Delete "\$INSTDIR\\tools\\*.*"
    RMDir "\$INSTDIR\\tools"
    RMDir "\$INSTDIR"

    Delete "\$SMPROGRAMS\\\${APP_NAME}\\\${APP_NAME}.lnk"
    RMDir "\$SMPROGRAMS\\\${APP_NAME}"
    Delete "\$DESKTOP\\\${APP_NAME}.lnk"

    DeleteRegKey HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\Quiesce"
    DeleteRegKey HKCU "Software\\Quiesce"

    ; The reader's own things are left alone, and the installer says so: settings,
    ; conversations, sessions, jobs and the image engine live in %APPDATA%\\quiesce and
    ; %USERPROFILE%, which is where they belong and not where this installer put them.
    MessageBox MB_OK "Quiesce's files are removed. Your settings, conversations, sessions and image engine are untouched."
SectionEnd
NSISCRIPT


say "makensis"
makensis -V2 -NOCD "$NSI" || die "makensis failed"

SETUP="$DIST/Quiesce-${VERSION}-windows-x86_64-setup.exe"
[ -f "$SETUP" ] || die "makensis said it worked and produced nothing"

say ""
say "windows: $SETUP ($(du -h "$SETUP" | cut -f1))"
say ""
say "contents, as listed by 7z:"
7z l "$SETUP" 2>/dev/null | sed -n '/^----/,$p' | sed -n '2,14p' || true
