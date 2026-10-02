<?php

declare(strict_types=1);

namespace App;

use Boson\Application;
use RuntimeException;

/**
 * A task loop: think, call a tool, read the result, think again, answer.
 *
 * This is the "agentic" part, and it is deliberately built the same way the
 * window's chat is: it talks to the *governed* endpoint, not to Ollama. That
 * single decision is what makes the whole promise hold — a six-step task is
 * six generations, each one queued, duty-cycled and heat-limited by the same
 * governor that paces a chat message. An agent looping for twenty minutes is
 * exactly the workload the governor exists for.
 *
 * Stepped rather than blocking, because the loop lives in the same PHP process
 * as the window: a blocking agent would freeze the UI it is reporting to.
 */
final class Agent
{
    public const int DEFAULT_MAX_STEPS = 6;

    private const string SYSTEM = <<<'PROMPT'
        You are an agent running inside Quiesce, a desktop application on the reader's own
        machine. The model you are running on is local; the application around you decides
        what you can touch.

        Work in small steps. Call a tool when a tool answers the question; otherwise answer
        directly. After each tool result, decide whether you have enough to answer.

        The working folder is %WORKSPACE%. Every file path you pass to a tool is relative to
        that folder, and nothing outside it can be read or written. If a tool refuses a path,
        do not try to work around it — say what you needed and why.

        %WEB%

        %SHELL%

        %KNOWLEDGE%

        Never invent the contents of a file, a search result, or a page: if you did not read
        it, say so. When the task is done, answer in plain sentences and stop calling tools.
        PROMPT;

    private ?HttpStream $stream = null;

    private string $pending = '';

    private string $state = 'idle';

    private string $task = '';

    private string $model = '';

    private int $step = 0;

    private string $buffer = '';

    private string $answer = '';

    private string $error = '';

    /** @var list<array<string, mixed>> */
    private array $steps = [];

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    /** @var array<string, mixed> */
    private array $usage = [];

    private float $startedAt = 0.0;

    private int $tokens = 0;

    private int $thinkingTokens = 0;

    /** @var array<string, mixed> */
    private array $window = [];

    /** The subagent currently running, if any. */
    private ?Agent $child = null;

    private int $childStep = 0;

    /** Thinking spent in the current turn, to tell "thought itself out" from "said nothing". */
    private int $turnThinking = 0;

    /** One widening retry per task, so a stuck model cannot loop on it. */
    private bool $widened = false;

    /** @var array<string, bool> model => supports thinking */
    private array $thinks = [];

    private readonly Http $http;

    /**
     * `$app` is optional so the same loop can run headless (tools/task.php) as
     * well as in the window. With no window, the push events are dropped and
     * nothing else changes.
     */
    /** The tool calls of the current turn, run one at a time. */
    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /** The call a command is being run for, while it runs or waits for approval. */
    /** @var array<string, mixed>|null */
    private ?array $pendingCall = null;

    private int $pendingStep = 0;

    private int $revision = 0;

    private ?string $sessionId = null;

    private ?string $jobId = null;

