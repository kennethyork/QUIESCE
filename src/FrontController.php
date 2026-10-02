<?php

declare(strict_types=1);

namespace App;

use Boson\Component\Http\Component\StatusCode;
use Boson\Component\Http\Response;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;

/**
 * Serves the window.
 *
 * Files come from the assets directory; anything else gets the one page this
 * application has. There is no routing table, because there is one page: this
 * is a desktop application, not a site.
 */
final readonly class FrontController
{
    public function __construct(
        private StaticProviderInterface $static,
        private string $entry,
        private string $images = '',
    ) {}

    public function __invoke(RequestInterface $request): ResponseInterface
    {
        $image = $this->image($request);

        if ($image !== null) {
            return $image;
        }

        $response = $this->static->findFileByRequest($request);

        if ($response !== null) {
            /*
             * Never cache the app's own files.
             *
             * Without this, WebKit caches /css/app.css across launches, and a window
             * can render with the *previous* stylesheet after an update — which is
             * how a stale layout appeared here and looked like a bug in the new CSS.
             * A desktop app's assets are on the same disk as the app; caching them
             * buys nothing and costs correctness.
             */
            try {
                if (method_exists($response->headers, 'set')) {
                    $response->headers->set('Cache-Control', 'no-store');
                } else {
                    $response->headers['Cache-Control'] = 'no-store';
                }
            } catch (\Throwable) {
                // A response that cannot be annotated is still a valid response.
            }

            return $response;
        }

        $page = @\file_get_contents($this->entry);

        if (!\is_string($page)) {
            return new Response(
                '<h1>Quiesce</h1><p>assets/private/view/index.html is missing.</p>',
                StatusCode::NotFound,
            );
        }

        return new Response($page, StatusCode::Ok, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Generated images are served from their own folder, and only that folder:
     * the window never gets a way to read an arbitrary file by asking for it.
     */
    private function image(RequestInterface $request): ?ResponseInterface
    {
        if ($this->images === '') {
            return null;
        }

        $path = $request->url->path->toString();

        if (!\str_starts_with($path, '/images/')) {
            return null;
        }

        $name = \substr($path, \strlen('/images/'));

        if (\preg_match('/^[A-Za-z0-9._-]+\.(png|jpg|jpeg|webp)$/', $name) !== 1) {
            return new Response('not an image name', StatusCode::NotFound);
        }

        $file = $this->images . '/' . $name;

        if (!\is_file($file) || \realpath(\dirname($file)) !== \realpath($this->images)) {
            return new Response('no such image', StatusCode::NotFound);
        }

        $type = \str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg';

        return new Response((string) @\file_get_contents($file), StatusCode::Ok, [
            'Content-Type' => $type,
            'Cache-Control' => 'no-store',
        ]);
    }
}
