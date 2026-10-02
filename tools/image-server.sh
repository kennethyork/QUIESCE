#!/bin/sh
# Quiesce — start a local image engine, whatever you have.
#
# The app speaks the AUTOMATIC1111 API (/sdapi/v1/txt2img), which is what Forge,
# A1111 and stable-diffusion.cpp all serve. This finds one, starts it bound to
# loopback, and writes a pid file so it can be stopped again.
#
#   tools/image-server.sh            start (find an engine and start it)
#   tools/image-server.sh --stop     stop what this started
#   tools/image-server.sh --status   is something answering on the port?
#   tools/image-server.sh --which    print the command it would run, and exit
#
# The engine is stable-diffusion.cpp, installed by tools/install-engine.sh. If you
# would rather run something else — a Forge or an A1111 you keep yourself — name it
# in "image_command" and this will drive that instead. Nothing else is searched for:
# an app should not go hunting through your home directory for something to start.
#
# Nothing is downloaded, and nothing is ever bound to anything but 127.0.0.1.

set -eu

CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/quiesce"
DATA="${XDG_DATA_HOME:-$HOME/.local/share}/quiesce"
# The installer puts engines in the data directory; the config directory is kept
# working too, because that is where the first version of this looked.
ENGINE_DIRS="$DATA/engine $CONFIG/engine"
PORT="${QUIESCE_IMAGE_PORT:-7860}"
PIDFILE="$CONFIG/image-server.pid"
MARKER="$CONFIG/image-server.ours"
LOGFILE="$CONFIG/image-server.log"

answering() {
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/sdapi/v1/sd-models" 2>/dev/null
}

case "${1:-}" in
    --status)
        if answering; then
            echo "answering on http://127.0.0.1:$PORT"
            exit 0
        fi
        echo "nothing answering on http://127.0.0.1:$PORT"
        exit 1
        ;;
    --stop)
        if [ -f "$PIDFILE" ]; then
            pid=$(cat "$PIDFILE")

            if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
                # The engine is a session leader, so killing the group takes its
                # worker processes with it instead of leaving a GPU-holding orphan.
                kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
                sleep 3
                kill -9 -- -"$pid" 2>/dev/null || true
                echo "stopped (pid $pid)"
            else
                echo "nothing of ours was running"
            fi

            rm -f "$PIDFILE" "$MARKER"
        else
            echo "this script did not start it — leaving it alone"
        fi
        exit 0
        ;;
esac

# ---- find one ----

resolved=""

if [ -n "${QUIESCE_IMAGE_COMMAND:-}" ]; then
    resolved="$QUIESCE_IMAGE_COMMAND"
fi

if [ -z "$resolved" ] && [ -f "$CONFIG/settings.json" ] && command -v python3 >/dev/null 2>&1; then
    resolved=$(python3 - "$CONFIG/settings.json" <<'PY' || true
import json, sys
try:
    print((json.load(open(sys.argv[1])).get("image_command") or "").strip())
except Exception:
    print("")
PY
)
fi

for ENGINE_DIR in $ENGINE_DIRS; do
    if [ -z "$resolved" ] && [ -x "$ENGINE_DIR/sd-server" ]; then
        # The installer writes this launcher, and it already knows its port and model.
        resolved="$ENGINE_DIR/sd-server"
    fi

    if [ -z "$resolved" ] && [ -x "$ENGINE_DIR/venv/bin/python" ] && [ -f "$ENGINE_DIR/launch.py" ]; then
        resolved="$ENGINE_DIR/venv/bin/python $ENGINE_DIR/launch.py --api --nowebui --port $PORT --server-name 127.0.0.1"
    fi
done

if [ -z "$resolved" ]; then
    echo "no image engine installed." >&2
    echo "  Install it:   sh tools/install-engine.sh" >&2
    echo "  Or name your own:  set \"image_command\" in $CONFIG/settings.json" >&2
    exit 1
fi

if [ "${1:-}" = "--which" ]; then
    printf '%s\n' "$resolved"
    exit 0
fi

if answering; then
    echo "something is already answering on http://127.0.0.1:$PORT — leaving it alone"
    exit 0
fi

echo "starting: $resolved"

setsid sh -c "exec $resolved" >>"$LOGFILE" 2>&1 &
echo $! >"$PIDFILE"
: >"$MARKER"

waited=0

while [ "$waited" -lt 180 ]; do
    if answering; then
        echo "up on http://127.0.0.1:$PORT after ${waited}s"
        exit 0
    fi

    sleep 3
    waited=$((waited + 3))

    if ! kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "the engine exited while starting; see $LOGFILE" >&2
        rm -f "$PIDFILE" "$MARKER"
        exit 1
    fi
done

echo "the engine did not answer within 180s; see $LOGFILE" >&2
exit 1
