<?php

declare(strict_types=1);

namespace App;

/**
 * Looking at images, with a local vision model.
 *
 * The model is chosen by asking Ollama what it can do rather than by a list
 * baked in here: `/api/show` reports capabilities, and any model that says
 * `vision` is a candidate. If none is installed the tool says so instead of
 * sending a picture to a model that cannot see it — a text model handed an
 * image will happily invent an answer, which is worse than a refusal.
 *
 * Images are read from the working folder only, by the same `realpath()` rule the
 * other file tools use, and only up to a size that a small model can actually
 * take: moondream downsamples everything to 378 px anyway.
 *
 * Not `final`, for the same reason `Hardware` is not: a test needs to say "a
 * vision model is installed" without installing one.
 */
class Vision
{
    public const int MAX_BYTES = 12_582_912;

    /** @var list<string> */
    private array $models = [];

    private float $probed = 0.0;

    public function __construct(
        private readonly Settings $settings,
        private readonly Ollama $ollama,
    ) {}

    /** Vision-capable models, asked of the engine and cached for a minute. */
    public function models(): array
    {
        if ($this->probed > 0.0 && \microtime(true) - $this->probed < 60.0) {
            return $this->models;
        }

        $this->probed = \microtime(true);
        $this->models = [];
        $preferred = $this->settings->get('vision_model');

        foreach ($this->ollama->models() as $model) {
            $name = (string) $model['name'];
            $show = $this->ollama->show($name);
            $capabilities = \is_array($show['capabilities'] ?? null) ? $show['capabilities'] : [];

            if (\in_array('vision', $capabilities, true)) {
                $this->models[] = $name;
            }
        }

        if (\is_string($preferred) && $preferred !== '' && \in_array($preferred, $this->models, true)) {
            $this->models = \array_values(\array_unique([$preferred, ...$this->models]));
        }

        return $this->models;
    }

    public function chosen(): ?string
    {
        return $this->models()[0] ?? null;
    }

    /**
     * @return array{ok: bool, output: string}
     */
    public function describe(string $path, string $question = ''): array
    {
        $root = $this->settings->get('workspace');

        if (!\is_string($root) || !\is_dir($root)) {
            return ['ok' => false, 'output' => 'no working folder has been chosen yet'];
        }

        $model = $this->chosen();

        if ($model === null) {
            return [
                'ok' => false,
                'output' => 'no vision model is installed. Pull one first, for example: ollama pull moondream',
            ];
        }

        $target = $this->resolve($root, $path);

        if ($target === null) {
            return ['ok' => false, 'output' => 'refused: "' . $path . '" is not a readable image inside the working folder'];
        }

        $size = @\filesize($target);

        if ($size === false || $size > self::MAX_BYTES) {
            return ['ok' => false, 'output' => 'that image is larger than ' . \number_format(self::MAX_BYTES / 1_048_576) . ' MB'];
        }

        $bytes = @\file_get_contents($target);

        if (!\is_string($bytes) || $bytes === '') {
            return ['ok' => false, 'output' => 'could not read that image'];
        }

        $plain = 'Describe this image plainly: what it shows, and any text in it.';
        $question = \trim($question);

        if ($question === '') {
            $question = $plain;
        }

        // A one-billion-parameter vision model intermittently answers nothing at
        // all — measured, not assumed: the same request to the same server comes
        // back with a sentence, then with an empty `done`, then with a sentence.
        // Each attempt is a short generation, so three of them cost little and
        // turn a dead end into an answer most of the time. The wording changes
        // because re-sending the identical request is the least likely to help.
        $attempts = [$question, 'What is in this image?', $plain];
        $answer = '';

        foreach ($attempts as $index => $attempt) {
            /*
             * Unload first, every time. Measured on this machine, with the same
             * image and the same request: a freshly loaded model answered twice in
             * a row, and then — still warm — answered nothing three times in a row.
             * A three-attempt retry against a warm model therefore retried into
             * the same wall three times. Loading moondream costs about a second,
             * and a second is worth an answer.
             */
            if ($index > 0) {
                $this->ollama->unload($model);
                \usleep(150_000);
            }

            $answer = $this->ask($model, $attempt, $bytes);

            if ($answer !== '') {
                break;
            }
        }

        if ($answer === '') {
            // Unload it. Measured: the identical request to this model answered
            // 8 times in 10, then 1 in 6 minutes later — the reliability drifts,
            // and the one remedy that helps is a cold load, so the next attempt
            // starts from a clean state rather than from whatever this one became.
            $this->ollama->unload($model);

            return ['ok' => false, 'output' => 'the vision model (' . $model . ') looked three times and said nothing — '
                . 'that happens with small vision models. It has been unloaded, so trying again starts it fresh.'];
        }

        return [
            'ok' => true,
            'output' => $answer . "\n\n(seen by " . $model . ', locally)',
        ];
    }

    /** One question to the vision model. Returns '' when it answers nothing. */
    private function ask(string $model, string $question, string $bytes): string
    {
        try {
            $response = $this->ollama->http()->json('POST', '/api/chat', [
                'model' => $model,
                'stream' => false,
                'messages' => [[
                    'role' => 'user',
                    'content' => $question,
                    'images' => [\base64_encode($bytes)],
                ]],
                'options' => [
                    'num_predict' => 320,
                    'temperature' => 0.2,
                ],
                'keep_alive' => '2m',
            ], 180.0);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $content = $response['json']['message']['content'] ?? null;

        return \is_string($content) ? \trim($content) : '';
    }

    /** A path inside the folder that is actually an image file. */
    private function resolve(string $root, string $relative): ?string
    {
        if ($relative === '' || \str_contains($relative, "\0") || \str_starts_with($relative, '/')) {
            return null;
        }

        $real = \realpath($root . '/' . $relative);

        if ($real === false || !\is_file($real) || !\str_starts_with($real, \rtrim($root, '/') . '/')) {
            return null;
        }

        $extension = \strtolower(\pathinfo($real, \PATHINFO_EXTENSION));

        if (!\in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'], true)) {
            return null;
        }

        return $real;
    }
}
