<?php

declare(strict_types=1);

/**
 * Run a task without the window — the same loop, the same governor, in a terminal.
 *
 *     php tools/task.php "read notes.md and write a three-line summary to summary.md" \
 *         --workspace=/path/to/folder --model=qwen3:8b --profile=whisper
 *
 * Useful for two things: watching the steps with a terminal's honesty (each one
 * timed, each one through the governed endpoint), and having an agent you can
 * use while the app is closed.
 */

use App\Agent;
use App\Governor;
use App\Guard;
use App\Checkpoints;
use App\Context;
use App\Documents;
use App\Hardware;
use App\Mcp;
use App\Ollama;
use App\Sessions;
use App\Settings;
use App\Shell;
use App\Skills;
use App\Tools;
use App\Vision;

require __DIR__ . '/../vendor/autoload.php';

$arguments = $argv;
\array_shift($arguments);

$options = [];
$words = [];

foreach ($arguments as $argument) {
    if (\str_starts_with($argument, '--')) {
        [$key, $value] = \array_pad(\explode('=', \substr($argument, 2), 2), 2, '1');
        $options[$key] = $value;
    } else {
        $words[] = $argument;
    }
}

$task = \trim(\implode(' ', $words));

if ($task === '') {
    \fwrite(\STDERR, "usage: php tools/task.php \"what to do\" [--workspace=/path] [--model=name] [--profile=whisper|steady|fast] [--steps=6] [--web=1]\n");
    exit(1);
}

$home = (string) (\getenv('HOME') ?: \sys_get_temp_dir());
$configRoot = (string) (\getenv('XDG_CONFIG_HOME') ?: $home . '/.config');
$configDirectory = $configRoot . '/quiesce';

$settings = new Settings($configDirectory . '/settings.json');

if (isset($options['workspace'])) {
    $settings->set('workspace', $options['workspace']);
}

if (isset($options['steps'])) {
    $settings->set('max_steps', (int) $options['steps']);
}

if (isset($options['web'])) {
    $settings->set('web', $options['web'] === '1');
}

$ollama = new Ollama(logPath: $configDirectory . '/ollama.log');

$shell = new Shell($settings);
$skills = new Skills($configDirectory . '/skills');
$documents = new Documents($settings, $ollama, $configDirectory . '/embeddings.json');
$vision = new Vision($settings, $ollama);
$context = new Context($ollama);
// The same net the window has: the harness runs the same tools, so a write it makes
// must be as undoable as one the window makes.
$checkpoints = new Checkpoints($configDirectory . '/checkpoints');
$sessions = new Sessions($configDirectory . '/sessions');
$mcp = new Mcp($configDirectory . '/mcp.json');
$tools = new Tools($settings, $shell, $skills, $documents, $mcp, $vision, $checkpoints);

if (isset($options['shell'])) {
    $shell->setEnabled($options['shell'] === '1');
}

if ($tools->workspace() === null) {
    \fwrite(\STDERR, "no working folder: pass --workspace=/some/folder (or choose one in the window first)\n");
    exit(1);
}

$governor = new Governor();
$governor->usePreset(\is_string($options['profile'] ?? null) ? $options['profile'] : (string) ($settings->get('profile') ?: 'steady'));

$hardware = new Hardware();
$guard = new Guard($ollama, $governor, $hardware);

$listening = $guard->listen();

if (!($listening['ok'] ?? false)) {
    \fwrite(\STDERR, 'could not listen on ' . Guard::HOST . ':' . Guard::PORT . ': ' . (string) ($listening['error'] ?? 'unknown') . "\n");
    exit(1);
}

if (!$ollama->probe()['up']) {
    \fwrite(\STDERR, 'ollama is not answering on ' . Ollama::HOST . ':' . Ollama::PORT . "\n");
    exit(1);
}

$agent = new Agent(null, $governor, $tools, $settings, $shell, $sessions, $skills, $documents, $mcp, $ollama, $context);

// `--index` builds the semantic index and stops, so the folder can be prepared
// before a task needs it (embedding a folder is slower than asking one question).
if (isset($options['index'])) {
    \printf("indexing %s with %s\n", (string) $tools->workspace(), (string) $documents->embeddingModel());

    $guardRounds = 0;

    while (($step = $documents->index(32))['ok'] && $step['remaining'] > 0 && $guardRounds++ < 200) {
        \printf("  %d left\n", $step['remaining']);
    }

    $status = $documents->indexStatus();

    \printf("done: %d chunks stored with %s\n", $status['vectors'], (string) $status['model']);
    exit($step['ok'] ? 0 : 1);
}
$yes = isset($options['yes']);
$model = (string) ($options['model'] ?? $settings->get('model', 'qwen3:8b'));

