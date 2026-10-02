#!/bin/sh
# Quiesce — an RPM, from the same staged files as the deb and the tarball.
#
#   tools/package-rpm.sh                 build if needed, then the RPM
#   tools/package-rpm.sh --no-build      use what is already in build/
#
# The layout is the one `install-app.sh` uses, moved to the system prefix, so an RPM
# install and a `--prefix=/usr` install are the same install: /usr/lib/quiesce holds
# the binary, the library it links and the scripts it spawns; /usr/bin/quiesce is the
# launcher, which cds into the install directory first.
#
# What is *not* claimed: this has been built and its contents listed on this machine,
# but it has not been installed on a Fedora or RHEL machine, because there is not one
# here. The Requires line is the honest best-effort mapping of the deb's Depends —
# webkitgtk6.0 and gtk4 are the Fedora names for the libraries the binary links.

set -eu

HERE="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo 0.1.0)"
DIST="$HERE/dist"
SOURCE="$HERE/build/linux/amd64"
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

command -v rpmbuild >/dev/null 2>&1 || die "rpmbuild is not installed (dnf install rpm-build)"

[ -x "$SOURCE/quiesce" ] || DO_BUILD=1

if [ "$DO_BUILD" = 1 ]; then
    command -v php >/dev/null 2>&1 || die "php is required to build the app"
    say "building (php vendor/bin/boson compile)"
    (cd "$HERE" && php vendor/bin/boson compile >/dev/null) || die "the build failed"
fi

[ -x "$SOURCE/quiesce" ] || die "no built binary at $SOURCE/quiesce"

TOP="$DIST/.rpmbuild"
rm -rf "$TOP"
mkdir -p "$TOP/BUILD" "$TOP/RPMS" "$TOP/SPECS" "$TOP/BUILDROOT"

# --- the files, laid out as they will be installed -----------------------------

PREFIX="$TOP/BUILDROOT/quiesce-$VERSION-1.x86_64"
mkdir -p "$PREFIX/usr/lib/quiesce/tools" "$PREFIX/usr/bin" \
         "$PREFIX/usr/share/applications" "$PREFIX/usr/share/doc/quiesce"

cp "$SOURCE/quiesce" "$PREFIX/usr/lib/quiesce/quiesce"
chmod 0755 "$PREFIX/usr/lib/quiesce/quiesce"

for file in "$SOURCE"/*.so; do
    [ -f "$file" ] && cp "$file" "$PREFIX/usr/lib/quiesce/"
done

for script in "$HERE"/tools/*.php "$HERE"/tools/*.sh; do
    [ -f "$script" ] || continue
    cp "$script" "$PREFIX/usr/lib/quiesce/tools/"
done
chmod 0755 "$PREFIX"/usr/lib/quiesce/tools/*.sh 2>/dev/null || true

cat >"$PREFIX/usr/bin/quiesce" <<'LAUNCHER'
#!/bin/sh
# Installed by the quiesce RPM. Runs from the install directory so the app finds the
# scripts it spawns.
cd /usr/lib/quiesce || exit 1
exec /usr/lib/quiesce/quiesce "$@"
LAUNCHER
chmod 0755 "$PREFIX/usr/bin/quiesce"

for size in 48 128 256 512; do
    icon="$HERE/assets/icon/quiesce-$size.png"
    [ -f "$icon" ] || continue
    mkdir -p "$PREFIX/usr/share/icons/hicolor/${size}x${size}/apps"
    cp "$icon" "$PREFIX/usr/share/icons/hicolor/${size}x${size}/apps/quiesce.png"
done

cat >"$PREFIX/usr/share/applications/quiesce.desktop" <<'ENTRY'
[Desktop Entry]
Type=Application
Name=Quiesce
Comment=Local models, on a machine that stays yours
Exec=/usr/bin/quiesce
Icon=quiesce
Terminal=false
Categories=Utility;
Keywords=ollama;llm;local;ai;quiet;
StartupNotify=true
ENTRY

cp "$HERE/LICENSE" "$PREFIX/usr/share/doc/quiesce/LICENSE" 2>/dev/null || true
cp "$HERE/README.md" "$PREFIX/usr/share/doc/quiesce/README.md" 2>/dev/null || true

# --- the spec ------------------------------------------------------------------
#
# No %build: the binary is compiled from the PHP in this repository by `boson compile`,
# and what an RPM does here is lay out an artefact that already exists. %install copies
# the staged tree into the build root, which is why the two halves must agree about
# where things go — they do, both from the same layout above.

cat >"$TOP/SPECS/quiesce.spec" <<SPEC
Name:           quiesce
Version:        $VERSION
Release:        1
Summary:        Local models only, and a governor in front of them
License:        MIT
URL:            https://github.com/kennethyork/QUIESCE
BuildArch:      x86_64

Requires:       webkitgtk6.0
Requires:       gtk4

%description
A desktop application for local models that stays quiet. Every generation goes through
one governed endpoint on 127.0.0.1:11435, which serialises them, holds a duty cycle and
refuses to start while the card is hot — including for coding agents that never open
this window.

Ollama only, loopback only, no telemetry: chat, an agentic task loop with tools and a
gate on commands, checkpoints and undo, MCP, skills, documents, sessions, jobs, vision,
and image generation through sd.cpp with LoRAs.

%prep

%build

%install
cp -a %{_builddir}/root/. %{buildroot}/

%files
/usr/lib/quiesce
/usr/bin/quiesce
/usr/share/applications/quiesce.desktop
/usr/share/icons/hicolor
%doc /usr/share/doc/quiesce

%changelog
* $(LC_ALL=C date '+%a %b %d %Y') Quiesce <noreply@example.invalid> - $VERSION-1
- Packaged from the same tree as the deb and the tarball.
SPEC

rm -rf "$TOP/BUILD/root"
mkdir -p "$TOP/BUILD/root/usr"
cp -a "$PREFIX/usr/." "$TOP/BUILD/root/usr/"

say "rpmbuild"
rpmbuild --define "_topdir $TOP" --define "_builddir $TOP/BUILD" \
         --define "_rpmdir $TOP/RPMS" \
         --define "_build_name_fmt %%{NAME}-%%{VERSION}-%%{RELEASE}.%%{ARCH}.rpm" \
         -bb "$TOP/SPECS/quiesce.spec" >"$TOP/rpmbuild.log" 2>&1 || {
    tail -20 "$TOP/rpmbuild.log" >&2
    die "rpmbuild failed (see $TOP/rpmbuild.log)"
}

RPM="$(find "$TOP/RPMS" -name '*.rpm' | head -1)"
[ -n "$RPM" ] || die "rpmbuild said it worked and produced nothing"
cp "$RPM" "$DIST/quiesce-${VERSION}-1.x86_64.rpm"

say ""
say "rpm: $DIST/quiesce-${VERSION}-1.x86_64.rpm"
rpm -qip "$DIST/quiesce-${VERSION}-1.x86_64.rpm" 2>/dev/null | head -8 || true
say ""
say "contents (first 12):"
rpm -qlp "$DIST/quiesce-${VERSION}-1.x86_64.rpm" 2>/dev/null | head -12 || true
say ""
say "install with:  sudo rpm -i $DIST/quiesce-${VERSION}-1.x86_64.rpm"
