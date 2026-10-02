#!/bin/sh
# Quiesce — install the image engine, so /create_image works on a fresh machine.
#
#   tools/install-engine.sh            install it
#   tools/install-engine.sh --which    what is installed now, and where
#
# One engine, deliberately: **stable-diffusion.cpp**. MIT, a ~37 MB prebuilt
# binary, no Python and no PyTorch, and it serves the /sdapi/v1/txt2img API this
# app already speaks. About 4.3 GB in total, most of it the model.
#
# What it is not: AUTOMATIC1111 or Forge. Those are AGPL-3.0 and 17 GB with a
# Python environment inside them, which is a different product to ship to
# somebody who asked for a quiet desktop app. If you run one yourself anyway,
# "image_command" in settings.json will point this app at it.
#
# Nothing is installed outside the engine directory, nothing is downloaded that
# this file does not name, and nothing is ever bound to anything but 127.0.0.1.

set -eu

CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/quiesce"
DATA="${XDG_DATA_HOME:-$HOME/.local/share}/quiesce"
ENGINE="$DATA/engine"
MODELS="$ENGINE/models"
PORT="${QUIESCE_IMAGE_PORT:-7860}"

MODEL_REPO="stable-diffusion-v1-5/stable-diffusion-v1-5"
MODEL_FILE="v1-5-pruned-emaonly.safetensors"
MODEL_URL="https://huggingface.co/$MODEL_REPO/resolve/main/$MODEL_FILE"
MODEL_LICENCE="CreativeML OpenRAIL-M"

say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
die()  { printf 'error: %s\n' "$*" >&2; exit 1; }

need() {
    command -v "$1" >/dev/null 2>&1 || die "$1 is required and not installed"
}

free_gb() {
    df -Pk "$HOME" | awk 'NR==2 { printf "%d", $4 / 1048576 }'
}

# ---------------------------------------------------------------- what is here

which_installed() {
    if [ -x "$ENGINE/sd-server" ]; then
        say "engine:  sd.cpp installed in $ENGINE"
    else
        say "engine:  not installed (run this script with no arguments)"
    fi

    if [ -f "$MODELS/$MODEL_FILE" ]; then
        say "model:   $MODELS/$MODEL_FILE ($MODEL_LICENCE)"
    else
        say "model:   not downloaded yet"
    fi

    if [ -d "$ENGINE/loras" ]; then
        say "loras:   $(ls "$ENGINE/loras" 2>/dev/null | wc -l) file(s) in $ENGINE/loras"
    fi

    if [ -f "$CONFIG/image-server.pid" ]; then
        say "running: pid $(cat "$CONFIG/image-server.pid")"
    fi
}

# ---------------------------------------------------------------- pieces

fetch() {
    # fetch <url> <destination> — with progress, resumable, and a real failure
    # instead of a truncated file pretending to be a model.
    url="$1"
    to="$2"

    mkdir -p "$(dirname "$to")"

    if [ -s "$to" ]; then
        say "already present: $(basename "$to") ($(du -h "$to" | cut -f1))"
        return 0
    fi

    say "downloading $(basename "$to")"

    curl -fL --retry 3 --retry-delay 2 -C - --progress-bar -o "$to.part" "$url" \
        || die "download failed: $url"

    mv "$to.part" "$to"
}

latest_sd_release() {
    # The release JSON, filtered to one asset. Nothing else is parsed.
    curl -fsSL --max-time 30 https://api.github.com/repos/leejet/stable-diffusion.cpp/releases/latest \
        | python3 -c "
import json, sys
release = json.load(sys.stdin)
want = sys.argv[1]
assets = release.get('assets', [])
for asset in assets:
    if want == 'linux' and 'Linux' in asset['name'] and asset['name'].endswith('.zip'):
        # Prefer Vulkan: it works on NVIDIA, AMD and Intel alike.
        if 'vulkan' in asset['name']:
            print(asset['browser_download_url'], 'vulkan')
            break
for asset in assets:
    if want == 'linux' and 'Linux' in asset['name'] and asset['name'].endswith('.zip'):
        print(asset['browser_download_url'], 'cpu')
        break
" "${1:-linux}"
}

install_sd() {
    step "stable-diffusion.cpp (MIT)"
    need curl
    need unzip
    need python3

    [ "$(free_gb)" -ge 8 ] || die "this needs about 6 GB free; $(free_gb) GB available"

    url=$(latest_sd_release linux | head -1 | cut -d' ' -f1)
    kind=$(latest_sd_release linux | head -1 | cut -d' ' -f2)
    [ -n "$url" ] || die "no Linux build found in the latest stable-diffusion.cpp release"

    say "build:   $kind ($(basename "$url"))"

    fetch "$url" "$ENGINE/sd-build.zip"

    mkdir -p "$ENGINE/quiesce-sd" "$ENGINE/loras"
    unzip -oq "$ENGINE/sd-build.zip" -d "$ENGINE/quiesce-sd" || die "could not unpack the build"
    rm -f "$ENGINE/sd-build.zip"

    [ -x "$ENGINE/quiesce-sd/sd-server" ] || die "the build does not contain sd-server"

    fetch "$MODEL_URL" "$MODELS/$MODEL_FILE"

    # A launcher, so the app and the shell agree on exactly one command.
    cat >"$ENGINE/sd-server" <<LAUNCHER
#!/bin/sh
# Written by Quiesce's installer. Loopback only, one model, nothing else.
here="\$(dirname "\$0")"
LD_LIBRARY_PATH="\$here/quiesce-sd\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}" \\
exec "\$here/quiesce-sd/sd-server" --listen-ip 127.0.0.1 --listen-port "$PORT" \\
    --lora-model-dir "\$here/loras" \\
    -m "$MODELS/$MODEL_FILE" "\$@"
LAUNCHER
    chmod +x "$ENGINE/sd-server"

    say "launcher: $ENGINE/sd-server"
    say "loras:    drop .safetensors files in $ENGINE/loras and name them in a prompt as <lora:name:0.8>"
}

# ---------------------------------------------------------------- verify

verify() {
    step "checking it answers"
    starter="$(dirname "$0")/image-server.sh"

    if [ ! -f "$starter" ]; then
        say "no image-server.sh next to this installer; skipping the check"
        return 0
    fi

    if sh "$starter" --status >/dev/null 2>&1; then
        say "already answering on http://127.0.0.1:$PORT"
        return 0
    fi

    say "starting it (this is the slow first start: the engine loads the model)"
    sh "$starter" || die "the engine did not come up; see $CONFIG/image-server.log"

    say ""
    say "/create_image will work now. The app starts and stops this engine itself,"
    say "and lets it go after ten idle minutes so the video memory comes back."
}

# ---------------------------------------------------------------- main

for arg in "$@"; do
    case "$arg" in
        --engine=sd) ;;   # accepted for compatibility; sd.cpp is the engine
        --engine=*) die "there is one engine now: sd. Remove the argument, or use" \
                       "\"image_command\" in settings.json to point at your own." ;;
        --which) which_installed; exit 0 ;;
        -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
        *) die "unknown argument: $arg" ;;
    esac
done

mkdir -p "$MODELS"

install_sd
verify
