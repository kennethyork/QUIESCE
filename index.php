<?php

declare(strict_types=1);

/**
 * Quiesce — a desktop application for local models that stays quiet.
 *
 * Vanilla PHP, HTML, CSS and JavaScript on top of Boson. No framework, no build
 * step, no Node, no bundler: the files in this directory are the program.
 *
 * What it guarantees, and where the guarantee lives:
 *   - desktop only      one native window; no server to open, no browser, no phone
 *   - local only        every socket in this application is loopback; see App\Http
 *   - ollama only       one engine, on 127.0.0.1:11434, and no provider list
 *   - quiet             App\Governor, enforced in App\Guard — in front of Ollama,
 *                       so agent loops that never touch this window are governed too
 */

use App\Advisor;
use App\Attachments;
use App\Agent;
use App\ChatSession;
use App\Chats;
use App\Checkpoints;
use App\Context;
use App\Documents;
use App\Jobs;
use App\Mcp;
use App\Models;
use App\FrontController;
use App\Governor;
use App\Guard;
use App\Hardware;
use App\Images;
use App\Ollama;
use App\Rpc;
use App\Sessions;
use App\Settings;
use App\Shell;
use App\Skills;
use App\Tools;
use App\Vision;
use Boson\Application;
use Boson\ApplicationCreateInfo;
use Boson\Component\Http\Static\FilesystemStaticProvider;
use Boson\WebView\Api\Schemes\Event\SchemeRequestReceived;
use Boson\WebView\WebViewCreateInfo;
use Boson\Window\WindowCreateInfo;
use Boson\Window\WindowDecoration;

require __DIR__ . '/vendor/autoload.php';

/*
 * A packaged app should be able to say what it is. `quiesce --version` answers
 * without opening a window, which is what a packager, an updater or a person
 * checking an install needs.
 */
$arguments = \array_slice($_SERVER['argv'] ?? $argv ?? [], 1);

foreach ($arguments as $argument) {
    if ($argument === '--version' || $argument === '-v') {
        $version = @\file_get_contents(__DIR__ . '/VERSION') ?: '0.1.0';
        \fwrite(\STDOUT, 'Quiesce ' . \trim($version) . " (PHP " . \PHP_VERSION . ")\n");
        exit(0);
    }

    if ($argument === '--help' || $argument === '-h') {
        \fwrite(\STDOUT, "Quiesce — local models, on a machine that stays yours.\n\n"
            . "  quiesce              open the window\n"
            . "  quiesce --version    print the version\n\n"
            . "Everything else lives in the window: models, conversations, jobs, images.\n");
        exit(0);
    }
}

/*
 * One instance, deliberately.
 *
 * A second copy cannot bind the governed endpoint, and — more confusingly — it
 * contends for the same application id, so it can start without ever drawing a
 * window: something that looks exactly like a broken build. Say so plainly
 * instead of opening a window that never appears.
 */
$running = @\fsockopen('127.0.0.1', 11435, $errorCode, $errorMessage, 0.3);

if (\is_resource($running)) {
    @\fclose($running);

    \fwrite(\STDERR, "Quiesce is already running (the governed endpoint on port 11435 is in use).\n");

    exit(0);
}

$home = (string) (\getenv('HOME') ?: \sys_get_temp_dir());
$configRoot = (string) (\getenv('XDG_CONFIG_HOME') ?: $home . '/.config');
$configDirectory = $configRoot . '/quiesce';

/* -------------------------------------------------------------------------
 *  Settings: a file in the reader's own config directory, and nothing else
 * ---------------------------------------------------------------------- */

$settings = new Settings($configDirectory . '/settings.json');
$governor = new Governor();

$remembered = $settings->get('profile');

if (\is_string($remembered)) {
    $governor->usePreset($remembered);
}

/* -------------------------------------------------------------------------
 *  The window
 * ---------------------------------------------------------------------- */

$debug = (bool) \filter_var(\getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);

// A window taller than the screen is a window with its buttons off it. The default
// is modest, and `QUIESCE_SIZE=1000x620 quiesce` starts it smaller still — the
// layout copes either way, which is what the responsive rules above are for.
$width = 1180;
$height = 760;

$requested = (string) \getenv('QUIESCE_SIZE');

