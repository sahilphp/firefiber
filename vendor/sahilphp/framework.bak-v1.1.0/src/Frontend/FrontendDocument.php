<?php

declare(strict_types=1);

namespace Sahil\Frontend;

use Sahil\View\Escaper;
use Sahil\Contracts\Session\SessionContract;

/**
 * The HTML document for one frontend page.
 *
 * Structure is deliberately framework-neutral so Vue, React and Svelte
 * pages look the same to the server:
 *
 *  - `<div id="app" data-component="…">` mount point,
 *  - `<script type="application/json" id="app-props">` payload (data, not
 *    executable code, and escaped by {@see Escaper::json()}),
 *  - the adapter's module scripts and stylesheets.
 *
 * Every value is escaped here; adapters cannot inject markup.
 */
final class FrontendDocument
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool|int|float|string> $attributes
     */
    public function __construct(
        private readonly FrontendConfiguration $configuration,
        private readonly string $component,
        private readonly array $payload,
        private readonly array $attributes,
        private readonly FrontendAssets $assets,
        private readonly string $title,
    private readonly ?SessionContract $session = null,
    ) {
        if (preg_match('#^[A-Za-z0-9_@/.-]+$#', $this->component) !== 1) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'Invalid frontend component [%s]; use letters, digits and / _ @ . -.',
                $this->component,
            ));
        }
    }
    private function csrfMetaTag(): string
    {
        if ($this->session === null) {
            return '';
        }

        $token = $this->session->token();

        return sprintf(
            '<meta name="csrf-token" content="%s">',
            Escaper::html($token),
        );
    }


    public function html(): string
    {
        $mountId = $this->configuration->mountId();

        $attributes = sprintf(' id="%s" data-component="%s"', Escaper::html($mountId), Escaper::html($this->component));

        foreach ($this->attributes as $name => $value) {
            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/', $name) !== 1) {
                throw new InvalidFrontendConfigurationException(sprintf('Invalid mount attribute name [%s].', $name));
            }

            if (str_starts_with(strtolower($name), 'on')) {
                throw new InvalidFrontendConfigurationException(sprintf(
                    'Event-handler attribute [%s] is not allowed on the frontend mount element.',
                    $name,
                ));
            }

            if ($value === true) {
                $attributes .= ' '.Escaper::html($name);

                continue;
            }

            if ($value === false) {
                continue;
            }

            if (!is_scalar($value)) {
                throw new InvalidFrontendConfigurationException(sprintf(
                    'Mount attribute [%s] must be a scalar value, got %s.',
                    $name,
                    get_debug_type($value),
                ));
            }

            $attributes .= sprintf(' %s="%s"', Escaper::html($name), Escaper::html((string) $value));
        }

$head = ['<meta charset="utf-8">', '<meta name="viewport" content="width=device-width, initial-scale=1">', $this->csrfMetaTag()];

        foreach ($this->assets->styles() as $href) {
            $head[] = sprintf('<link rel="stylesheet" href="%s">', Escaper::html($href));
        }

        $body = [sprintf('<div%s></div>', $attributes)];

        $body[] = sprintf(
            '<script type="application/json" id="%s-props">%s</script>',
            Escaper::html($mountId),
            $this->encodedPayload(),
        );

        foreach ($this->assets->scripts() as $src) {
            $body[] = $this->assets->usesModules()
                ? sprintf('<script type="module" src="%s"></script>', Escaper::html($src))
                : sprintf('<script src="%s"></script>', Escaper::html($src));
        }

        return implode("\n", [
            '<!doctype html>',
            sprintf('<html lang="%s">', Escaper::html($this->configuration->lang())),
            '<head>',
            implode("\n", [...$head, '<title>'.Escaper::html($this->title).'</title>']),
            '</head>',
            '<body>',
            implode("\n", $body),
            '</body>',
            '</html>',
        ]);
    }

    private function encodedPayload(): string
    {
        try {
            return Escaper::json($this->payload);
        } catch (\Throwable $failure) {
            throw new InvalidFrontendConfigurationException(
                'The frontend payload cannot be encoded as JSON: '.$failure->getMessage(),
                0,
                $failure,
            );
        }
    }


}
