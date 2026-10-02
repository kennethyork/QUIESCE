<?php

declare(strict_types=1);

namespace App;

use Boson\Application;

/**
 * What the window is allowed to ask of the application.
 *
 * Every binding here is a plain method call on one of the services; there is no
 * second source of truth for state, and no binding that changes a setting the
 * governor does not read. Named `q*` so that the JavaScript in the window reads
 * as one flat surface rather than a namespace puzzle.
 */
final class Rpc
{
    public function __construct(
        private readonly Application $app,
        private readonly Hardware $hardware,
        private readonly Governor $governor,
        private readonly Ollama $ollama,
        private readonly Guard $guard,
        private readonly ChatSession $chat,
        private readonly Settings $settings,
        private readonly Tools $tools,
        private readonly Agent $agent,
        private readonly Shell $shell,
        private readonly Sessions $sessions,
        private readonly Skills $skills,
        private readonly Documents $documents,
        private readonly Jobs $jobs,
        private readonly Mcp $mcp,
        private readonly Vision $vision,
        private readonly Images $images,
        private readonly Advisor $advisor,
        private readonly Attachments $attachments,
        private readonly Chats $chats,
        private readonly Models $models,
    ) {}

    public function register(): void
    {
        $bindings = $this->app->webview->bindings;

        /* ---------- the task loop ---------- */

        $bindings->bind('qTask', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];

            return $this->agent->run(
                (string) ($payload['task'] ?? ''),
                (string) ($payload['model'] ?? $this->settings->get('model', '')),
            );
        });

        $bindings->bind('qTaskStop', fn (): array => $this->agent->stop());

        // The gate in front of anything that could change the machine.
        $bindings->bind('qApprove', function (mixed $allowed = null): array {
            $verdict = $this->agent->approve((bool) $allowed);

            $this->governor->note('shell', (bool) $allowed
                ? 'the reader allowed a command'
                : 'the reader refused a command');

            return $verdict;
        });

        $bindings->bind('qShell', function (mixed $on = null): array {
            $this->shell->setEnabled((bool) $on);
            $this->governor->note('shell', (bool) $on
                ? 'commands on: the agent may run things in the working folder, as you'
                : 'commands off: the agent can only read and write files');

            return ['ok' => true, 'shell' => $this->shell->enabled()];
        });

        $bindings->bind('qMode', function (mixed $name = null): array {
            $name = \is_string($name) && \in_array($name, ['chat', 'task', 'history'], true) ? $name : 'chat';
            $this->settings->set('mode', $name);
            $this->settings->save();

            return ['ok' => true, 'mode' => $name];
        });
        $bindings->bind('qTaskForget', function (): array {
            $this->agent->forget();

            return ['ok' => true];
        });

        /* ---------- the folder the tools live in ---------- */

        $bindings->bind('qChooseWorkspace', function (): array {
            $current = $this->tools->workspace() ?? (string) (\getenv('HOME') ?: '/');
            $chosen = $this->app->dialog->selectDirectory($current);

            if ($chosen === null) {
                return ['ok' => false, 'error' => 'no folder chosen'];
            }

            return $this->tools->setWorkspace($chosen) + ['chosen' => $chosen];
        });

        $bindings->bind('qWorkspace', fn (mixed $path = null): array => $this->tools->setWorkspace(
            \is_string($path) ? $path : null,
        ));

        /* ---------- the web tools ---------- */

        $bindings->bind('qWeb', function (mixed $on = null): array {
            $this->tools->setWeb((bool) $on);
            $this->governor->note('web', (bool) $on
                ? 'web tools on: the agent may search and fetch, which leaves this machine'
                : 'web tools off: the agent cannot reach the network');

            return ['ok' => true, 'web' => $this->tools->webEnabled()];
        });

        $bindings->bind('qSearchUrl', fn (mixed $url = null): array => $this->tools->setSearchUrl(
            \is_string($url) ? $url : null,
        ));

        /* ---------- sessions ---------- */

        $bindings->bind('qSessions', fn (): array => [
            'sessions' => $this->sessions->list(60),
            'directory' => $this->sessions->directory(),
        ]);

        $bindings->bind('qSession', fn (mixed $id = null): array => [
            'session' => \is_string($id) ? $this->sessions->load($id) : null,
        ]);

        $bindings->bind('qSessionDelete', function (mixed $id = null): array {
            $removed = \is_string($id) && $this->sessions->remove($id);

            return ['ok' => $removed];
        });

        $bindings->bind('qResume', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];

            return $this->agent->resume(
                (string) ($payload['id'] ?? ''),
                (string) ($payload['instruction'] ?? ''),
            );
        });

        /* ---------- skills ---------- */

        $bindings->bind('qSkills', fn (): array => [
            'skills' => \array_map(
                static fn (array $skill): array => ['name' => $skill['name'], 'description' => $skill['description']],
                $this->skills->catalogue(),
            ),
            'directory' => $this->skills->directory(),
        ]);

        /* ---------- documents ---------- */

        $bindings->bind('qDocuments', fn (): array => $this->documents->stats());

        $bindings->bind('qSearch', function (mixed $query = null, mixed $limit = null): array {
            $result = $this->documents->search(
                \is_string($query) ? $query : '',
                \is_numeric($limit) ? (int) $limit : 5,
            );

            return ['ok' => $result['ok'], 'output' => $result['output']];
        });

        /* ---------- jobs ---------- */

        $bindings->bind('qJobs', fn (): array => $this->jobs->view(\microtime(true)));

        $bindings->bind('qJobSave', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];

            $task = \trim((string) ($payload['task'] ?? ''));

            if ($task === '') {
                return ['ok' => false, 'error' => 'a job needs something to do'];
            }

            $job = [
                'id' => (string) ($payload['id'] ?? ''),
                'name' => \trim((string) ($payload['name'] ?? '')) ?: \substr($task, 0, 40),
                'task' => $task,
                'model' => (string) ($payload['model'] ?? ''),
                'enabled' => ($payload['enabled'] ?? true) === true,
                'run_requested' => false,
            ];

            $at = \trim((string) ($payload['at'] ?? ''));

            if ($at !== '' && \preg_match('/^\d{1,2}:\d{2}$/', $at) === 1) {
                $job['at'] = $at;
            } else {
                $job['every_minutes'] = \max(Jobs::MIN_EVERY, (int) ($payload['every_minutes'] ?? 60));
            }

            return $this->jobs->save($job);
        });

        $bindings->bind('qJobToggle', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];

            $this->jobs->toggle((string) ($payload['id'] ?? ''), ($payload['enabled'] ?? false) === true);

            return ['ok' => true];
        });

        $bindings->bind('qJobRemove', function (mixed $id = null): array {
            $this->jobs->remove(\is_string($id) ? $id : '');

            return ['ok' => true];
        });

        $bindings->bind('qJobRun', function (mixed $id = null): array {
            if (!\is_string($id) || $id === '') {
                return ['ok' => false, 'error' => 'no job named'];
            }

            $this->jobs->requestRun($id);

            return ['ok' => true, 'note' => 'queued: it runs when the machine is idle'];
        });

        /* ---------- images ---------- */

        $bindings->bind('qImage', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];
            $prompt = \trim((string) ($payload['prompt'] ?? ''));

            if ($prompt === '') {
                return ['ok' => false, 'error' => 'nothing to draw'];
            }

            $backend = $this->images->backend();

            if (!$backend['available']) {
                return [
                    'ok' => false,
                    'error' => 'no local image server is answering at ' . $backend['url']
                        . '. Image generation needs a Stable Diffusion server on this machine — '
                        . 'start AUTOMATIC1111 with --api (it listens on 7860 by default), or set another '
                        . 'address in settings. Nothing is sent anywhere else.',
                ];
            }

            // The governor's gate: diffusion is the loudest thing this app can do,
            // so it waits for an idle card rather than stacking on top of a model.
            if ($this->guard->stats()['inflight'] !== null || $this->agent->running() || $this->chat->state()['state'] === 'generating') {
                return ['ok' => false, 'error' => 'a model is generating right now — the image waits its turn'];
            }

            $gpu = $this->hardware->gpu();

            if (\is_array($gpu) && \is_numeric($gpu['temp'] ?? null) && (float) $gpu['temp'] >= $this->governor->ceiling()) {
                return [
                    'ok' => false,
                    'error' => 'the card is at ' . \number_format((float) $gpu['temp'], 0) . ' °C, at or above the '
                        . \number_format($this->governor->ceiling(), 0) . ' °C ceiling of the ' . $this->governor->preset()
                        . ' profile — let it cool first, or raise the ceiling',
                ];
            }

            $started = $this->images->start($prompt);

            if (($started['ok'] ?? false) === true) {
                $loras = $this->images->requestedLoras();

                $this->governor->note('image', 'drawing "' . \substr($prompt, 0, 80) . '" with ' . $backend['kind']
                    . ' at ' . $backend['url']
                    . ($loras === [] ? '' : ' — with lora ' . App\Images::describeLoras($loras)));

                if ($loras !== []) {
                    $installed = $this->images->loras()['files'];

                    foreach ($loras as $lora) {
                        if (!\in_array($lora['path'], $installed, true)) {
                            $this->governor->note('image', 'note: no lora file called "' . $lora['path']
                                . '" in ' . $this->images->loraDirectory() . ' — the engine will say so if it cannot find it');
                        }
                    }
                }
            }

            return $started;
        });

        $bindings->bind('qImageServe', function (): array {
            $result = $this->images->serve();

            $this->governor->note('image', ($result['ok'] ?? false)
                ? 'image engine started: ' . (string) ($result['url'] ?? '')
                : 'image engine did not start: ' . (string) ($result['error'] ?? ''));

            return $result;
        });

        $bindings->bind('qImageInstall', function (mixed $engine = null): array {
            $chosen = \is_string($engine) && $engine !== '' ? $engine : 'sd';
            $result = $this->images->install($chosen);

            $this->governor->note('image', ($result['ok'] ?? false)
                ? 'installing an image engine (' . $chosen . ') — this downloads a few gigabytes'
                : 'could not start the engine install: ' . (string) ($result['error'] ?? ''));

            return $result;
        });

        $bindings->bind('qImageStopServing', function (): array {
            $result = $this->images->stopServing();
            $this->governor->note('image', 'image engine stopped');

            return $result;
        });

        $bindings->bind('qImageStop', function (): array {
            $this->images->stop();

            return ['ok' => true];
        });

        $bindings->bind('qImageConfig', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];

            $url = $this->images->setUrl(\is_string($payload['url'] ?? null) ? (string) $payload['url'] : null);

            if (\is_numeric($payload['steps'] ?? null)) {
                $this->images->setSteps((int) $payload['steps']);
            }

            if (\is_numeric($payload['size'] ?? null)) {
                $this->images->setSize((int) $payload['size']);
            }

            return $url;
        });

        /* ---------- vision ---------- */

        $bindings->bind('qLook', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];
            $path = (string) ($payload['path'] ?? '');

            $result = $this->vision->describe($path, (string) ($payload['question'] ?? ''));

            if ($result['ok']) {
                $this->governor->note('vision', 'looked at ' . $path . ' with ' . (string) $this->vision->chosen());
            }

            return $result + ['path' => $path];
        });

        /* ---------- the document index ---------- */

        $bindings->bind('qIndex', fn (mixed $limit = null): array => $this->documents->index(
            \is_numeric($limit) ? (int) $limit : 64,
        ));

        $bindings->bind('qForgetIndex', function (): array {
            $this->documents->forgetIndex();

            return ['ok' => true];
        });

        /* ---------- mcp ---------- */

        $bindings->bind('qMcp', fn (): array => [
            'path' => $this->mcp->path(),
            'servers' => $this->mcp->status(),
        ]);

        $bindings->bind('qOpen', function (mixed $path = null): array {
            if (!\is_string($path) || (!\is_dir($path) && !\is_file($path))) {
                return ['ok' => false, 'error' => 'nothing to open'];
            }

            $this->app->dialog->open($path);

            return ['ok' => true];
        });

        /* ---------- bringing a model here ---------- */

        $bindings->bind('qModelCheck', function (mixed $name = null): array {
            if (!\is_string($name) || \trim($name) === '') {
                return ['ok' => false, 'error' => 'name a model to size up'];
            }

            $lookup = $this->models->lookup($name);

            if (($lookup['ok'] ?? false) !== true) {
                return $lookup;
            }

            $gpu = $this->hardware->gpu();
            $lookup['card_free_megabytes'] = \is_numeric($gpu['vram_total'] ?? null) && \is_numeric($gpu['vram_used'] ?? null)
                ? (int) $gpu['vram_total'] - (int) $gpu['vram_used']
                : null;
            $lookup['fits_weights'] = $lookup['card_free_megabytes'] !== null
                ? $lookup['megabytes'] <= $lookup['card_free_megabytes']
                : null;
            $lookup['note'] = 'weights only: the KV cache depends on the context you run it at, '
                . 'and that is only knowable once the model is here';

            return $lookup;
        });

        $bindings->bind('qModelPull', function (mixed $name = null): array {
            if (!\is_string($name) || \trim($name) === '') {
                return ['ok' => false, 'error' => 'name a model to download'];
            }

            $started = $this->models->pull($name);

            $this->governor->note('model', ($started['ok'] ?? false)
                ? 'downloading ' . $name . ' from the registry — this one leaves your machine to fetch it'
                : 'could not start the download: ' . (string) ($started['error'] ?? ''));

            return $started;
        });

        $bindings->bind('qState', fn (): array => $this->state());
        $bindings->bind('qHardware', fn (): array => $this->hardware->snapshot());

        // Undo, for the folder the agent writes in. The journal is a file in the reader's
        // own config directory, so what it holds is readable without asking this app.
        $bindings->bind('qUndo', fn (): array => $this->tools->undoLast() + ['held' => $this->tools->checkpoints()]);

        // The leash for the next task: how much window it may use, and how many
        // generations it may take. The profile stays the ceiling — `Guard` clamps
        // whatever is asked for — so this stores the reader's number and the window's
        // note says when the two differ, rather than the app quietly using its own.
        $bindings->bind('qTaskRoom', function (mixed $numCtx = 0, mixed $steps = 0): array {
            $room = (int) $numCtx;
            $leash = (int) $steps;

            $this->settings->set('task_num_ctx', $room > 0 ? $room : 0);

            if ($leash > 0) {
                $this->settings->set('max_steps', \min($leash, 40));
            }

            return [
                'ok' => true,
                'task_num_ctx' => (int) $this->settings->get('task_num_ctx', 0),
                'max_steps' => (int) $this->settings->get('max_steps', 6),
            ];
        });
        $bindings->bind('qModels', fn (): array => $this->models());

        $bindings->bind('qSend', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];

            return $this->chat->send(
                (string) ($payload['prompt'] ?? ''),
                (string) ($payload['model'] ?? $this->settings->get('model', '')),
                (string) ($payload['attachment'] ?? ''),
            );
        });

        $bindings->bind('qStop', fn (): array => $this->chat->stop());

        /* ---------- conversations ---------- */

        // Zoom is a reading preference, so it is remembered like one. It scales the
        // page rather than the window, which is what makes a small window usable.
        $bindings->bind('qTaskFold', function (mixed $open = null): array {
            $this->settings->set('task_fold', (bool) $open);
            $this->settings->save();

            return ['ok' => true, 'open' => (bool) $open];
        });

        $bindings->bind('qZoom', function (mixed $level = null): array {
            $value = \is_numeric($level) ? (float) $level : 1.0;
            $value = \max(0.7, \min(1.6, \round($value, 2)));

            $this->settings->set('zoom', $value);
            $this->settings->save();

            return ['ok' => true, 'zoom' => $value];
        });

        $bindings->bind('qChats', fn (): array => [
            'chats' => $this->chats->list(40),
            'current' => $this->chat->chatId(),
            'directory' => $this->chats->directory(),
        ]);

        $bindings->bind('qChatNew', fn (): array => $this->chat->newChat());

        $bindings->bind('qChatUse', function (mixed $id = null): array {
            return \is_string($id) ? $this->chat->useChat($id) : ['ok' => false, 'error' => 'no conversation named'];
        });

        $bindings->bind('qChatRegenerate', fn (): array => $this->chat->regenerate());

        $bindings->bind('qChatEditQuestion', function (mixed $text = null): array {
            return $this->chat->editQuestion(\is_string($text) ? $text : '');
        });

        $bindings->bind('qChatSearch', fn (mixed $query = null): array => [
            'matches' => $this->chats->search(\is_string($query) ? $query : ''),
        ]);

        $bindings->bind('qChatRename', function (mixed $payload = null): array {
            $payload = \is_array($payload) ? $payload : [];
            $id = (string) ($payload['id'] ?? '');

            if ($id === '') {
                return ['ok' => false, 'error' => 'no conversation named'];
            }

            $this->chats->rename($id, (string) ($payload['title'] ?? ''));

            return ['ok' => true];
        });

        $bindings->bind('qChatDelete', function (mixed $id = null): array {
            if (!\is_string($id) || $id === '') {
                return ['ok' => false, 'error' => 'no conversation named'];
            }

            $removed = $this->chats->remove($id);

            if ($this->chat->state()['chat'] === $id) {
                $this->chat->newChat();
            }

            return ['ok' => $removed];
        });

        // Attach a file from the working folder. The native picker returns a path;
        // it is only accepted if it is inside the folder the tools are confined to.
        $bindings->bind('qAttach', function (): array {
            $workspace = $this->tools->workspace();

            if ($workspace === null) {
                return ['ok' => false, 'error' => 'choose a working folder first — attachments come from there'];
            }

            $chosen = $this->app->dialog->selectFile($workspace);

            if ($chosen === null) {
                return ['ok' => false, 'error' => 'no file chosen'];
            }

            $loaded = $this->attachments->load($chosen);

            return ($loaded['ok'] ?? false) === true
                ? ['ok' => true, 'path' => $chosen, 'name' => $loaded['name'], 'note' => $loaded['note']]
                : $loaded;
        });
        $bindings->bind('qForget', function (): array {
            $this->chat->forget();

            return ['ok' => true];
        });

        $bindings->bind('qProfile', function (mixed $id = null): array {
            $ok = \is_string($id) && $this->governor->usePreset($id);

            if ($ok) {
                $this->settings->set('profile', $id);
                $this->settings->save();
            }

            return ['ok' => $ok, 'governor' => $this->governor->snapshot()];
        });

        $bindings->bind('qModel', function (mixed $name = null): array {
            if (!\is_string($name) || $name === '') {
                return ['ok' => false, 'error' => 'no model named'];
            }

            $this->settings->set('model', $name);
            $this->settings->save();

            return ['ok' => true, 'model' => $name];
        });

        $bindings->bind('qLoad', function (mixed $name = null): array {
            if (!\is_string($name) || $name === '') {
                return ['ok' => false, 'error' => 'no model named'];
            }

            $result = $this->ollama->preload($name, $this->governor->options(), $this->governor->keepAlive());
            $this->governor->note('load', \sprintf('asked ollama to load %s', $name));

            return $result + ['model' => $name];
        });

        $bindings->bind('qUnload', function (mixed $name = null): array {
            if (!\is_string($name) || $name === '') {
                return ['ok' => false, 'error' => 'no model named'];
            }

            $result = $this->ollama->unload($name);
            $this->governor->note('unload', \sprintf('emptied %s out of vram', $name));

            return $result + ['model' => $name];
        });

        $bindings->bind('qServe', function (): array {
            $result = $this->ollama->serve();
            $this->governor->note(
                'engine',
                $result['ok'] ?? false
                    ? 'ollama is up on ' . Ollama::HOST . ':' . Ollama::PORT
                    : 'could not start ollama: ' . (string) ($result['error'] ?? 'unknown'),
            );

            return $result;
        });

        $bindings->bind('qLog', fn (): array => $this->log());
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $this->debug('requested');

        $probe = $this->ollama->probe();
        $models = $probe['up'] ? $this->models() : ['models' => [], 'loaded' => []];

        $this->debug('answered');

        return [
            'app' => [
                'name' => 'Quiesce',
                'version' => '0.1.0',
                'local_only' => true,
                'endpoint' => $this->guard->baseUrl(),
                'configuration' => $this->settings->path(),
            ],
            'ollama' => $probe,
            'models' => $models['models'],
            'loaded' => $models['loaded'],
            'gpu' => $this->hardware->gpu(),
            'cpu' => $this->hardware->cpu(),
            'governor' => $this->governor->snapshot(),
            'guard' => $this->guard->stats(),
            'chat' => $this->chat->state(),
            'agent' => $this->agent->state(),
            'tools' => [
                'workspace' => $this->tools->workspace(),
                'web' => $this->tools->webEnabled(),
                'shell' => $this->shell->enabled(),
                'search_url' => $this->tools->searchUrl(),
            ],
            'chats' => $this->chats->list(20),
            'model_options' => [
                'suggestions' => $this->models->suggestions(),
                'pulling' => $this->models->running() ? $this->models->progress() : null,
                'installed' => \array_map(
                    static fn (array $model): array => [
                        'name' => (string) $model['name'],
                        'megabytes' => Models::megabytesOf($model),
                        'parameters' => (string) ($model['parameters'] ?? ''),
                        'quantization' => (string) ($model['quantization'] ?? ''),
                    ],
                    $this->ollama->models(),
                ),
            ],
            'chat' => $this->chat->chatId(),
            'sessions' => $this->sessions->list(12),
            'sessions_directory' => $this->sessions->directory(),
            'skills' => \array_map(
                static fn (array $skill): array => ['name' => $skill['name'], 'description' => $skill['description']],
                $this->skills->catalogue(),
            ),
            'skills_directory' => $this->skills->directory(),
            'documents' => $this->documents->stats() + ['index' => $this->documents->indexStatus()],
            'advisor' => $this->advisor->advise(
                $probe['up'] ? $this->ollama->models() : [],
                $this->governor,
                $probe['up'] ? $this->ollama->loaded() : [],
            ),
            'vision' => [
                'models' => $this->vision->models(),
                'chosen' => $this->vision->chosen(),
            ],
            'images' => [
                'backend' => $this->images->backend(),
                'serving' => $this->images->serving(),
                'installing' => $this->images->running() && $this->images->kind() === 'install',
                'loras' => $this->images->loras(),
                'lora_dir' => $this->images->loraDirectory(),
                'status' => $this->images->latest(),
                'running' => $this->images->running(),
                'prompt' => $this->images->prompt(),
                'steps' => $this->images->steps(),
                'size' => $this->images->size(),
                'directory' => $this->images->directory(),
                'recent' => \array_slice($this->images->catalogue(6), 0, 6),
            ],
            'jobs' => $this->jobs->view(\microtime(true))['jobs'],
            'mcp' => [
                'path' => $this->mcp->path(),
                'servers' => $this->mcp->status(),
            ],
            'log' => \array_slice($this->log(), 0, 40),
            'settings' => $this->settings->all(),
            'profiles' => \array_values(\array_map(
                static fn (string $id, array $preset): array => [
                    'id' => $id,
                    'label' => (string) $preset['label'],
                    'note' => (string) $preset['note'],
                    'ceiling' => (float) $preset['ceiling'],
                    'floor' => (float) $preset['floor'],
                    'max_duty' => (float) $preset['max_duty'],
                    'max_tps' => (int) $preset['max_tps'],
                    'num_ctx' => (int) $preset['num_ctx'],
                    'num_predict' => (int) $preset['num_predict'],
                    'num_thread' => (int) $preset['num_thread'],
                    'on_ms' => (int) $preset['on_ms'],
                    'off_ms' => (int) $preset['off_ms'],
                ],
                \array_keys(Governor::PRESETS),
                \array_values(Governor::PRESETS),
            )),
            'sampled_at' => \microtime(true),
        ];
    }

    /** @return array<string, mixed> */
    public function models(): array
    {
        $models = $this->ollama->models();
        $loaded = [];

        foreach ($this->ollama->loaded() as $entry) {
            $loaded[$entry['name']] = $entry;
        }

        $out = [];

        foreach ($models as $model) {
            $out[] = [
                'name' => $model['name'],
                'size' => $model['size'],
                'gigabytes' => \round($model['size'] / 1_000_000_000, 1),
                'parameters' => $model['parameters'],
                'quantization' => $model['quantization'],
                'family' => $model['family'],
                'modified' => $model['modified'],
                'loaded' => isset($loaded[$model['name']]),
                'vram' => isset($loaded[$model['name']]) ? $loaded[$model['name']]['vram'] : 0,
                'context' => isset($loaded[$model['name']]) ? $loaded[$model['name']]['context'] : 0,
            ];
        }

        return ['models' => $out, 'loaded' => \array_values($loaded)];
    }

    /**
     * A two-line trail, only when BOSON_DEBUG is set: "the window is empty" and
     * "the loop stopped" look identical from the outside, and only one of them
     * is a code problem.
     */
    private function debug(string $phase): void
    {
        if (\getenv('BOSON_DEBUG')) {
            @\file_put_contents(
                \dirname($this->settings->path()) . '/state.log',
                \date('H:i:s') . ' ' . $phase . "\n",
                \FILE_APPEND,
            );
        }
    }

    /** The two logs, newest first, as one timeline. */
    private function log(): array
    {
        $entries = [];

        foreach ($this->guard->log() as $entry) {
            $entries[] = ['t' => $entry['t'], 'source' => 'endpoint', 'message' => $entry['message']];
        }

        foreach ($this->governor->log() as $entry) {
            $entries[] = ['t' => $entry['t'], 'source' => $entry['level'], 'message' => $entry['message']];
        }

        \usort($entries, static fn (array $a, array $b): int => $b['t'] <=> $a['t']);

        return $entries;
    }
}
