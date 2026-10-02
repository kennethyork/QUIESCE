# Quiesce

**Local models, on a machine that stays yours.**

A desktop application for running Ollama models without the fans going mad. Plain PHP,
HTML, CSS and JavaScript on [Boson](https://bosonphp.com) — no Electron, no Node, no
framework, no bundler, no build step. The files in this directory are the program.

```bash
php index.php          # the window
php tools/harness.php  # the same governed endpoint, without the window
composer test:unit     # the governor's arithmetic, tested as arithmetic
```

---

## The three promises

| Promise | Where it is kept | How you can check it |
| --- | --- | --- |
| **Desktop only** | `index.php` — one native window, no server to open in a browser, no phone build. `boson.json` lists desktop targets only. | `ss -ltn` shows one loopback listener and nothing else. |
| **Local only** | `App\Http` — the only HTTP client in the program, and its constructor refuses any host that is not loopback. No provider list, no keys, no telemetry, no CDN fonts. | `grep -rn "http" src/ assets/private/js/` — every URL is `127.0.0.1`. |
| **Ollama only** | `App\Ollama` — one engine at `127.0.0.1:11434`, and a Start button that runs the `ollama` already on your PATH. | The status bar names the version it is talking to, or says it is not running. |

## The quiet part, and where it lives

Fans follow heat, heat follows sustained power, and sustained power follows **duty
cycle** — how much of the time the card is busy. One answer for twenty seconds is
invisible in a fan curve; an agent looping tool calls for twenty minutes is not.

So the governor is not in the chat window. It is in the socket, in
[`App\Guard`](src/Guard.php), listening on `127.0.0.1:11435` and forwarding to Ollama.
Anything that talks to that port is governed — including a coding agent that never opens
this window.

```
your agent ──▶ 127.0.0.1:11435 (the governor) ──▶ 127.0.0.1:11434 (ollama)
                    │
                    ├─ one generation at a time, always (agents that fan out get queued)
                    ├─ duty cycle: read for N ms, hold for M ms
                    ├─ temperature ceiling, with a floor to fall back to (hysteresis)
                    ├─ a token pace, and a rolling budget over twenty minutes
                    └─ context, prediction and thread ceilings, decided by the profile
```

The throttle is honest: while holding, the governor simply stops reading from Ollama.
The socket buffer fills, the model blocks on its next write, and the GPU goes idle for
that stretch. Nothing is killed mid-sentence — it stalls, then resumes, and every
decision is written to the log the window shows.

### Profiles

| | ceiling | duty cycle | token pace | context | threads | busy budget |
| --- | --- | --- | --- | --- | --- | --- |
| **Whisper** | 66 °C | 2 s on / 1.8 s off | ≤ 12 /s | 4,096 | 4 | 45% of 20 min |
| **Steady** (default) | 74 °C | 4 s on / 0.9 s off | ≤ 30 /s | 8,192 | 6 | 70% of 20 min |
| **Fast** | 84 °C | none | none | 16,384 | all | 100% |

### What it measured on this machine

Sustained sequential looping, 120 seconds each way, `qwen3:8b`, 48 tokens a request, the
same card (RTX 3090, already capped at 280 W against a 420 W default):

| | tokens/s | power (avg) | temp peak | GPU busy | fan peak |
| --- | --- | --- | --- | --- | --- |
| straight at `:11434` | 76.2 | 261 W | 71 °C | 72% | 63% |
| through `:11435`, Whisper | 19.2 | 172 W | 66 °C | **24%** | 64% |

Three times less duty and a third less average power. Starting the governed run while the
card was still hot from the first, the governor's first act was to refuse to work:

```
[ 152s]  59 °C  57 W   cooling at 60 °C (floor 58 °C)      ← holding, refusing to start
[ 156s]  58 °C  62 W   going — qwen3:8b 1s
[ 158s]  60 °C 150 W   in the off 1.8s of a 53% duty cycle
```

**Three honest caveats.**

1. **The fan percentage did not move in two minutes** (63% vs 64% peak). This card idles
   at ~60 °C with its curve already sitting near 60%, so a two-minute sample cannot show
   acoustics — the difference the governor makes is in work per minute, which is what
   decides where the fan *settles* over half an hour. The measurement above is the
   defensible claim; "your fans will be quiet" is not.
2. **Per-token peak power is not governed.** A 30B model at long context still spikes
   while it runs. The only real lever on peak wattage is the card's power limit
   (`nvidia-smi -pl`), which needs root and cannot be set from here. The card is already
   capped at 280 W; the app reports that and does not claim credit for it.
3. **The ceiling sits close to idle temperature.** Whisper's 66 °C ceiling is six degrees
   above this card's idle. That is why it is slow: it spends its time waiting. Raise the
   ceiling, or use Steady.

## Being agentic

The endpoint speaks both shapes agents already use, and tool calls pass through intact
(verified end to end: `finish_reason: tool_calls`, arguments preserved as JSON):

```bash
# Ollama-native agents
OLLAMA_HOST=http://127.0.0.1:11435 ollama run qwen3:8b

# OpenAI-shaped agents
OPENAI_BASE_URL=http://127.0.0.1:11435/v1   OPENAI_API_KEY=local
```

Point a coding agent (or an MCP-based one) at those addresses instead of 11434 and its
loops are serialised, duty-cycled and heat-limited without it knowing anything about any
of this.

### The built-in agent

The **Task** view runs a tool loop inside the window: think, call a tool, read the
result, think again, answer. Every step is one generation **through the governed
endpoint**, so a task is paced, serialised and heat-limited by exactly the same code that
paces a chat message. An agent looping for twenty minutes is the case the governor exists
for, and this is that case, on purpose.

Tools, and their rules:

| Tool | What it can touch |
| --- | --- |
| `list_files` | the working folder you choose, relative paths only |
| `read_file` | a file inside it, at most 64 KB returned |
| `write_file` | a file inside it, at most 256 KB, parent folders created |
| `run_command` | runs in the folder, **as you** — read-only commands run, anything that could write is held for your approval, a few are refused outright |
| `web_search` | leaves the machine — logged, and marked in the step list |
| `web_fetch` | one public http(s) page; loopback and private addresses refused |

The folder is chosen with a native picker (**Choose…**) and confined properly: every path
is resolved with `realpath()` and checked to be inside it, so `../`, absolute paths, and
symlinks pointing out are refused rather than sanitised. `tests/Unit/ToolsTest.php`
attempts each of those escapes — including writing through a symlinked directory — and the
window will not start a task until a folder is chosen.

### Commands, and the gate

`run_command` runs as you, with the working folder as its directory. That is a **gate,
not a sandbox**, and it should be said in the same breath as the feature:

- **Read-only programs run immediately**: `ls`, `cat`, `grep`, `find`, `git status`,
  `git diff`, `git log`, `wc`, `pwd` and friends.
- **Anything that could write waits for you**: any shell operator (`>`, `|`, `&&`, `` ` ``,
  `$(`), any program not known to be read-only (`rm`, `mv`, `npm`, `make`, `git commit`),
  and write-flags inside read-only programs (`sed -i`, `find -delete`). The window shows
  the exact command, why it was held, and two buttons.
- **A few are refused outright**, whatever you say: `sudo`, `su`, `pkexec`, `mkfs`, `dd`,
  `fdisk`, `shutdown`, `reboot`, `systemctl`, `rm -rf /`. `Shell::start()` re-checks this
  too, so no future caller can be the one that forgets.
- Output is capped at 32 KB, a command that overstays its timeout is killed
  (process group and all), and only one command runs at a time.

Verified end to end against `qwen3:8b`: `ls` ran unattended; `echo 'hi' > hello.txt` was
held, allowed, and the file appeared; `sudo whoami` was refused and the model reported the
refusal instead of working around it; `rm notes.md` was held, the reader refused it, and
`notes.md` was still there afterwards.

**What the gate does not do:** it cannot stop a command you *allow* from writing outside
the folder. There is no filesystem containment — a local model plus a fetched web page is
a prompt injection, and the gate is what stops the payload that arrives as "run this". If
you want a stronger guarantee, run the app as a separate user with access only to the
folder you choose.

**The web tools are the one place this application leaves your machine.** They are
switchable, default on (you asked for web), every call is written to the governor's log
with its URL, and each step that goes out is marked `left this machine` in the step list.
Model traffic never leaves: only these two tools open an outbound socket, and both refuse
private and loopback addresses so a fetched page cannot be aimed at your own network.

Search has no key and no account, and that limits it honestly: DuckDuckGo's HTML endpoint
serves a challenge page to this machine, so with no search instance configured the agent
falls back to DuckDuckGo instant answers and Wikipedia. Add a **SearXNG** address in
settings (`qSearchUrl`) and search gets real. Fetching a page the reader or the model names
always works.

The loop can also run headless, which is how it was tested:

```bash
php tools/task.php "read notes.md and write a three-line summary to summary.md" \
    --workspace=/path/to/folder --model=qwen3:8b --profile=steady
```

### Sessions

Every task is written to `~/.config/quiesce/sessions` as one readable JSON file: the task,
the model, the steps, the files it wrote, the answer, and the full message history. The
**History** mode lists them, reads one back, or continues it — and continuing starts a
*new* file that carries the history forward, because rewriting yesterday's record because
you asked a follow-up today would make the transcripts worth nothing. The headless runner
can do the same: `php tools/task.php "…" --resume=<id>`.

### Skills

Markdown written for the model, in `~/.config/quiesce/skills`, as `name.md` or
`name/SKILL.md`. The prompt carries the names and their one-line descriptions; the full
text arrives only when the model calls `read_skill`. Loading every skill into an 8k context
would spend the context on instructions for work nobody asked for.

```markdown
---
description: how to write release notes in this repository
---

# Release notes

Every release note starts with a one-line summary in **bold** …
```

### Documents

`search_documents` finds text in the working folder by keyword and reports the file,
the approximate line and the passage. It is **not semantic search** — there is no embedding
model installed here, and shipping something that quietly needs one produces an agent that
answers confidently from nothing — so the tool says which it is, and how many bytes it read.
Scanning is bounded: 3,000 files, 1 MB per file, 12 MB per search, and `.git`,
`node_modules` and friends are never walked.

### Jobs

`~/.config/quiesce/jobs.json`, edited in the window or by hand:

```json
[{"id":"quiet-check","name":"Quiet check","task":"Summarise notes.md into summary.md",
  "model":"qwen3:8b","every_minutes":60,"enabled":true}]
```

Either every N minutes or once a day at `"at": "07:30"`. A due job **waits for an idle
machine**: no task running, nothing in the endpoint's queue, no chat answer in flight. A
background job running beside a foreground one is how a quiet machine stops being quiet.
Verified: a job configured with no previous run fired within seconds of launch, and its
session is recorded with `"job": "quiet-check"`.

### MCP

Your own servers, over stdio, in `~/.config/quiesce/mcp.json`:

```json
{"servers":[{"id":"files","command":"npx","args":["-y","@modelcontextprotocol/server-filesystem","/home/you/notes"],"enabled":true}]}
```

The client handshakes (`initialize` → `notifications/initialized` → `tools/list`), then
offers every tool as `mcp_<server>_<tool>` with the server's own schema. Calls are stepped,
not blocking, so a server that hangs cannot freeze the window, and they are marked in the
step list as leaving the app — a server is another program, and this application does not
pretend to know what it does. Tested against a real server process
(`tests/fixtures/mcp-echo-server.php`), including a tool that never answers.

**One bug worth recording.** JSON objects arrive in PHP as empty arrays, so `"properties": {}`
comes back as `"properties": []` — and Ollama rejects that outright with *"Value looks like
object, but can't find closing '}' symbol"*. The guard now decodes to objects rather than
arrays (`assoc: false`) so every body it forwards keeps its fidelity, and MCP schemas are
put back the way they arrived. That is the kind of bug that only shows up when a real model
server validates a real schema, which is why the tests run against one.

### Vision

Any model in Ollama that reports the `vision` capability is used automatically — the app
asks `/api/show` rather than keeping a list, so `ollama pull moondream` is the whole setup.
Two ways in: the agent has `look_at_image` (a path inside the working folder, plus an
optional question), and the window has `look`:

```
/look pictures/chart.png what does this show?
```

**A measured quirk, handled rather than hidden:** a 1B vision model sometimes answers
*nothing at all* — the same request to the same server came back with a sentence, then with
an empty `done`, then with a sentence. It also answers nothing for a bare shape on a blank
background, and answers readily when there is text in the picture. So `describe()` asks up
to three times, changing the wording, before it gives up and says so. The test uses a sign
with a word on it, for the same reason.

### Semantic search

With an embedding model installed (`ollama pull nomic-embed-text`) the **Index** button
builds vectors for the folder's chunks, cached in `~/.config/quiesce/embeddings.json`
(base64-packed floats, capped at 2,000 chunks, keyed by file, mtime and chunk index).
After that, `search_documents` ranks by cosine similarity and says so:

```
• docs/thermal.md around line 1
The quiet profile holds the card at a 66 C ceiling, close to idle, so it waits a lot.

(semantic search: nomic-embed-text:latest, locally)
```

That query — *"how hot is the card allowed to get"* — shares no word with the passage, which
is the entire point of doing it. Without a model, or before indexing, the same tool falls
back to keyword search and **says which it used**. Indexing is bounded per call (48 chunks at
a time from the window), so a big folder is progress you can watch.

**One bug worth recording here.** The query embedding took **122 seconds**. `Documents` was
posting to the app's own governed endpoint with a *blocking* client, from inside the loop
that also steps that endpoint — so the request waited for a guard that was waiting for the
loop, and only the timeout ended it. Embeddings now go straight to Ollama, which is also the
right shape for the governor's purpose: a few milliseconds of GPU is not the sustained work
the governor exists to cap. Generations still go through the one door.

### /create_image — and what engine it drives

```
/create_image a red cube on a wooden table
```

**Ollama cannot generate images** — there is no diffusion model in a language-model server —
so this needs a diffusion engine on the machine, speaking the AUTOMATIC1111 API. The window
has a **Start engine** button, and `tools/image-server.sh` finds and starts one:

```bash
tools/image-server.sh            # find an engine and start it (loopback only)
tools/image-server.sh --which    # print the command it would use
tools/image-server.sh --stop     # stop it, and give the video memory back
```

It looks, in order, for: `image_command` in settings → an engine in
`~/.config/quiesce/engine/` → a Forge/A1111 already installed on the machine. Whichever it
finds, it starts it with `--server-name 127.0.0.1` and stops it as a process group, so
nothing is ever bound to a network interface and no GPU-holding orphan is left behind.

**Measured on this machine**, against the Forge install that was already here, with
`dreamshaper_8` (SD 1.5):

| | |
| --- | --- |
| engine cold start | 18 s to answer `/sdapi/v1/sd-models` |
| 512×512, 20 steps | 3.4 s warm (11 s including a cold model load) |
| card during it | 65 °C, 244 W, 3.6 GB of video memory held |
| after **Stop engine** | port free, 1.1 GB held, 60 °C |

If the app started the engine itself it stops it again after ten idle minutes
(`image_idle_stop`), because a diffusion server sitting idle holds gigabytes of video memory,
which is the opposite of what this application is for. **Steps and size are in the window**:
1–4 for a turbo model, 20–30 for SD 1.5.

**One engine, and the app installs it.** The window has a **Set up engine** button, and

```bash
tools/install-engine.sh            # install it
tools/install-engine.sh --which    # what is installed now
```

The engine is **stable-diffusion.cpp**: MIT, a 37 MB prebuilt binary, no Python and no
PyTorch, and it speaks the same `/sdapi/v1/txt2img` API this client already used. It goes
into `~/.local/share/quiesce/engine/`, and the installer ends by starting it and checking
that it answers — an install that does not test itself is a guess.

**Measured here, from an empty directory:**

| | |
| --- | --- |
| install | **1 m 43 s**, 4.1 GB on disk (37 MB binary + the 4.3 GB model) |
| what it needs first | nothing: no Python, no PyTorch, no CUDA toolkit |
| model | SD 1.5, `v1-5-pruned-emaonly`, **CreativeML OpenRAIL-M** |
| cold start | 3 s to answer `/sdapi/v1/sd-models` |
| 512×512, 20 steps | **24.5 s**, 61 °C, 3.7 GB of video memory |

Re-running skips what is already there, so pressing the button twice is free.

**LoRAs.** Drop `.safetensors` files in `~/.local/share/quiesce/engine/loras/` and name them
in a prompt the way you already would:

```
/create_image a red cube on a wooden table, photograph <lora:lcm:0.9>
```

Two things about that, both measured rather than assumed:

- **The engine will not read that tag.** stable-diffusion.cpp deliberately ignores
  `<lora:...>` in a prompt — "clients should resolve LoRA usage through the structured `lora`
  array" — so the app parses it, strips it from the prompt, and sends
  `lora: [{"path": "lcm.safetensors", "multiplier": 0.9}]`. A tag the engine silently ignored
  would be worse than an error.
- **Paths must be relative to the LoRA folder.** An absolute path is refused with
  `invalid lora path`, so a name you type is resolved against that folder first; a name with
  no file behind it is passed through unchanged, because the engine's own complaint is a
  truer answer than the app inventing a file name.

Verified end to end with a real LoRA (LCM-LoRA for SD 1.5, 134 MB, OpenRAIL++, applied at
0.9): the engine logged `(834 / 834) LoRA tensors have been applied`, and the same prompt
drew in 13.0 s against 24.5 s without it. The installed LoRAs are listed in the window, with
the folder they came from.

**Why not Forge.** It was here on this machine, and it works — 3.4 s an image, because it runs
CUDA. It is also **AGPL-3.0** and **17 GB** (7.5 GB of Python environment with PyTorch, 8.7 GB
of checkpoints). That is a different product to hand somebody who asked for a quiet desktop
app, and shipping it in an MIT package would drag the whole distribution under AGPL. So the
app no longer looks for a Forge install anywhere: it uses the engine it installed itself, and
`"image_command"` in settings.json points it at something else if you keep one anyway.

Where a server *is* running, the request runs in a worker process rather than in the
application loop (thirty seconds of diffusion must not freeze the window), progress comes
from the server's own `/sdapi/v1/progress`, steps and size are clamped
(1–80, 256–1024), and the governor gates the whole thing: **no image is started while a model
is generating, and none while the card is at or above the profile's ceiling.** Diffusion is
the loudest thing this application can do, so it waits its turn like everything else. Results
are written to `~/.config/quiesce/images` and served to the window from `/images/<name>` —
that folder only, never an arbitrary path.

### Editing with a diff

`edit_file` replaces an exact piece of a file and refuses anything ambiguous: text that does
not appear, or appears more than once, is not guessed at — a model that means to change one
function and quietly changes three is worse than one that fails and is told why. The window
shows the change as a diff (green arrivals, red departures) rather than only the request.
Tested: unique-match, no-match, `all: true`, and the refusals outside the working folder.

### Staying inside the window

A real file blows through an 8k context in two reads, and running out silently drops the
oldest turns — including the task. So the history is trimmed deliberately, in order: old
tool results shortened, then replaced by one line saying what the call was, then the oldest
exchanges dropped. Deterministic on purpose (a model-written summary costs a generation and
can fail). The window shows `used / budget tokens` and says when it trimmed.

### Packaging

```bash
php vendor/bin/boson compile   # downloads the PHP backend, assembles per target
./build/linux/amd64/quiesce    # 19 MB, no PHP, no Node, no installer
```

Verified: the binary opens its window, binds the governed endpoint, loads its bundled page,
CSS and script, and reports no missing libraries (`ldd`). Windows, macOS amd64 and macOS
arm64 targets are produced by the same run.

### Which models will actually fit

The advisor computes the KV cache from each model's own metadata — 2 × layers × kv heads ×
head dim × 2 bytes — and compares it with the weights and the card's own VRAM report, then
says what fits the profile's context and which model is the largest that still does. No
tables of assumptions about what a 30B "usually" needs. A test asserts the arithmetic against
qwen3's real shape (48 layers, 4 KV heads, 128 dims).

### Subagents

`delegate` hands a self-contained subtask to a fresh agent with its own context and its own
transcript, and takes back only its answer. It is driven by the same loop rather than beside
it — one generation at a time, through the same governor — so a subagent is not a second GPU
load, it is the next turn in the queue. One level only: a subagent cannot delegate again, or
"quiet" would be negotiable.

### Attachments

`Attach…` opens the native file picker and takes a file from the working folder: text is
inlined (capped, and it says so), a picture is handed to the vision model. A picture offered
to a model that cannot see is **refused with instructions**, because a text-only model handed
an image does not fail — it answers something, confidently and wrongly.

**One measured, uncomfortable fact about small vision models.** moondream on this engine does
not fail; it *stops*, returning one token and `done`. Eight runs in ten answered, then one in
six for the identical request minutes later, and with four payload shapes measured at 2–3
successes in 5 there was no setting that fixed it. What did matter: **a freshly loaded model
answers, a warm one stops** — two answers in a row cold, then five empties warm. So the vision
path now loads the model per attempt, and the chat sends pictures through the vision tool's
own code path rather than a second implementation that behaved differently. Result, measured
through the chat: 3 of 5, where the model itself manages about half.

The remaining gaps, honestly: images are one-shot (no img2img, no inpainting), ComfyUI's
workflow API is not supported (only the A1111 shape), the index is rebuilt by hand rather
than on file change, and a vision answer you can rely on every time needs a better vision
model than a 1B one.

## The agent checks its own work

A tool that says it wrote a file is making a claim. After `write_file` the file is read back and
its size compared with what was sent; after `edit_file` the replacement is looked for in the
file. The result goes in the step, in the window:

```
step 1  write_file hello.txt (11 bytes)
   │ wrote 11 B to hello.txt
   │ ✓ verified: hello.txt is on disk, 11 bytes, as written
step 2  edit_file hello.txt (5 → 5 bytes)
   │ @@ around line 1 @@
   │ - world
   │ + there
   │ ✓ verified: the change is in hello.txt
```

It is specific rather than clever: it does not understand the content, it checks the claim. A
step that fails its check says `✗` and says why.

## Installing it

```bash
tools/install-app.sh            # build if needed, install under ~/.local
tools/install-app.sh --uninstall
```

One file, an icon and a menu entry: `~/.local/lib/quiesce/quiesce` (19 MB), the scripts it
spawns beside it, icons in `hicolor`, and `~/.local/share/applications/quiesce.desktop` —
which `desktop-file-validate` accepts. Then it is in your menu as **Quiesce**.

**The bug that only appeared once installed.** Compiled, `__DIR__` is a `phar://` path — inside
the bundle — so the app looked for its worker scripts *inside itself*, and a shell cannot exec
something inside a bundle. It worked from a checkout and would have been dead once installed.
The paths are now resolved where they can actually be: beside the app, in the directory it was
launched from, or in `~/.local/share/quiesce/tools`. `QUIESCE_TRACE=1 quiesce` writes
`~/.config/quiesce/startup.log` saying which it found, because this is exactly the kind of
thing that should be checkable rather than assumed.

## Bringing a model

The window's **Models** card lists what is installed with the advisor's verdict next to each
one, and can fetch another: **Check** asks the registry what a model weighs before you commit
to gigabytes, **Get** downloads it through Ollama (so it is resumable and the blobs are shared
with anything else you use).

Measured against what is on this machine, the registry agrees with the disk exactly:

| model | registry says | on disk |
| --- | --- | --- |
| qwen3:8b | 4.9 GB | 4.9 GB |
| moondream | 1.6 GB | 1.6 GB |
| nomic-embed-text | 0.3 GB | 0.3 GB |

Two things the card says plainly rather than implying: the check sizes **weights only** — the
KV cache depends on the context you run it at, and that is only knowable once the model is
here — and a download **leaves your machine**, which nothing else in this application does.

## Small windows, and zoom

The chat is the thing you are using, so it always gets the area, and the side panels get out
of its way:

- **wide** (over 1024px): chat and panels side by side, and the **Panels** button closes them so
  the chat can have the whole window;
- **narrow**: they become a drawer the same button opens; a click outside or `Escape` closes it,
  and the chat keeps the whole window;
- the header sheds its long status labels, the footer goes, the rows wrap, and file paths
  truncate instead of setting a floor under the window's width.

The button sits with the view buttons — Chat, Task, History, **Panels** — and not in the header,
where it sat in the middle of the window. It also used to toggle one class that only the *narrow*
stylesheet read, so on any window wide enough to show the panels anyway, pressing it did nothing
at all. "Panels" is now one button with one meaning per layout, decided with the width in hand,
and the reader's choice outranks the window exactly as the Task settings' fold does.

**Zoom** with the keys you already use: `Ctrl` `+`, `Ctrl` `-`, `Ctrl` `0` to reset. It is
remembered, and it scales the *page* rather than the window, so the layout reflows instead of
being cropped.

Measured by squeezing the body to one pixel inside the running app and reading what it refused
to go below: the page's minimum went from **885×632 to 452×348**. The window can then be
resized to 640×560 or 470×500 and stays usable — verified by resizing a running instance and
looking at it.

**Three bugs, all of which had to be found to get there:**

1. **A specificity bug made the drawer cover the chat.** `.side { display: none }` inside the
   media query was outranked by a later `.pane { display: flex }`, because the element carries
   both classes. The fix is `.pane.side`.
2. **The media queries were in the wrong place.** They were written next to the rules they
   adjust — early in the file — so later rules of equal specificity outranked them, and the
   footer stayed on a 460px window. Every media query now lives at the end, where a breakpoint
   belongs.
3. **WebKit was caching the stylesheet.** Static assets carried no cache headers, so a window
   could render with the *previous* `app.css` — which is what made the two bugs above look
   unfixable for several rounds. Assets are now served `no-store`, which for a desktop app is
   free: they are on the same disk as the binary.

One thing still not explained: in this environment the window manager inflates the *initial*
window size (asking for 520×600 opens at 642×722), and `QUIESCE_SIZE=1000x700` is offered so a
small screen can ask for less. Resizing afterwards works, and the layout copes either way.

### The Task tab, at every size it is asked to be

The Task tab is the one with 600px of settings in it, so it has to be explicit about who gives
up space first:

| part | when the window is short |
|---|---|
| the settings card | gives its height back first, then scrolls inside itself — capped at half the view |
| the conversation | gets what is left, which can be very little |
| the answer | caps itself rather than pushing anything off the window |
| the composer | gives up nothing: it is the box you type in |

The card's header folds it to a line that says what is inside it — `no folder · web on ·
commands on · 1 skill · max 4 steps` — so the tab is complete at a size that cannot hold the
rows. The fold follows the window while the reader has not said otherwise, and follows the
reader once they tap it; a tap is remembered.

**Measured in one run, tapping the header between measurements** (`QUIESCE_TRACE_OPEN=1`),
on a 1150×560 window that the page sees as 1150×513:

```
settings  129..313 (184px) open=true scrollbar=13px   ← 612px of rows, scrolling
steps     323..378  (55px)                            the conversation gives way first
composer  390..497 (107px)                            inside the view, not under it
view      129..497 (368px) needs=368px scrollbar=0px  no overflow left over
clipped   false

settings  129..174  (45px) open=false                 folded: one line
steps     184..378 (194px)
composer  390..497 (107px)
clipped   false
```

**Four bugs, which is what it took:**

1. **The settings never scrolled because nothing said they should.** `overflow-y` was set only
   inside a media query, so at a width above that breakpoint the rows spilled over the
   composer and off the window. It is in the base rules now, at every size.
2. **The fold was decided once and never revisited.** It was worked out on the first poll, so a
   window dragged to half its height kept the arrangement it was drawn with — and the
   remembered value was read *after* the render that used it, so it never applied at all. Now
   the fold is re-derived on every resize *and* every poll, the remembered value is read before
   anything is drawn, and a tap outranks the window. Two tests cover it: the fold moves with a
   window that is resized, and a tap outranks the window.
3. **`.task-rows` was never closed in `index.html`.** One missing `</div>` put the conversation,
   the answer and the composer *inside* the settings card. The trace is what found it: the
   conversation's rectangle was sitting *inside* the card's, which no layout can do.
4. **`main { display: block }` on narrow windows left the pane with an indefinite height.** A
   percentage `max-height` against an indefinite height computes to `none`, so the half-view cap
   silently stopped existing on every window under 1024px wide: the card grew to **663px on a
   431px-tall window** and pushed the composer off it. Narrow windows get a one-column grid
   whose row fills the window instead, which is definite, so the cap is real.

**Scrollbars.** WebKitGTK draws overlay scrollbars: they fade out when the mouse is still, so a
card that scrolls perfectly well looks exactly like a card that has been cut off — which is how
"the settings still do not scroll" was reported three times while they were scrolling all along,
invisibly. Styling `::-webkit-scrollbar` switches to the classic kind, which stays: measured as
a **13px** scrollbar above, and visible in a screenshot of the open card.

**The one case that is still uncomfortable** is a window about 260px tall (430px at this
display's scale): the composer, the folded card and the gaps between them need roughly 160px, so
below that the *view* scrolls rather than cutting anything off. Nothing is unreachable; it is
just a window with no room in it.

### Text that was being cut off

Two strings were being clipped rather than wrapped, and both were the same mistake in different
clothes: **a box cannot be narrower than its longest unbreakable word**, and an English sentence
of CSS does not say so.

- The **endpoint URLs** in the side panel are what an agent is pointed at, and the panel was
  showing `OPENAI_BASE_URL=http://127.0.0.1:114` — the `35/v1` simply gone. There is no space in
  that string to wrap at, so the flex item's automatic minimum width was the whole line and the
  panel clipped it. `min-width: 0` plus `overflow-wrap: anywhere` lets it be narrow (`break-word`
  would not have: it only affects lines that already wrap), and the line is now two block boxes —
  the name, then the address on its own line — so the break lands on the `=` instead of in the
  middle of an address. Block display adds no characters, so what click-to-copy puts on the
  clipboard is still the whole line, and the trace prints it every five seconds to keep that true:

  ```
  copy      OLLAMA_HOST=http://127.0.0.1:11435  |  OPENAI_BASE_URL=http://127.0.0.1:11435/v1
  ```

- The **Jobs** form's "every N minutes" field was 46px instead of 72px, which cut the digits off
  it. `.job-form input[type="text"] { width: 100% }` also matched the *second* input in the row
  below, and a `width: 100%` on a flex item is a flex basis as wide as the card — which the flex
  algorithm paid for by shrinking the number field beside it. The selector is `.job-form >
  input[type="text"]` now, and the field is `flex: 0 0 72px`.

**The trace finds this class of bug now, so nobody has to notice it in a screenshot.** Every five
seconds it lists elements whose text is wider than their box, excluding the two cases where that
is deliberate (text ending in an ellipsis, and boxes that scroll sideways on purpose):

```
cut off   2 — endpoint-openai 240<266, job-every 46<53      ← the reported state
cut off   nothing                                          ← after the fix
```

## The leash, and the net

The agentic half of the app was honest about what it *could* do and silent about what it
*could not*. Four things, all of them now in the loop:

### 1. The digest rung, instead of a name

`Context` trimmed in three rungs and the second one lost the work: an old result became
`(earlier result, shortened: read_file notes.md)` — the name of the call and nothing about
it. A model four steps later cannot tell from that whether it has read the file or merely
asked for it, so it re-reads everything or, worse, invents the difference.

The rung now leaves a **digest**: the call with its arguments, how much came back, and the
first line of it.

```
(digest: read_file(path=notes.md) → 41 line(s), 2.4 KB · Invoice 4021 — paid)
```

Every part of that is counted from the transcript — the call from the assistant message that
asked for it, the size and the first line from the result itself. Nothing is composed, so
nothing can be wrong; a model-written summary is a paragraph that can be confidently wrong,
and this is the one application where that failure is not allowed. Rung 1 keeps the newest
results whole, rung 2 digests the older ones, rung 3 drops only if it still does not fit,
and the system prompt and the task are pinned throughout.

### 2. The leash, in the reader's hands

The Task card has **Room** and **steps at most** now: the window a task may use, and how many
generations it may take before it stops and answers. The profile stays the ceiling — `Guard`
clamps whatever is asked for, which is the promise the app makes about the card — and when
the two differ, the app says so three times: in the note under the controls, in the governor
log when the task starts, and on the context row while it runs.

```
this task: up to 4 step(s) in a 8,192-token window. 32,768 was asked for and 8,192 is this
profile's ceiling, so the ceiling is what it gets — Fast is how you ask for a larger window
```

Worth stating plainly, because it is not obvious: **a larger window costs VRAM and time, not
watts.** What heats the card is sustained duty, and the duty cycle does not depend on the
window at all. So a reader can have a bigger room without giving up the quiet — which is the
whole reason the 8k ceiling was ever a problem rather than a feature.

### 3. The chat has the ladder too

It did not, and that was the last place a long conversation could fail *silently*: past the
window, Ollama drops the oldest turns and the model answers as if the question had never been
asked. The exact failure `Context` exists to prevent, live on the most-used path.

What is trimmed is the **request**, not the conversation. The JSON file keeps every turn the
reader wrote; the window says what the request left out. An application that quietly edited
your history to fit its own window would be a worse failure than the one being fixed.

### 4. Checkpoints, and one-button undo

Writes were verified (`stat()` and a read-back before the agent claims anything) and commands
had a gate. What was missing was the other half: a write that *did* happen and was wrong. Every
`write_file` and `edit_file` now copies what was there aside first, into
`~/.config/quiesce/checkpoints/` — readable with `cat`, not a mystery inside the app — and
**Undo last write** in the Task card puts the newest one back.

Measured through the headless harness (`php tools/task.php`, the same loop), on a real folder:

```
[ 11.2s] step 3  write_file summary.md (21 bytes)
        │ wrote 21 B to summary.md
        │ ✓ verified: summary.md is on disk, 21 bytes, as written

$ cat ~/.config/quiesce/checkpoints/checkpoints.json
[{ "tool": "write_file", "root": "/tmp/quiesce-leash", "path": "summary.md", "existed": false }]

undid write_file: summary.md was created by the agent and has been removed
held after: 0
again: nothing to undo: no write has been checkpointed since the app started
```

One write at a time, deliberately: a button that rewrites forty files at once is a bigger risk
than the mistake it guards. A file the agent created is *removed*, and the journal says so
rather than pretending something was restored. The journal is capped at forty entries, and a
file larger than a megabyte is refused the net while the write still goes ahead — with the
result saying `not checkpointed, so that change cannot be undone`, because refusing the write
would be worse and implying the net exists would be dishonest. If the workspace has moved or
the path no longer resolves inside it, undo refuses and keeps the entry rather than writing
somewhere it cannot vouch for.

**Still not built, deliberately: model-written compaction.** The digest is the mechanical half
of it, and for an 8k window it is most of the value — it preserves the shape of the work
without spending a generation, failing, or being able to lie. A model's paragraph belongs at
the bottom of that ladder, behind a threshold with hysteresis, through the governor, and
checkpointed like anything else — one rung further down than the one that was needed.

## Packaging and updating

```bash
tools/package.sh --update-manifest   # tarball, .deb, SHA256SUMS, update.json
tools/update.sh --check   --manifest=dist/update.json
tools/update.sh --install --manifest=dist/update.json
```

**Measured:** a 9.7 MB tarball and an 8.0 MB `.deb` (control metadata and file layout checked
with `dpkg-deb`), and the tarball extracted and installed into a clean prefix really does run —
`Quiesce 0.1.0 (PHP 8.4.13)`, and the startup trace shows it finding its own scripts there:

```
cwd:     /tmp/quiesce-prefix/lib/quiesce
worker:  /tmp/quiesce-prefix/lib/quiesce/tools/generate.php
starter: /tmp/quiesce-prefix/lib/quiesce/tools/image-server.sh
```

**What the updater gives you, and what it does not.** A tampered artifact is *refused*, with both
hashes printed:

```
REFUSED: the file does not match the manifest
  manifest says 069e389ed9964d1e0735b28c4679cafaf5bf61c1d3b9f9f17f92822cdb435c11
  file is       91e453a2491d84fb822ec32199201f88ac93f82264fc025c40b2409e453b29d6
```

That is **integrity, not authenticity**: a corrupted or truncated download is caught; a manifest
served by somebody else would be believed. Signing is the step that turns this into a real
update path and it needs a key, which this project does not have. The `update.json` says so in
its own note.

**macOS and Windows are scripts, not products.** `tools/package-macos.sh` builds a `.app` bundle
and `tools/package-windows.ps1` stages a folder for Inno Setup. Both are labelled at the top
with the truth: *written on Linux, never executed*. The compiler already produces those binaries;
nothing here has run one on the platform it is for.

## Conversations

Every conversation is a JSON file in `~/.config/quiesce/chats`, titled with the first thing you
asked. The sidebar lists them, newest first; clicking one brings it back, `×` forgets it, and
image data is deliberately not reloaded into a continued conversation — it is megabytes per
picture and the model would be charged for it on every turn.

Three things that make a bad answer recoverable rather than fatal: **Ask again** re-asks the
last question without its answer, **Edit last question** puts it back in the box to be changed —
sending then replaces it *and everything after it*, because those were answers to something
else — and the search box finds a conversation by what was said in it, not only by its title.

## Layout

```
index.php                        the window, and the one timer that drives everything
src/Http.php, HttpStream.php     loopback-only HTTP, stepped rather than blocking
src/Ollama.php                   the engine: list, show, load, unload, start, chat
src/Hardware.php                 what the card says about itself, read not assumed
src/Governor.php                 the profiles, the gate, the rolling budget, the log
src/Guard.php                    the governed endpoint: queue, pace, hold, translate
src/ChatSession.php              the window's own conversation, a client like any other
src/Agent.php                    the task loop: think, call a tool, answer
src/Tools.php                    the tools, and the folder and address rules they obey
src/Rpc.php                      the bindings the window is allowed to call
src/Settings.php                 the few things worth remembering, in ~/.config/quiesce
assets/private/                  the page, the stylesheet, the script (all bundled)
tools/harness.php                run the endpoint headless, one measured line a second
tools/task.php                   run one task headless, with every step timed
```

Two notes for whoever works on this next:

- **One timer only.** `SaucerPoller::executePeriodicTask()` runs the *first* registered
  periodic task and returns, so a second `poller->timer()` never fires. Everything on a
  clock goes inside the single 120 ms callback in `index.php`, with a counter for the
  jobs that are not wanted every tick.
- **Style every native widget, or it will look like a hole.** WebKitGTK paints light
  controls whatever the page is doing: `<input>` (which the Jobs form was the first to
  use, and so the first to expose), the scrollbars, and the `<select>` menulist. Each of
  those is now styled in `assets/private/css/app.css` with a comment saying why. The one
  surface still drawn by the platform is the dropdown popup itself — `option` colours are
  a hint, not a guarantee.
- **The window waits for its bindings.** `assets/private/js/app.js` polls for
  `window.qState` and retries for 30 s before giving up, because the bound functions are
  injected at DOM-ready and the page's script can beat them there. Without that wait the
  window renders and then sits there looking alive and doing nothing.

## Licence

MIT, © 2026 Kenneth York. Built on [Boson](https://github.com/boson-php/boson) (MIT).
