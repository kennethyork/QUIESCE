#!/bin/sh
# Quiesce — update from a manifest, checking what you downloaded.
#
#   tools/update.sh --check   --manifest=<path or url>
#   tools/update.sh --install --manifest=<path or url> [--prefix=~/.local]
#
# The manifest is the file tools/package.sh writes (dist/update.json): a version,
# and for each artifact its name, size and sha256.
#
# What this gives you, and what it does not:
#
#   integrity    a download that is truncated or corrupted is refused, with both
#                hashes printed, because the manifest and the file must agree
#   authenticity NOTHING. This project has no signing keys, so a manifest served
#                by somebody else would be believed. Signing is the step that
#                turns this into a real update path, and it needs a key you own.
#
# The manifest may be a path or a file:// URL for local testing; http(s) works the
# same way and is the only thing that leaves the machine.

set -eu

CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/quiesce"
PREFIX="$HOME/.local"
MANIFEST="${QUIESCE_UPDATE_MANIFEST:-}"
MODE=""

for arg in "$@"; do
    case "$arg" in
        --check) MODE=check ;;
        --install) MODE=install ;;
        --manifest=*) MANIFEST="${arg#--manifest=}" ;;
        --prefix=*) PREFIX="${arg#--prefix=}" ;;
        -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; exit 1 ;;
    esac
done

[ -n "$MODE" ] || { echo "say --check or --install" >&2; exit 1; }
[ -n "$MANIFEST" ] || MANIFEST="$(python3 - "$CONFIG/settings.json" <<'PY' 2>/dev/null || true
import json, sys
try:
    print((json.load(open(sys.argv[1])).get("update_url") or "").strip())
except Exception:
    print("")
PY
)"

[ -n "$MANIFEST" ] || {
    echo "no manifest: pass --manifest=… or set \"update_url\" in $CONFIG/settings.json" >&2
    exit 1
}

INSTALLED="$(cat "$(dirname "$0")/../VERSION" 2>/dev/null || echo 0.0.0)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fetch() {
    case "$1" in
        http://*|https://*) curl -fsSL --retry 2 -o "$2" "$1" ;;
        file://*) cp "${1#file://}" "$2" ;;
        *) cp "$1" "$2" ;;
    esac
}

fetch "$MANIFEST" "$WORK/update.json" || { echo "could not read the manifest: $MANIFEST" >&2; exit 1; }

python3 - "$WORK/update.json" "$INSTALLED" "$MODE" "$WORK" "$MANIFEST" <<'PY'
import json, os, shutil, subprocess, sys, urllib.parse

manifest_path, installed, mode, work, manifest_origin = sys.argv[1:6]

try:
    manifest = json.load(open(manifest_path))
except Exception as error:
    print(f"the manifest is not JSON: {error}")
    raise SystemExit(1)

available = str(manifest.get("version", "")).strip()

if not available:
    print("the manifest does not say what version it is")
    raise SystemExit(1)

print(f"installed: {installed}")
print(f"available: {available}")

if available == installed:
    print("nothing to do: this is the version you have")

if mode == "check":
    if available != installed:
        print("\nartifacts in the manifest:")
        for artifact in manifest.get("artifacts", []):
            print(f"  {artifact['name']:<46} {artifact['bytes']/1e6:8.1f} MB")
    raise SystemExit(0)

if available == installed:
    raise SystemExit(0)

artifact = None

for candidate in manifest.get("artifacts", []):
    if candidate.get("name", "").endswith(".tar.gz"):
        artifact = candidate
        break

if artifact is None:
    print("the manifest has no tarball to install")
    raise SystemExit(1)

# Artifacts live next to the manifest they belong to — the original location, not
# the temporary copy this read. For an http manifest, next to means the same URL.
if manifest_origin.startswith("http://") or manifest_origin.startswith("https://"):
    base = manifest_origin.rsplit("/", 1)[0]
    source = base + "/" + artifact["name"]
else:
    origin = manifest_origin[len("file://"):] if manifest_origin.startswith("file://") else manifest_origin
    base = os.path.dirname(os.path.abspath(origin))
    source = os.path.join(base, artifact["name"])

print(f"\nartifact: {artifact['name']}")

if source.startswith("http"):
    fetch = os.path.join(work, artifact["name"])
    subprocess.run(["curl", "-fsSL", "--retry", "2", "-o", fetch, source], check=False)
    source = fetch

if not os.path.isfile(source):
    print(f"not next to the manifest: {source}")
    print("(this updater installs from a manifest it can also read the files of;")
    print(" point it at the dist/ directory or serve that directory over http)")
    raise SystemExit(1)

import hashlib

digest = hashlib.sha256(open(source, "rb").read()).hexdigest()

if digest != artifact["sha256"]:
    print("REFUSED: the file does not match the manifest")
    print(f"  manifest says {artifact['sha256']}")
    print(f"  file is       {digest}")
    raise SystemExit(1)

print(f"sha256 matches: {digest[:16]}…")

if os.path.getsize(source) != artifact["bytes"]:
    print("REFUSED: the file is not the size the manifest says")
    raise SystemExit(1)

target = os.path.join(work, "unpacked")
os.makedirs(target, exist_ok=True)
subprocess.run(["tar", "-xzf", source, "-C", target], check=True)

roots = [os.path.join(target, name) for name in os.listdir(target)]
root = roots[0] if roots else target
installer = os.path.join(root, "install.sh")

if not os.path.isfile(installer):
    print("REFUSED: the archive has no install.sh in it")
    raise SystemExit(1)

print(f"installing from {root}")
raise SystemExit(subprocess.run(["sh", installer, f"--prefix={os.environ.get('QUIESCE_PREFIX', os.path.expanduser('~/.local'))}"]).returncode)
PY