if (\preg_match('/^(\d{3,4})x(\d{3,4})$/', $requested, $size) === 1) {
    $width = \max(420, (int) $size[1]);
    $height = \max(420, (int) $size[2]);
}

$app = new Application(new ApplicationCreateInfo(
    schemes: ['boson'],
    debug: $debug,
    window: new WindowCreateInfo(
        title: 'Quiesce',
        width: $width,
        height: $height,
        decoration: WindowDecoration::DarkMode,
        webview: new WebViewCreateInfo(
            devTools: $debug,
            extensions: [...WebViewCreateInfo::DEFAULT_WEBVIEW_EXTENSIONS],
        ),
    ),
));

/* -------------------------------------------------------------------------
 *  Assets
 * ---------------------------------------------------------------------- */

$static = new FilesystemStaticProvider([
    __DIR__ . '/assets/private',
    __DIR__ . '/assets/public',
]);

$controller = new FrontController($static, __DIR__ . '/assets/private/view/index.html', $configDirectory . '/images');

$app->on(static function (SchemeRequestReceived $e) use ($controller): void {
    $e->response = $controller($e->request);
});

/* -------------------------------------------------------------------------
 *  The machine, the engine, and the governor
 * ---------------------------------------------------------------------- */

$hardware = new Hardware();
$ollama = new Ollama(logPath: $configDirectory . '/ollama.log');
$guard = new Guard($ollama, $governor, $hardware);

$listening = $guard->listen();

if (!($listening['ok'] ?? false)) {
    $governor->note('endpoint', 'the governed endpoint is not listening — generations will be refused');
}

$shell = new Shell($settings);
$sessions = new Sessions($configDirectory . '/sessions');
$skills = new Skills($configDirectory . '/skills');
$documents = new Documents($settings, $ollama, $configDirectory . '/embeddings.json');
$jobs = new Jobs($configDirectory . '/jobs.json');
$mcp = new Mcp($configDirectory . '/mcp.json');
$models = new Models($ollama, $configDirectory . '/model-sizes.json');

$vision = new Vision($settings, $ollama);
$attachments = new Attachments($settings, $vision);
$context = new Context($ollama);
$checkpoints = new Checkpoints($configDirectory . '/checkpoints');
$advisor = new Advisor($ollama, $hardware);
$images = new Images($settings, $configDirectory . '/images', __DIR__ . '/tools/generate.php');
$attachments = new Attachments($settings, $vision);
$chats = new Chats($configDirectory . '/chats');
$chat = new ChatSession($app, $ollama, $governor, $guard, $attachments, $vision, $chats, $context);
$tools = new Tools($settings, $shell, $skills, $documents, $mcp, $vision, $checkpoints);
$agent = new Agent($app, $governor, $tools, $settings, $shell, $sessions, $skills, $documents, $mcp, $ollama, $context);

$rpc = new Rpc($app, $hardware, $governor, $ollama, $guard, $chat, $settings, $tools, $agent, $shell, $sessions, $skills, $documents, $jobs, $mcp, $vision, $images, $advisor, $attachments, $chats, $models);
$rpc->register();

/* -------------------------------------------------------------------------
 *  The loop
 *
 *  Both timers run in the same single-threaded loop, which is what makes the
 *  pacing honest: while the governor holds a generation, this process is doing
 *  nothing else that could heat the card on its behalf.
 * ---------------------------------------------------------------------- */

/*
 * ONE timer, deliberately.
 *
 * Boson's loop runs only the *first* registered periodic task in each cycle
 * (`SaucerPoller::executePeriodicTask()` returns after the first callback), so
 * a second timer is a timer that never fires — which is exactly how a window
 * ends up looking alive and never updating. Everything this application does on
 * a clock therefore happens inside this one callback, with a counter for the
 * jobs that are not wanted every 120 ms.
 */
$clock = new class () {
    public int $ticks = 0;
    public ?string $jobId = null;
    public float $imageIdleSince = 0.0;
};

/**
 * Push a line of JavaScript into the window, and shrug if it is not there yet.
 */
$push = static function (string $event, array $payload) use ($app): void {
    try {
        $app->webview->scripts->eval(\sprintf(
            'window.quiesce && window.quiesce.%s(%s);',
            $event,
            \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}',
        ));
    } catch (\Throwable) {
        // The window may be closing. A dropped UI update is not worth an exception.
    }
};

