<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use Boson\WebView\Event\WebViewDomReady;

/**
 * The one thing this application's window depends on: a call from JavaScript
 * reaching PHP and its answer coming back.
 *
 * Deliberately written without a timer. Boson's poller runs only the first
 * registered periodic task per cycle, so a test that adds a second timer
 * silently never runs it — the check finishes itself from inside the binding
 * instead, which is also the shorter path.
 */
final class BindingsTest extends TestCase
{
    public function testAJavaScriptCallReachesPhpAndTheAnswerComesBack(): void
    {
        $app = $this->createApplication('<div id="out">nothing</div>');

        $seen = null;

        $app->webview->bindings->bind('double', static fn (int $number): int => $number * 2);

        $app->webview->bindings->bind('done', function (string $text) use (&$seen, $app): void {
            $seen = $text;
            $app->quit();
        });

        $app->webview->on(function (WebViewDomReady $e): void {
            $e->subject->scripts->eval(
                'double(21).then(function (n) {'
                . ' document.getElementById("out").textContent = "got " + n;'
                . ' done(document.getElementById("out").textContent); });',
            );
        });

        $app->run();

        self::assertSame('got 42', $seen, 'the round trip from JavaScript to PHP and back completed');
    }
}
