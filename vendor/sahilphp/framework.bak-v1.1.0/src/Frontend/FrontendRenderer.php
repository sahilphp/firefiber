<?php

declare(strict_types=1);

namespace Sahil\Frontend;

use Sahil\Http\Response;
use Sahil\View\RenderedView;

/**
 * Turns a response that targets a JavaScript frontend into a complete HTML
 * document.
 *
 * This is the single place where mount markup, payload serialization and
 * asset tags are produced, so adapters stay small and cannot drift apart in
 * safety behaviour. The renderer is a normal container service: it holds no
 * static state, and the kernel receives it through its constructor.
 */
final class FrontendRenderer
{
    public function __construct(
        private readonly FrontendManager $adapters,
        private readonly FrontendConfiguration $configuration,
    ) {
    }

    /**
     * Finalize a frontend response; native/HTML responses pass through.
     */
    public function render(Response $response): Response
    {
        $target = $response->frontend();

        if ($target === null) {
            return $response;
        }

        $view = $response->renderedView();

        if ($view === null || !$view->hasTemplate()) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'The [%s] response has no rendered view metadata. Render with a view engine that returns %s '
                .'(for example $views->renderView($template, $data)) before calling Response::%s().',
                $target,
                RenderedView::class,
                $target,
            ));
        }

        $adapter = $this->adapters->adapter($target);
        $component = $adapter->component($view);
        $payload = $adapter->payload($view);
        $assets = $adapter->assets($this->configuration->development());

        $this->assertAssetUrls($assets);

        $document = new FrontendDocument(
            $this->configuration,
            $component,
            $payload,
            $adapter->mountAttributes($view),
            $assets,
            $this->title($view),
        );

        $finalized = new Response($response->status(), ['Content-Type' => 'text/html; charset=utf-8'], $document->html());

        foreach ($response->headers() as $name => $values) {
            if (in_array(strtolower($name), ['content-type', 'content-length'], true)) {
                continue;
            }

            $finalized = $finalized->withHeader($name, $values);
        }

        return $finalized;
    }

    private function title(RenderedView $view): string
    {
        $value = $view->data();

        foreach (explode('.', $this->configuration->titleKey()) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;

                break;
            }

            $value = $value[$segment];
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            $title = trim((string) $value);

            if ($title !== '') {
                return $title;
            }
        }

        return $this->configuration->defaultTitle();
    }

    private function assertAssetUrls(FrontendAssets $assets): void
    {
        foreach ([...$assets->scripts(), ...$assets->styles()] as $url) {
            if (!is_string($url) || $url === '') {
                throw new InvalidFrontendConfigurationException('Frontend asset URLs must be non-empty strings.');
            }

            if (preg_match('/[\x00-\x1F\x7F"\'<>]/', $url) === 1) {
                throw new InvalidFrontendConfigurationException(sprintf(
                    'Frontend asset URL [%s] contains characters that are not allowed.',
                    $url,
                ));
            }

            if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $url) === 1
                && preg_match('#^https?://#i', $url) !== 1) {
                throw new InvalidFrontendConfigurationException(sprintf(
                    'Frontend asset URL [%s] must be http(s), root-relative or relative.',
                    $url,
                ));
            }
        }
    }
}