$app->poller->timer(0.12, static function () use ($app, $guard, $chat, $agent, $mcp, $jobs, $images, $models, $ollama, $governor, $settings, $configDirectory, $clock, $push): void {
    $guard->tick();
    $chat->tick();
    $agent->tick();
    $mcp->tick();
    $clock->ticks++;

    /* ---------------------------------------------------------------------
     *  Jobs
     *
     *  A due job waits for an idle machine rather than getting its own lane:
     *  no task running, nothing in the guard's queue, no chat answer in flight.
     *  A background job running beside a foreground one is how a quiet machine
     *  stops being quiet, so the check is deliberately strict.
     * ------------------------------------------------------------------ */

    $agentState = $agent->state();

    if ($clock->jobId !== null && !$agent->running()) {
        $jobs->markRan($clock->jobId, (string) $agentState['state'], (float) $agentState['seconds']);
        $governor->note('job', 'finished ' . $clock->jobId . ' (' . (string) $agentState['state'] . ')');
        $clock->jobId = null;
    }

    if ($clock->jobId === null && $clock->ticks % 40 === 0) {
        $busy = $guard->stats()['inflight'] !== null || $chat->state()['state'] === 'generating';

        if (!$agent->running() && !$busy) {
            $due = $jobs->due(\microtime(true));

            if ($due !== []) {
                $job = $due[0];
                $model = (string) ($job['model'] ?? '');

                if ($model === '') {
                    $model = (string) $settings->get('model', 'qwen3:8b');
                }

                $started = $agent->run((string) ($job['task'] ?? ''), $model, (string) ($job['id'] ?? ''));

                if (($started['ok'] ?? false) === true) {
                    $clock->jobId = (string) ($job['id'] ?? '');
                    $governor->note('job', 'running ' . $clock->jobId . ' — it waits its turn like everything else');
                } else {
                    $jobs->markRan((string) ($job['id'] ?? ''), 'could not start: ' . (string) ($started['error'] ?? ''), 0.0);
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     *  Image generation: a worker process, watched rather than waited for.
     * ------------------------------------------------------------------ */

    if ($models->running() && $clock->ticks % 8 === 0) {
        $progress = $models->progress();

        if ($progress === null || !$progress['done']) {
            $push('onModelPull', $progress ?? []);
        } else {
            $finished = $models->finish();

            $governor->note('model', $finished['ok']
                ? 'downloaded ' . $finished['name'] . ' in ' . $finished['seconds'] . 's — it is in the model list now'
                : 'download of ' . $finished['name'] . ' failed: ' . $finished['error']);

            $push('onModelPull', $progress + ['done' => true]);
        }
    }

    if ($images->running() && $images->kind() === 'install' && $clock->ticks % 16 === 0) {
        // Show the installer moving. A four-minute silent download is
        // indistinguishable from a four-minute hang.
        $images->poll();
        $governor->note('image', 'installing an engine: ' . ($images->latest() ?: 'working…'));
    }

    if ($images->running()) {
        $poll = $images->poll();

        if ($clock->ticks % 8 === 0) {
            $progress = $images->progress();

            $push('onImageProgress', [
                'prompt' => $images->prompt(),
                'seconds' => $poll['seconds'],
                'progress' => $progress['progress'] ?? null,
            ]);
        }

        if (!$poll['running']) {
            $done = $images->collect();
            $images->prune();

            if ($images->kind() === 'install') {
                $governor->note('image', $done['ok']
                    ? 'engine installed — Start engine will use it'
                    : 'the engine install failed: ' . $done['error']);

                $push('onImageServer', ['installed' => $done['ok'], 'error' => $done['error']]);
            }

            $push('onImageDone', [
                'ok' => $done['ok'],
                'files' => $done['files'],
                'error' => $done['error'],
                'seconds' => $done['seconds'],
                'prompt' => $images->prompt(),
            ]);

            $governor->note('image', $done['ok']
                ? 'drew ' . \implode(', ', $done['files']) . ' in ' . $done['seconds'] . 's'
                : 'image failed: ' . $done['error']);

            $clock->imageIdleSince = \microtime(true);
        }
    }

    /*
     * An image engine holds gigabytes of video memory while it sits idle, which is
     * the opposite of what this application is for. If *this app* started it and
     * nothing has been drawn for a while, it is stopped again — and the log says
     * so, because a machine that quietly frees resources should also say that it
     * did.
     */
    $idleMinutes = (int) ($settings->get('image_idle_stop') ?? 10);

    if ($idleMinutes > 0 && $clock->imageIdleSince > 0.0
        && $images->serving()['started_by_us']
        && \microtime(true) - $clock->imageIdleSince > $idleMinutes * 60) {
        $clock->imageIdleSince = 0.0;
        $stopped = $images->stopServing();

        $governor->note('image', 'image engine stopped after ' . $idleMinutes
            . ' idle minutes — the video memory goes back to the quiet budget');
        $push('onImageServer', ['available' => false, 'stopped' => true, 'output' => (string) ($stopped['output'] ?? '')]);
    }

    /*
     * A page cannot be narrower than its content, and a window cannot be narrower
     * than its page.
     *
     * `QUIESCE_TRACE=1` writes down what the layout actually did, every five
     * seconds, for the view the reader is looking at: which way the Task settings
     * are folded, whether that card is scrolling (`content` past the box, with a
     * `scrollbar` that is really there), whether the composer is still inside the
     * view rather than under it, and whether any text on the page is being cut off
     * rather than wrapped or deliberately truncated.
     *
     * A screenshot is a claim about one moment, and one taken mid-repaint is not
     * evidence at all. These are the numbers behind it — written from the
     * application's own timer, because a second timer would never fire (see the note
     * on the poller above).
     */
    if (\getenv('QUIESCE_TRACE') && $clock->ticks % 40 === 0) {
        try {
            $layout = $app->webview->data->get(
                "(function () {"
                . " var q = function (s) { return document.querySelector(s); };"
                . " var box = function (n) { if (!n) { return 'missing'; }"
                . "   var r = n.getBoundingClientRect();"
                . "   return Math.round(r.top) + '..' + Math.round(r.bottom) + ' (' + Math.round(r.height) + 'px)'; };"
                . " var wide = function (n) { return n ? (n.offsetWidth - n.clientWidth) + 'px' : '-'; };"
                . " var how = function (n) { if (!n) { return ''; } var s = getComputedStyle(n);"
                . "   return ' overflow=' + s.overflowY + ' min=' + s.minHeight + ' max=' + s.maxHeight; };"
                . " var bottom = function (n) { return n ? Math.round(n.getBoundingClientRect().bottom) : 0; };"
                // The view on screen decides which scroller and which composer are the
                // ones worth measuring: a hidden view measures as zeroes.
                . " var shown = q('.view:not([hidden])'), chat = shown && shown.id === 'view-chat';"
                . " var bar = q('.task-bar'), rows = q('.task-rows');"
                . " var steps = chat ? q('#transcript') : q('#steps');"
                . " var form = chat ? q('#composer') : q('#task-form');"
                // Text that is cut off, as opposed to text that ends in an ellipsis on
                // purpose or lives in a box that scrolls on purpose. `OPENAI_BASE_URL=
                // http://127.0.0.1:11435/v1` in a narrow panel is what this is for: the
                // end of it was simply gone, and no screenshot says which rule caused it.
                . " var cut = Array.from(document.querySelectorAll('*')).filter(function (n) {"
                . "   if (!n.clientWidth) { return false; }"
                . "   var s = getComputedStyle(n);"
                . "   if (s.textOverflow === 'ellipsis') { return false; }"
                . "   if (s.overflowX === 'auto' || s.overflowX === 'scroll') { return false; }"
                . "   return n.scrollWidth > n.clientWidth + 2; });"
                . " var cutNames = cut.slice(0, 6).map(function (n) {"
                . "   return String(n.id || n.className || n.tagName) + ' ' + n.clientWidth + '<' + n.scrollWidth; });"
                . " return 'viewport  ' + window.innerWidth + 'x' + window.innerHeight"
                . "   + '  view=' + (shown ? shown.id : 'none')"
                . "   + (chat ? '' : bar ? '\\nsettings  ' + box(bar) + ' open=' + bar.dataset.open + ' scrollbar=' + wide(bar) + how(bar) : '')"
                . "   + (chat ? '' : rows ? '\\nrows      content=' + rows.scrollHeight + 'px scrollbar=' + wide(rows) + how(rows) : '')"
                . "   + (steps && !chat ? '\\nsteps     ' + box(steps) : '')"
                . "   + (steps && chat ? '\\ntranscript ' + box(steps) + ' scrollbar=' + wide(steps) : '')"
                . "   + (form ? '\\ncomposer  ' + box(form) : '')"
                . "   + (shown ? '\\nview      ' + box(shown) + ' needs=' + shown.scrollHeight + 'px scrollbar=' + wide(shown) : '')"
                . "   + '\\nclipped   ' + (shown && form ? (bottom(form) > bottom(shown) + 1) : '?')"
                . "   + '\\ncut off   ' + (cut.length ? cut.length + ' — ' + cutNames.join(', ') : 'nothing')"
                // What click-to-copy would actually put on the clipboard. The endpoint
                // lines are two block boxes now, so this is where that stays honest:
                // `textContent` has to be the whole line, exactly.
                . "   + '\\ncopy      ' + (q('#endpoint-openai')"
                . "       ? q('#endpoint-ollama').textContent + '  |  ' + q('#endpoint-openai').textContent"
                . "       : 'not in this view')"
                . "   + '   scrollbar-chrome=' + (function () {"
                . "       try { var rs = document.styleSheets[0].cssRules;"
                . "         for (var i = 0; i < rs.length; i++) {"
                . "           if (String(rs[i].selectorText || '').indexOf('webkit-scrollbar') >= 0) { return 'styled'; } }"
                . "       } catch (e) { return 'unreadable'; }"
                . "       return 'none'; })();"
                . "})()",
                3.0,
            );

            // What the page *needs*, independent of how big the window happens to be:
            // squeeze the body to nothing and see what it refuses to go below.
            $needs = $app->webview->data->get(
                "(function () { var b = document.body; var was = b.style.width;"
                . " b.style.width = '1px'; var need = b.scrollWidth + 'x' + b.scrollHeight;"
                . " b.style.width = was; return need; })()",
                3.0,
            );

            @\file_put_contents($configDirectory . '/startup.log', \date('H:i:s') . '  '
                . (\is_scalar($layout) ? (string) $layout : 'no measurement')
                . '\\npage wants  ' . (\is_scalar($needs) ? (string) $needs : '?') . "\\n\\n", \FILE_APPEND);

            /*
             * `QUIESCE_TRACE_OPEN=1` taps the Task settings header between
             * measurements, so a short window gets measured with the rows open and
             * again with them folded — the state the reader complained about, not the
             * convenient one. Driving the window's own buttons from outside needs a
             * mouse at a known place on a screen, which is exactly the kind of
             * assumption that produced three rounds of believing a screenshot.
             */
            if (\getenv('QUIESCE_TRACE_OPEN')) {
                $app->webview->scripts->eval("document.getElementById('task-toggle').click();");
            }
        } catch (\Throwable $e) {
            @\file_put_contents($configDirectory . '/startup.log', 'layout measure failed: ' . $e->getMessage() . "\\n", \FILE_APPEND);
        }
    }

    // Every ~8s: is the engine still there?
    if ($clock->ticks % 66 === 0 && !$ollama->probe()['up']) {
        $governor->note('engine', 'ollama is not answering on ' . Ollama::HOST . ':' . Ollama::PORT);
    }

    // `QUIESCE_TRACE=1 php index.php` writes one line a second, which answers
    // "did the loop stop, or is the window empty?" without a debugger.
    if (\getenv('QUIESCE_TRACE') && $clock->ticks % 8 === 0) {
        @\file_put_contents($configDirectory . '/loop.log', \date('H:i:s') . ' tick ' . $clock->ticks . "\n", \FILE_APPEND);
    }
});

if (\getenv('QUIESCE_TRACE')) {
    // Where the app thinks its worker scripts are. In a compiled build those are
    // files next to the binary, and this is how that gets checked rather than
    // assumed.
    @\file_put_contents($configDirectory . '/startup.log', \sprintf(
        "%s\ncwd:     %s\nworker:  %s\nstarter: %s\nimage:   %s\n",
        \date('c'),
        \getcwd(),
        $images->workerPath() ?? 'MISSING',
        $images->starter() ?? 'MISSING',
        $images->url(),
    ), \FILE_APPEND);
}

$app->webview->url = 'boson://index';
$app->run();