\printf(
    "task: %s\nmodel: %s   profile: %s   folder: %s   web: %s\n\n",
    $task,
    $model,
    $governor->preset(),
    (string) $tools->workspace(),
    $tools->webEnabled() ? 'on' : 'off',
);

// Give the MCP servers a moment to handshake: their tools have to be in the
// list the model is offered on the first turn, not the second.
$mcpDeadline = \microtime(true) + 8.0;

while ($mcp->servers() !== [] && \microtime(true) < $mcpDeadline) {
    $mcp->tick();
    $ready = true;

    foreach ($mcp->status() as $server) {
        if ($server['stage'] !== 'ready') {
            $ready = false;
        }
    }

    if ($ready) {
        break;
    }

    \usleep(100_000);
}

foreach ($mcp->status() as $server) {
    \printf("mcp %s: %s%s\n", $server['id'], $server['stage'], $server['error'] !== '' ? ' — ' . $server['error'] : '');
}

if ($skills->catalogue() !== []) {
    \printf("skills: %s\n", \implode(', ', \array_column($skills->catalogue(), 'name')));
}

$started = \microtime(true);

if (isset($options['resume'])) {
    $result = $agent->resume((string) $options['resume'], $task);
} else {
    $result = $agent->run($task, $model);
}

if (($result['ok'] ?? false) === false) {
    \fwrite(\STDERR, 'could not start: ' . (string) ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

$seen = 0;
$announced = [];
$deadline = \microtime(true) + 900;

// `running()` rather than a list of states: a list is one refactor away from
// leaving the runner standing in the dark while the agent works.
while ($agent->running() && \microtime(true) < $deadline) {
    $guard->tick();
    $mcp->tick();
    $agent->tick();
    \usleep(60_000);

    $state = $agent->state();

    // The same gate as the window, in a terminal's clothes.
    if ($state['awaiting']) {
        \printf("\n  the agent wants to run:\n    %s\n  reason: %s\n", (string) $state['command'], 'it could change something');

        if ($yes) {
            \fwrite(\STDERR, "  (--yes: allowed automatically)\n");
            $agent->approve(true);
        } else {
            echo '  run it? [y/N] ';
            $answer = \trim((string) \fgets(\STDIN));
            $agent->approve(\strtolower($answer) === 'y' || \strtolower($answer) === 'yes');
        }

        continue;
    }

    $count = \count($state['steps']);

    while ($seen < $count) {
        $step = $state['steps'][$seen];

        // A command announces itself while it runs, then prints its output when
        // it finishes: printing a running step's empty output would say nothing.
        if (!empty($step['running'])) {
            if (!isset($announced[$seen])) {
                $announced[$seen] = true;
                \printf("  [%5.1fs] step %d  %s   (running…)\n", \microtime(true) - $started, $step['step'], $step['call']);
            }

            break;
        }

        $seen++;
        \printf(
            "  [%5.1fs] step %d  %s%s  (%d ms)\n%s\n",
            \microtime(true) - $started,
            $step['step'],
            $step['call'],
            $step['machine'] ? '   ← left this machine' : '',
            $step['ms'],
            \preg_replace('/^/m', '        │ ', \substr((string) $step['output'], 0, 600)),
        );
    }
}

$state = $agent->state();
$gpu = $hardware->gpu();

\printf(
    "\n%s in %.1fs — %d tokens, %d steps, governor: %s\n",
    \strtoupper((string) $state['state']),
    \microtime(true) - $started,
    (int) $state['tokens'],
    (int) $state['step'],
    $governor->reason(),
);

if ($gpu !== null) {
    \printf(
        "card afterwards: %s °C, %s W, fan %s%%, busy %s%%   (duty over the window: %.0f%%)\n",
        \number_format((float) $gpu['temp'], 0),
        \number_format((float) $gpu['power'], 0),
        $gpu['fan'] === null ? '—' : \number_format((float) $gpu['fan'], 0),
        $gpu['util'] === null ? '—' : \number_format((float) $gpu['util'], 0),
        $governor->duty() * 100,
    );
}

if ($state['error'] !== '') {
    \fwrite(\STDERR, 'error: ' . (string) $state['error'] . "\n");
}

if ($state['answer'] !== '') {
    echo "\n", $state['answer'], "\n";
}

if (isset($options['trace'])) {
    echo "\n--- endpoint log ---\n";

    foreach (\array_slice($guard->log(), 0, 12) as $entry) {
        echo '  ', \number_format(\microtime(true) - (float) $entry['t'], 3), 's ago  ', $entry['message'], "\n";
    }

    echo '--- state: ', \json_encode([
        'state' => $state['state'],
        'tokens' => $state['tokens'],
        'steps' => \count($state['steps']),
        'error' => $state['error'],
        'messages' => \count($agent->state()['steps']),
    ]), "\n";
}

exit($state['state'] === 'done' ? 0 : 1);