    public function __construct(
        private readonly ?Application $app,
        private readonly Governor $governor,
        private readonly Tools $tools,
        private readonly Settings $settings,
        private readonly ?Shell $shell = null,
        private readonly ?Sessions $sessions = null,
        private readonly ?Skills $skills = null,
        private readonly ?Documents $documents = null,
        private readonly ?Mcp $mcp = null,
        private readonly ?Ollama $ollama = null,
        private readonly ?Context $context = null,
        private readonly int $depth = 0,
    ) {
        $this->http = new Http(Guard::HOST, Guard::PORT);

        // Only a top-level agent may hand work over: a subagent that could spawn
        // its own subagents would be a way to make a quiet machine loud.
        $this->tools->allowDelegate($depth === 0);
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'state' => $this->state,
            'task' => $this->task,
            'model' => $this->model,
            'step' => $this->step,
            'max_steps' => $this->maxSteps(),
            'answer' => $this->answer,
            'error' => $this->error,
            'tokens' => $this->tokens,
            'thinking_tokens' => $this->thinkingTokens,
            'context' => $this->window,
            'subagent' => $this->state === 'subagent' && $this->child !== null ? [
                'step' => $this->childStep,
                'steps' => $this->child->state()['step'],
                'tokens' => $this->child->state()['tokens'],
                'state' => $this->child->state()['state'],
            ] : null,
            'seconds' => $this->startedAt === 0.0 ? 0.0 : \round(\microtime(true) - $this->startedAt, 1),
            'steps' => $this->steps,
            'usage' => $this->usage,
            'awaiting' => $this->pendingCall !== null && $this->state === 'awaiting',
            'command' => $this->pendingCommand(),
        ];
    }

    private function maxSteps(): int
    {
        $configured = $this->settings->get('max_steps');

        return \is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_STEPS;
    }

    /**
     * How much window this task may use.
     *
     * The reader can ask for a different window per task (`task_num_ctx` in the Task
     * card), and the profile stays the ceiling — `Guard` clamps whatever is asked for to
     * the profile, which is the promise this application makes about the card. So this
     * is the smaller of the two, and `noteLeash()` says out loud when the number asked
     * for is not the number used. A task quietly running in a smaller window than the
     * reader chose is how an agent looks stupid for no visible reason.
     *
     * Worth stating because it is not obvious: a bigger window costs VRAM and time, not
     * watts. What heats the card is sustained duty, and the duty cycle does not depend
     * on this — which is why a reader can have a larger window without giving up the
     * quiet.
     */
    private function room(): int
    {
        $ceiling = (int) $this->governor->current()['num_ctx'];
        $asked = (int) ($this->settings->get('task_num_ctx') ?? 0);

        return $asked > 0 ? \min($asked, $ceiling) : $ceiling;
    }

    /**
     * The leash, said out loud when the task starts, rather than discovered at step 6.
     *
     * Only when it differs from the defaults: a task running on the profile's own
     * numbers is not news, and a log that reports the ordinary is a log nobody reads.
     */
    private function noteLeash(): void
    {
        $ceiling = (int) $this->governor->current()['num_ctx'];
        $asked = (int) ($this->settings->get('task_num_ctx') ?? 0);
        $room = $this->room();
        $steps = $this->maxSteps();

        if ($asked <= 0 && $steps === self::DEFAULT_MAX_STEPS) {
            return;
        }

        $note = \sprintf('this task: up to %d step(s) in a %s-token window', $steps, \number_format($room));

        if ($asked > $ceiling) {
            $note .= \sprintf(
                '. %s was asked for and %s is this profile\'s ceiling, so the ceiling is what it gets — Fast is how you ask for a larger window',
                \number_format($asked),
                \number_format($ceiling),
            );
        } elseif ($asked > 0 && $room < $ceiling) {
            $note .= \sprintf('. Smaller than the %s the profile allows, on purpose', \number_format($ceiling));
        }

        $this->governor->note('task', $note);
    }

    /** @return array<string, mixed> */
    public function run(string $task, string $model, ?string $job = null): array
    {
        if ($this->state === 'thinking' || $this->state === 'tool') {
            return ['ok' => false, 'error' => 'a task is already running'];
        }

        $task = \trim($task);

        if ($task === '') {
            return ['ok' => false, 'error' => 'nothing to do'];
        }

        if ($model === '') {
            return ['ok' => false, 'error' => 'pick a model first'];
        }

        if ($this->tools->workspace() === null) {
            return ['ok' => false, 'error' => 'choose a working folder first — the file tools live inside it'];
        }

        $this->task = $task;
        $this->noteLeash();
        $this->model = $model;
        $this->queue = [];
        $this->pendingCall = null;
        $this->pendingStep = 0;
        $this->step = 0;
        $this->widened = false;
        $this->buffer = '';
        $this->answer = '';
        $this->error = '';
        $this->steps = [];
        $this->calls = [];
        $this->usage = [];
        $this->tokens = 0;
        $this->startedAt = \microtime(true);

        $this->jobId = $job;

        $this->messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $task],
        ];

        $this->sessionId = $this->sessions?->start(
            $task,
            $model,
            $this->tools->workspace(),
            $this->governor->preset(),
        );

        $this->push('onTaskStarted', ['task' => $task, 'model' => $model, 'session' => $this->sessionId]);

        return $this->think();
    }

    /**
     * Continue a session that was recorded earlier.
     *
     * The old transcript is left exactly as it was; continuing starts a *new*
     * session that carries the history forward. Rewriting yesterday's record
     * because you asked a follow-up question today would make the transcripts
     * worth nothing.
     *
     * @return array<string, mixed>
     */
    public function resume(string $id, string $instruction = ''): array
    {
        if ($this->sessions === null) {
            return ['ok' => false, 'error' => 'sessions are not available'];
        }

        if ($this->running()) {
            return ['ok' => false, 'error' => 'a task is already running'];
        }

        $messages = $this->sessions->messages($id);
        $record = $this->sessions->load($id);

        if ($messages === [] || $record === null) {
            return ['ok' => false, 'error' => 'that session has nothing to continue'];
        }

        if ($this->tools->workspace() === null) {
            return ['ok' => false, 'error' => 'choose a working folder first'];
        }

        $model = (string) ($record['model'] ?? $this->settings->get('model', ''));

        if ($model === '') {
            return ['ok' => false, 'error' => 'that session does not say which model it used'];
        }

        // Refresh the system prompt: the folder, skills or switches may have
        // changed since that session ran.
        $messages[0] = ['role' => 'system', 'content' => $this->systemPrompt()];
        $messages[] = [
            'role' => 'user',
            'content' => \trim($instruction) === '' ? 'Carry on with the task.' : \trim($instruction),
        ];

        $this->task = \trim((string) ($record['task'] ?? 'task')) . ' (continued)';
        $this->model = $model;
        $this->jobId = null;
        $this->queue = [];
        $this->pendingCall = null;
        $this->calls = [];
        $this->steps = [];
        $this->usage = [];
        $this->step = 0;
        $this->tokens = 0;
        $this->buffer = '';
        $this->answer = '';
        $this->error = '';
        $this->startedAt = \microtime(true);
        $this->messages = \array_values($messages);

        $this->sessionId = $this->sessions->start(
            $this->task,
            $model,
            $this->tools->workspace(),
            $this->governor->preset(),
        );

        $this->push('onTaskStarted', [
            'task' => $this->task,
            'model' => $model,
            'session' => $this->sessionId,
            'resumed' => $id,
        ]);

        return $this->think();
    }

    /**
     * Is the loop busy right now?
     *
     * `subagent` belongs in this list: while a child agent works, the parent is
     * mid-task, and a job that thought otherwise would start a second task beside
     * it. That is the one thing this application exists not to do.
     */
    public function running(): bool
    {
        return \in_array($this->state, ['thinking', 'tool', 'command', 'mcp', 'awaiting', 'subagent'], true);
    }

    /** @return array<string, mixed> */
    public function stop(): array
    {
        if ($this->stream !== null) {
            $this->stream->abort();
            $this->stream = null;
        }

        if ($this->state === 'idle') {
            return ['ok' => false, 'error' => 'nothing is running'];
        }

        $this->state = 'stopped';
        $this->record();
        $this->push('onTaskFinished', $this->state());

        return ['ok' => true];
    }

    public function forget(): void
    {
        $this->stop();
        $this->state = 'idle';
        $this->task = '';
        $this->answer = '';
        $this->error = '';
        $this->steps = [];
        $this->messages = [];
        $this->push('onTaskCleared', []);
    }

    /**
     * One step of the loop, called from the application timer.
     *
     * The stream is pumped a piece at a time; when a generation finishes, the
     * tool calls it asked for are run here, one after another, and the next
     * generation is opened. Nothing blocks; the window keeps painting.
     */
    public function tick(): void
    {
        if ($this->state === 'command' && $this->shell !== null) {
            $this->pumpCommand();

            return;
        }

        if ($this->state === 'mcp' && $this->mcp !== null) {
            $this->pumpMcp();

            return;
        }

        if ($this->state === 'subagent' && $this->child !== null) {
            $this->pumpChild();

            return;
        }

        if ($this->state !== 'thinking' || $this->stream === null) {
            return;
        }

        if (!$this->stream->finished()) {
            $bytes = $this->stream->pump();

            if ($bytes !== '') {
                $this->pending .= $bytes;
                $this->drain();
            }
        }

        if ($this->stream !== null && $this->stream->finished()) {
            $this->finishGeneration();
        }
    }

    private function drain(): void
    {
        while (($newline = \strpos($this->pending, "\n")) !== false) {
            $line = \trim(\substr($this->pending, 0, $newline));
            $this->pending = \substr($this->pending, $newline + 1);

            if ($line === '') {
                continue;
            }

            $event = \json_decode($line, true);

            if (!\is_array($event)) {
                continue;
            }

            if (isset($event['error'])) {
                $this->fail((string) $event['error']);

                return;
            }

            $message = \is_array($event['message'] ?? null) ? $event['message'] : [];

            // Reasoning is counted, never kept: it is not part of the answer, and
            // feeding it back as history would charge the context twice.
            if (($message['thinking'] ?? '') !== '') {
                $this->thinkingTokens++;
                $this->turnThinking++;
            }

            if (($message['content'] ?? '') !== '') {
                $piece = (string) $message['content'];
                $this->buffer .= $piece;
                $this->tokens++;
                $this->push('onTaskToken', ['text' => $piece]);
            }

            if (\is_array($message['tool_calls'] ?? null)) {
                foreach ($message['tool_calls'] as $index => $call) {
                    if (\is_array($call)) {
                        $this->calls[(int) $index] = $call;
                    }
                }
            }

            if ((bool) ($event['done'] ?? false)) {
                $this->usage = [
                    'prompt' => (int) ($event['prompt_eval_count'] ?? 0),
                    'completion' => (int) ($event['eval_count'] ?? 0),
                ];
            }
        }
    }

    /** The generation ended: either answer, or run the tools it asked for. */
    private function finishGeneration(): void
    {
        $this->stream = null;

        if ($this->state !== 'thinking') {
            return;
        }

        if ($this->calls === [] && \trim($this->buffer) === '' && $this->turnThinking > 0 && !$this->widened) {
            // The model thought and then said nothing: its reasoning consumed the
            // whole prediction budget, so the answer never started. Ask again with
            // more room rather than reporting a finished task that produced nothing.
            $this->widened = true;
            $this->governor->note('thinking', sprintf(
                'the model used %d thinking tokens and had no room left to answer — asking again with a wider budget',
                $this->turnThinking,
            ));
            $this->messages[] = [
                'role' => 'user',
                'content' => 'Answer with the next step, briefly. Do not reason at length.',
            ];
            $this->think(4);

            return;
        }

        if ($this->calls === []) {
            $this->answer = \trim($this->buffer);

            if ($this->answer === '' && $this->turnThinking > 0) {
                $this->answer = '(the model spent ' . $this->turnThinking . ' tokens thinking and produced no answer; '
                    . 'the step budget for reasoning is the ' . $this->governor->preset() . ' profile\'s, widened once already)';
                $this->error = 'the model produced no answer';
            }

            $this->state = 'done';
            $this->record();
            $this->push('onTaskFinished', $this->state());

            return;
        }

        $assistant = ['role' => 'assistant', 'content' => $this->buffer];
        $assistant['tool_calls'] = \array_values($this->calls);
        $this->messages[] = $assistant;

        $this->queue = \array_values($this->calls);
        $this->calls = [];
        $this->buffer = '';
        $this->step++;

        $this->advance();
    }

    /**
     * Run a subtask in a fresh agent.
     *
     * The child is a real agent with its own session and its own context, driven
     * by this loop rather than beside it: one generation at a time, through the
     * same governor. That is the whole reason it can exist in a quiet app — a
     * subagent is not a second GPU load, it is the next turn in the queue.
     *
     * @param array<string, mixed> $call
     * @param array<string, mixed> $arguments
     */
    private function delegate(array $call, array $arguments): void
    {
        $task = \trim((string) ($arguments['task'] ?? ''));

        if ($task === '') {
            $this->recordStep($call, $arguments, false, 'nothing was asked of the subagent', 0.0);

            return;
        }

        $this->tools->allowDelegate(false);

        $child = new self(
            $this->app,
            $this->governor,
            $this->tools,
            $this->settings,
            $this->shell,
            $this->sessions,
            $this->skills,
            $this->documents,
            $this->mcp,
            $this->ollama,
            $this->context,
            $this->depth + 1,
        );

        $this->child = $child;
        $this->childStep = $this->step;

        $this->steps[] = [
            'step' => $this->step,
            'tool' => 'delegate',
            'call' => 'delegate "' . \substr($task, 0, 120) . '"',
            'ok' => true,
            'running' => true,
            'output' => 'a subagent is working on this…',
            'ms' => 0,
            'machine' => false,
            'arguments' => $arguments,
            'at' => \microtime(true),
            'revision' => 0,
        ];

        $this->push('onTaskStep', \end($this->steps));

        $started = $child->run($task, $this->model);

        if (($started['ok'] ?? false) === false) {
            $this->child = null;
            $this->tools->allowDelegate($this->depth === 0);
            $index = \count($this->steps) - 1;

            if ($index >= 0) {
                $this->steps[$index]['running'] = false;
                $this->steps[$index]['ok'] = false;
                $this->steps[$index]['output'] = (string) ($started['error'] ?? 'the subagent could not start');
            }

            $this->toolMessage($call, 'delegate', (string) ($started['error'] ?? 'the subagent could not start'));
            $this->advance();

            return;
        }

        $this->governor->note('subagent', 'handed over: ' . \substr($task, 0, 90));
        $this->state = 'subagent';
    }

    /** Step the subagent, and finish the step when it answers. */
    private function pumpChild(): void
    {
        $child = $this->child;

        if ($child === null) {
            return;
        }

        $child->tick();
        $state = $child->state();
        $index = \count($this->steps) - 1;

        if ($index >= 0 && isset($this->steps[$index]['running'])) {
            $this->steps[$index]['output'] = 'subagent: ' . $state['state'] . ' · step ' . $state['step']
                . ' · ' . $state['tokens'] . ' tokens' . ($state['answer'] !== '' ? "\n\n" . $state['answer'] : '');
            $this->steps[$index]['revision'] = ++$this->revision;
            $this->push('onTaskOutput', [
                'index' => $index,
                'revision' => $this->revision,
                'output' => $this->steps[$index]['output'],
            ]);
        }

        if ($child->running()) {
            return;
        }

        $answer = \trim((string) $state['answer']);
        $failed = $state['state'] === 'failed';

        $this->child = null;
        $this->tools->allowDelegate($this->depth === 0);

        if ($index >= 0) {
            $this->steps[$index]['running'] = false;
            $this->steps[$index]['ok'] = !$failed && $answer !== '';
            $this->steps[$index]['output'] = $answer === ''
                ? ($failed ? (string) $state['error'] : 'the subagent finished without an answer')
                : $answer;
            $this->steps[$index]['ms'] = (int) \round((\microtime(true) - (float) $this->steps[$index]['at']) * 1_000);
            $this->steps[$index]['revision'] = ++$this->revision;
            $this->push('onTaskStep', $this->steps[$index]);
        }

        $this->toolMessage(
            [],
            'delegate',
            $answer === '' ? 'the subagent produced no answer' : $answer,
        );

        $this->state = 'thinking';
        $this->governor->note('subagent', 'came back' . ($failed ? ' having failed' : ''));
        $this->advance();
    }

    /**
     * Work through the calls of this turn, in order.
     *
     * A command stops here rather than running inline: it may take a minute, and
     * a minute of frozen window is not a feature. `advance()` is re-entered when
     * the command finishes or the reader answers.
     */
    private function advance(): void
    {
        while ($this->queue !== []) {
            $call = \array_shift($this->queue);
            $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = (string) ($function['name'] ?? '');
            $arguments = $function['arguments'] ?? [];

            if (\is_string($arguments)) {
                $decoded = \json_decode($arguments, true);
                $arguments = \is_array($decoded) ? $decoded : [];
            }

            $arguments = \is_array($arguments) ? $arguments : [];

            if ($name === 'delegate' && $this->child === null && $this->depth === 0) {
                $this->delegate($call, $arguments);

                return;
            }

            if ($this->mcp !== null && $this->mcp->owns($name)) {
                $started = $this->mcp->start($name, $arguments);

                if (($started['ok'] ?? false) === false) {
                    $this->recordStep($call, $arguments, false, (string) ($started['error'] ?? 'could not start'), 0.0);

                    continue;
                }

                $this->pendingCall = $call;
                $this->pendingStep = $this->step;
                $this->state = 'mcp';
                $this->revision = 0;

                $this->steps[] = [
                    'step' => $this->step,
                    'tool' => $name,
                    'call' => 'mcp ' . $name,
                    'ok' => true,
                    'running' => true,
                    'output' => '',
                    'ms' => 0,
                    'machine' => true,
                    'arguments' => $arguments,
                    'at' => \microtime(true),
                    'revision' => 0,
                ];

                $this->push('onTaskStep', \end($this->steps));
                $this->governor->note('mcp', 'calling ' . $name . ' on one of your servers');

                return;
            }

            if ($name === 'run_command' && $this->shell !== null) {
                $command = \trim((string) ($arguments['command'] ?? ''));
                $verdict = $this->shell->classify($command);

                if ($verdict['verdict'] === 'never') {
                    $this->recordStep($call, $arguments, false, $verdict['reason'], 0.0);

                    continue;
                }

                if ($verdict['verdict'] === 'write') {
                    $this->pendingCall = $call;
                    $this->pendingStep = $this->step;
                    $this->state = 'awaiting';
                    $this->push('onTaskApproval', [
                        'step' => $this->step,
                        'command' => $command,
                        'reason' => $verdict['reason'],
                    ]);

                    return;
                }

                $this->startCommand($call, $command);

                return;
            }

            $machine = $this->tools->leavesTheMachine($name);
            $before = \microtime(true);
            $result = $this->tools->run($name, $arguments);

            // Verify before reporting. A tool that says it wrote a file is making
            // a claim; reading the file back is the evidence. Anything that
            // changes the machine is checked, and the check goes in the step.
            $verification = ($result['ok'] ?? false) ? $this->verify($name, $arguments) : null;

            $this->recordStep(
                $call,
                $arguments,
                $result['ok'],
                $result['output'] . ($verification === null ? '' : "\n\n" . $verification['note']),
                \microtime(true) - $before,
                $machine,
                $result['diff'] ?? null,
                $verification,
            );
        }

        if ($this->step >= $this->maxSteps()) {
            $this->state = 'done';
            $this->answer = \trim($this->buffer) . "\n\n(stopped after " . $this->maxSteps() . ' steps)';
            $this->record();
            $this->push('onTaskFinished', $this->state());

            return;
        }

        $this->think();
    }

    /**
     * Write the transcript. Called wherever the loop stops, so a task that fails
     * or is stopped is still a session the reader can read afterwards.
     */
    private function record(): void
    {
        if ($this->sessions === null || $this->sessionId === null) {
            return;
        }

        $this->sessions->finish($this->sessionId, $this->state(), $this->messages, $this->jobId);
    }

    /** Does this model reason before answering? Asked, then remembered. */
    private function supportsThinking(string $model): bool
    {
        if (isset($this->thinks[$model])) {
            return $this->thinks[$model];
        }

        $capabilities = $this->ollama?->show($model)['capabilities'] ?? [];

        return $this->thinks[$model] = \is_array($capabilities)
            && \in_array('thinking', $capabilities, true);
    }

    /** The reader allowed or refused the command the model asked for. */
    public function approve(bool $allowed): array
    {
        if ($this->state !== 'awaiting' || $this->pendingCall === null) {
            return ['ok' => false, 'error' => 'nothing is waiting for approval'];
        }

        $call = $this->pendingCall;
        $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
        $arguments = $function['arguments'] ?? [];

        if (\is_string($arguments)) {
            $decoded = \json_decode($arguments, true);
            $arguments = \is_array($decoded) ? $decoded : [];
        }

        $this->pendingCall = null;

        if (!$allowed) {
            $this->recordStep($call, \is_array($arguments) ? $arguments : [], false, 'the reader refused this command', 0.0);
            $this->advance();

            return ['ok' => true, 'allowed' => false];
        }

        $this->startCommand($call, \trim((string) (\is_array($arguments) ? ($arguments['command'] ?? '') : '')));

        return ['ok' => true, 'allowed' => true];
    }

    private function startCommand(array $call, string $command): void
    {
        if ($this->shell === null) {
            return;
        }

        $started = $this->shell->start($command);

        if (($started['ok'] ?? false) === false) {
            $this->recordStep($call, ['command' => $command], false, (string) ($started['error'] ?? 'could not start'), 0.0);
            $this->advance();

            return;
        }

        $this->pendingCall = $call;
        $this->pendingStep = $this->step;
        $this->state = 'command';
        $this->revision = 0;

        $this->steps[] = [
            'step' => $this->step,
            'tool' => 'run_command',
            'call' => 'run_command ' . $command,
            'ok' => true,
            'running' => true,
            'output' => '',
            'ms' => 0,
            'machine' => false,
            'arguments' => ['command' => $command],
            'at' => \microtime(true),
            'revision' => 0,
        ];

        $this->push('onTaskStep', \end($this->steps));
        $this->governor->note('shell', 'running: ' . $command . ' (in the working folder, as you)');
    }

    /** An MCP call is out: wait for the answer, then carry on. */
    private function pumpMcp(): void
    {
        if ($this->mcp === null) {
            return;
        }

        $this->mcp->tick();
        $poll = $this->mcp->poll();

        if ($poll['running']) {
            return;
        }

        $call = $this->pendingCall ?? [];
        $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
        $name = (string) ($function['name'] ?? 'mcp');
        $index = \count($this->steps) - 1;

        $this->pendingCall = null;

        if ($index >= 0 && isset($this->steps[$index]['running'])) {
            $this->steps[$index]['running'] = false;
            $this->steps[$index]['output'] = \substr($poll['output'], 0, 4_000);
            $this->steps[$index]['ok'] = $poll['ok'];
            $this->steps[$index]['ms'] = (int) \round((\microtime(true) - (float) $this->steps[$index]['at']) * 1_000);
            $this->steps[$index]['revision'] = ++$this->revision;
            $this->push('onTaskStep', $this->steps[$index]);
        }

        $this->toolMessage($call, $name, $poll['output']);
        $this->state = 'thinking';
        $this->advance();
    }

    /** A command is running: show its output as it arrives, and finish the step. */
    private function pumpCommand(): void
    {
        if ($this->shell === null) {
            return;
        }

        $poll = $this->shell->poll();
        $index = \count($this->steps) - 1;

        if ($poll['output'] !== '' && $index >= 0 && isset($this->steps[$index]['running'])) {
            $this->steps[$index]['output'] = \substr($this->steps[$index]['output'] . $poll['output'], -4_000);
            $this->steps[$index]['revision'] = ++$this->revision;
            $this->push('onTaskOutput', [
                'index' => $index,
                'revision' => $this->revision,
                'output' => $this->steps[$index]['output'],
            ]);
        }

        if ($poll['running']) {
            return;
        }

        $call = $this->pendingCall ?? [];
        $output = $this->shell->collect();
        $seconds = $poll['seconds'];

        $this->pendingCall = null;

        if ($index >= 0) {
            $this->steps[$index]['running'] = false;
            $this->steps[$index]['output'] = \substr($output, 0, 4_000);
            $this->steps[$index]['ok'] = $poll['code'] === 0;
            $this->steps[$index]['ms'] = (int) \round($seconds * 1_000);
            $this->steps[$index]['revision'] = ++$this->revision;
        }

        $this->toolMessage($call, 'run_command', $output . "\n(exit " . (string) $poll['code'] . ', ' . \number_format($seconds, 1) . 's)');
        $this->push('onTaskStep', $index >= 0 ? $this->steps[$index] : []);

        $this->state = 'thinking';
        $this->advance();
    }

    /**
     * The command the agent is running, or is waiting for permission to run.
     * The window shows it, so it has to be readable in both states.
     */
    private function pendingCommand(): string
    {
        if ($this->pendingCall !== null) {
            $function = \is_array($this->pendingCall['function'] ?? null) ? $this->pendingCall['function'] : [];
            $arguments = $function['arguments'] ?? [];

            if (\is_string($arguments)) {
                $decoded = \json_decode($arguments, true);
                $arguments = \is_array($decoded) ? $decoded : [];
            }

            return \trim((string) (\is_array($arguments) ? ($arguments['command'] ?? '') : ''));
        }

        return $this->shell !== null && $this->state === 'command' ? $this->shell->command() : '';
    }

    /**
     * Did the change that was claimed actually happen?
     *
     * Only for tools that change something on disk. The check is deliberately
     * cheap and specific: a write is the right size, an edit's replacement is
     * present in the file. It does not claim to understand the content.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{ok: bool, note: string}|null
     */
    private function verify(string $name, array $arguments): ?array
    {
        $path = (string) ($arguments['path'] ?? '');

        if ($path === '') {
            return null;
        }

        if ($name === 'write_file') {
            $expected = \strlen((string) ($arguments['content'] ?? ''));
            $stat = $this->tools->stat($path);

            if (($stat['ok'] ?? false) !== true) {
                return ['ok' => false, 'note' => '✗ verified: nothing is on disk at ' . $path];
            }

            $bytes = (int) ($stat['bytes'] ?? -1);

            return $bytes === $expected
                ? ['ok' => true, 'note' => '✓ verified: ' . $path . ' is on disk, ' . $bytes . ' bytes, as written']
                : ['ok' => false, 'note' => '✗ verified: ' . $path . ' is ' . $bytes . ' bytes on disk, not the '
                    . $expected . ' that were written'];
        }

        if ($name === 'edit_file') {
            $replacement = (string) ($arguments['replace'] ?? '');
            $read = $this->tools->run('read_file', ['path' => $path]);

            if (($read['ok'] ?? false) !== true) {
                return ['ok' => false, 'note' => '✗ verified: could not read ' . $path . ' back'];
            }

            if ($replacement === '') {
                return ['ok' => true, 'note' => '✓ verified: ' . $path . ' is still there after the removal'];
            }

            return \str_contains($read['output'], $replacement)
                ? ['ok' => true, 'note' => '✓ verified: the change is in ' . $path]
                : ['ok' => false, 'note' => '✗ verified: ' . $path . ' does not contain what the edit said it put there'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $call
     * @param array<string, mixed> $arguments
     * @param array{ok: bool, note: string}|null $verification
     */
    private function recordStep(
        array $call,
        array $arguments,
        bool $ok,
        string $output,
        float $seconds,
        bool $machine = false,
        ?string $diff = null,
        ?array $verification = null,
    ): void {
        $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
        $name = (string) ($function['name'] ?? '');

        $record = [
            'step' => $this->step,
            'tool' => $name,
            'call' => $this->tools->describe($name, $arguments),
            'ok' => $ok,
            'output' => \substr($output, 0, 4_000),
            'ms' => (int) \round($seconds * 1_000),
            'machine' => $machine || $this->tools->leavesTheMachine($name),
            'arguments' => $arguments,
            'at' => \microtime(true),
            'revision' => ++$this->revision,
        ];

        // An edit carries its diff, so the window can show what changed rather
        // than only what was asked for.
        if ($diff !== null) {
            $record['diff'] = $diff;
        }

        if ($verification !== null) {
            $record['verified'] = $verification['ok'];
            $record['verification'] = $verification['note'];
        }

        $this->steps[] = $record;

        if ($record['machine']) {
            $this->governor->note('web', $record['call'] . ' — this request left the machine');
        }

        $this->push('onTaskStep', $record);
        $this->toolMessage($call, $name, $output);
    }

    /** @param array<string, mixed> $call */
    private function toolMessage(array $call, string $name, string $content): void
    {
        $message = [
            'role' => 'tool',
            'content' => $content,
            'tool_name' => $name,
        ];

        if (isset($call['id'])) {
            $message['tool_call_id'] = (string) $call['id'];
        }

        // `tool_call_id` is what OpenAI-shaped clients expect; Ollama uses
        // `tool_name`. Sending both keeps one loop honest across both shapes.
        $this->messages[] = $message;
    }

    /** @return array<string, mixed> */
    private function think(int $multiplier = 0): array
    {
        // A real file blows through an 8k window in two reads, and running out
        // silently drops the oldest turns — including the task. So the history is
        // trimmed here, deliberately, and the count is reported.
        if ($this->context !== null) {
            $trimmed = $this->context->trim($this->messages, $this->model, $this->room());
            $this->messages = $trimmed['messages'];
            $this->window = [
                'used' => $trimmed['tokens'],
                'budget' => $trimmed['budget'],
                'shortened' => $trimmed['trimmed'],
                'digested' => $trimmed['digested'],
                'dropped' => $trimmed['dropped'],
            ];

            if ($trimmed['trimmed'] > 0 || $trimmed['digested'] > 0 || $trimmed['dropped'] > 0) {
                $this->governor->note('context', sprintf(
                    'trimmed %d result(s), digested %d older one(s) into a line each, dropped %d message(s) to stay inside the %s-token window',
                    $trimmed['trimmed'],
                    $trimmed['digested'],
                    $trimmed['dropped'],
                    \number_format($trimmed['budget']),
                ));
            }
        }

        $payload = [
            'model' => $this->model,
            'messages' => $this->messages,
            'stream' => true,
            'tools' => $this->tools->definitions(),
            'options' => $this->governor->options(
                ['temperature' => 0.2, 'num_ctx' => $this->room()],
                $multiplier > 0 ? $multiplier : ($this->supportsThinking($this->model) ? 2 : 1),
            ),
        ];

        /*
         * Reasoning models must be *allowed* to think.
         *
         * With `think: false`, qwen3 does not stop reasoning — it reasons in the
         * open, in `content`. Measured on the 30B: the whole chain of thought
         * arrived as the assistant's answer, with no tool call in it, and the loop
         * ended there believing the task was done. With `think: true` the same
         * model puts that reasoning in `message.thinking` and returns a clean
         * `content` — 741 characters of thinking and the word "ok" as the answer.
         */
        if ($this->supportsThinking($this->model)) {
            $payload['think'] = true;
        }

        try {
            $this->stream = $this->http->open('POST', '/api/chat', $payload, 30.0);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $this->pending = '';
        $this->buffer = '';
        $this->turnThinking = 0;
        $this->state = 'thinking';

        return ['ok' => true, 'step' => $this->step];
    }

    /** @return array<string, mixed> */
    private function fail(string $error): array
    {
        $this->error = $error;
        $this->state = 'failed';
        $this->stream = null;
        $this->record();
        $this->push('onTaskFinished', $this->state());

        return ['ok' => false, 'error' => $error];
    }

    private function systemPrompt(): string
    {
        $web = $this->tools->webEnabled()
            ? "You may search the web and fetch individual pages with web_search and web_fetch.\n"
                . "Those two tools send data off this machine, so use them when the task actually needs them\n"
                . "and not otherwise. Local and private addresses are refused."
            : 'The web tools are switched off in this application, so work from the folder and from what you already know.';

        $shell = $this->shell !== null && $this->shell->enabled()
            ? "You may run commands with run_command. They run in the working folder, as the reader, and they\n"
                . "can change things on this machine. Commands that only look — ls, cat, grep, git status, git diff —\n"
                . "run immediately; anything that could write waits for the reader to allow it; a few are refused\n"
                . "outright. Do not try to disguise a command to get it past that gate: if it is refused, say what\n"
                . "you needed and why."
            : 'Running commands is switched off in this application, so work with the file tools you have.';

        $knowledge = [];

        $skills = $this->skills?->catalogue() ?? [];

        if ($skills !== []) {
            $names = \array_map(static fn (array $skill): string => $skill['name'], $skills);
            $knowledge[] = 'This application has skills the reader wrote — instructions for particular kinds of work. '
                . 'Call read_skill with one of these names when the task is that kind of work: ' . \implode(', ', $names) . '.';
        }

        if ($this->documents !== null && $this->documents->stats()['files'] > 0) {
            $knowledge[] = 'The working folder holds documents. search_documents finds text in them by keyword; '
                . 'use it before claiming something is not there.';
        }

        return \str_replace(
            ['%WORKSPACE%', '%WEB%', '%SHELL%', '%KNOWLEDGE%'],
            [(string) $this->tools->workspace(), $web, $shell, \implode("\n\n", $knowledge)],
            self::SYSTEM,
        );
    }

    /** @param array<string, mixed> $payload */
    private function push(string $event, array $payload): void
    {
        if ($this->app === null) {
            return;
        }

        $script = \sprintf(
            'window.quiesce && window.quiesce.%s(%s);',
            $event,
            \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}',
        );

        try {
            $this->app->webview->scripts->eval($script);
        } catch (\Throwable) {
            // A window that is not there yet, or is closing. Not worth an
            // exception in the middle of a task.
        }
    }
}
