<?php

declare(strict_types=1);

namespace Sahil\View;

/**
 * Compiles a template into a cached PHP file.
 *
 * The template language is deliberately small and total — text, escaped and
 * raw echoes, conditionals, loops, sections/inheritance and includes — and
 * EVERY line of generated code is authored here from parsed input. Nothing
 * from a template is ever handed to `eval()`, and nothing is written into
 * the compiled file as code: literal text goes through `var_export()`, and
 * expressions are parsed by {@see ExpressionCompiler} and re-emitted as PHP
 * we produce ourselves.
 *
 * Because of that, a template cannot contain PHP: a `<?php` / `<?=` tag is a
 * compile error rather than text that could be interpreted when the compiled
 * file is included.
 *
 * Cache invalidation is CONTENT- and VERSION-based: the compiled file records
 * a hash of the template it was built from (and the compiler cache format
 * version) and is rebuilt when either changes, so a deployment that rewrites
 * a file without touching its mtime, or an upgrade that changes emitted PHP,
 * cannot serve a stale template.
 */
final class TemplateCompiler
{
    /**
     * Bump when a compiler change can alter emitted PHP; old compiled files
     * then miss the freshness check and are regenerated.
     */
    private const CACHE_VERSION = '3';

    /** Directives the language understands; anything else is literal text. */
    private const DIRECTIVES = [
        'if', 'elseif', 'else', 'endif',
        'foreach', 'endforeach',
        'section', 'parent', 'endsection',
        'extends', 'yield', 'include', 'json',
        'asset_css', 'asset_js', 'asset_client',
    ];

    /** Directives that take no arguments at all. */
    private const ARGLESS = ['else', 'endif', 'endforeach', 'parent', 'endsection', 'asset_client'];

    /** Constructs that are intentionally unsupported. */
    private const REJECTED = ['php', 'endphp', 'eval'];

    /**
     * Names a `@foreach` binding may not use: they are literals or operators
     * in the expression language, so a binding with one of these names could
     * never be read.
     */
    private const RESERVED_BINDINGS = ['true', 'false', 'null', 'and', 'or', 'not', 'in'];

    /** @var list<array{name: string, line: int, locals: int}> open blocks, innermost last */
    private array $blocks = [];

    private string $template = '';

    private int $line = 1;

    /** Number of `@foreach` blocks compiled so far (unique loop variables). */
    private int $loopCounter = 0;

    public function __construct(private readonly string $cachePath)
    {
    }

    public function cachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Path of the compiled file for a template, compiling when the cache is
     * missing or stale.
     */
    public function compileIfNeeded(string $template, string $source): string
    {
        $contents = $this->readSource($template, $source);
        $compiled = $this->compiledPath($source);

        if ($this->isFresh($compiled, $contents)) {
            return $compiled;
        }

        return $this->compile($template, $source, $contents);
    }

    /**
     * Compile unconditionally and return the compiled path.
     */
    public function compile(string $template, string $source, ?string $contents = null): string
    {
        $contents ??= $this->readSource($template, $source);

        $this->template = $template;
        $this->line = 1;
        $this->blocks = [];
        $this->loopCounter = 0;

        $body = $this->emit($contents);

        $compiled = $this->compiledPath($source);

        $this->write($compiled, $template, $source, sha1($contents), $body);

        return $compiled;
    }

    /**
     * Remove every compiled template (deploy-time cache reset).
     *
     * @return int number of files removed
     */
    public function flush(): int
    {
        if (!is_dir($this->cachePath)) {
            return 0;
        }

        $files = glob(rtrim($this->cachePath, '/').'/*.php');

        if ($files === false) {
            return 0;
        }

        $removed = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Deterministic path of a template's compiled file.
     */
    public function compiledPath(string $source): string
    {
        return rtrim($this->cachePath, '/').'/'.sha1($source).'.php';
    }

    /**
     * Whether a compiled file exists and was built from exactly this content.
     */
    public function isFresh(string $compiled, string $contents): bool
    {
        if (!is_file($compiled)) {
            return false;
        }

        $handle = @fopen($compiled, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fgets($handle, 4096);
        fclose($handle);

        return is_string($header)
            && str_contains($header, 'sahilphp-compiled:'.self::CACHE_VERSION.':'.sha1($contents));
    }

    private function readSource(string $template, string $source): string
    {
        if (!is_file($source) || !is_readable($source)) {
            throw new ViewException(sprintf('Template [%s] at [%s] is not readable.', $template, $source));
        }

        $contents = file_get_contents($source);

        if ($contents === false) {
            throw new ViewException(sprintf('Template [%s] could not be read.', $template));
        }

        return $contents;
    }

    /* ---------- compilation ---------- */

    private function emit(string $template): string
    {
        $php = '';
        $text = '';
        $offset = 0;
        $length = strlen($template);

        while ($offset < $length) {
            $character = $template[$offset];

            if ($character === '{') {
                $tag = $this->readEchoTag($template, $offset);

                if ($tag !== null) {
                    [$kind, $inner, $offset, $tagLine] = $tag;

                    $php .= $this->flushText($text);
                    $php .= $this->compileEcho($kind, $inner, $tagLine);

                    continue;
                }
            }

            if ($character === '<' && preg_match('/\G<\?(php|=)/i', substr($template, $offset)) === 1) {
                throw new ViewSyntaxException(
                    $this->template,
                    $this->line,
                    'PHP tags are not allowed in a template; compute the value in the controller and pass it in, or use a value echo',
                );
            }

            if ($character === '@') {
                $directive = $this->readDirective($template, $offset);

                if ($directive !== null) {
                    [$name, $arguments, $offset, $tagLine] = $directive;

                    if ($name === '@literal') {
                        $text .= (string) $arguments;

                        continue;
                    }

                    $php .= $this->flushText($text);
                    $php .= $this->compileDirective($name, $arguments, $tagLine);

                    continue;
                }
            }

            if ($character === "\n") {
                ++$this->line;
            }

            $text .= $character;
            ++$offset;
        }

        $php .= $this->flushText($text);

        $this->assertAllBlocksClosed();

        return $php;
    }

    private function compileEcho(string $kind, string $inner, int $line): string
    {
        if ($kind === 'comment') {
            return '';
        }

        $expression = (new ExpressionCompiler())->compile($inner, $this->template, $line);

        return $kind === 'raw'
            ? sprintf("echo \\%s::raw(%s);\n", Escaper::class, $expression)
            : sprintf("echo \\%s::html(%s);\n", Escaper::class, $expression);
    }

    /**
     * Recognise `{{ ... }}`, `{!! ... !!}` and `{{-- ... --}}`.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}|null [kind, inner, newOffset, line]
     */
    private function readEchoTag(string $template, int $offset): ?array
    {
        $rest = substr($template, $offset);
        $tagLine = $this->line;

        $closers = [
            '{{--' => ['comment', '--}}', false],
            '{!!' => ['raw', '!!}', true],
            '{{' => ['escaped', '}}', true],
        ];

        foreach ($closers as $opener => [$kind, $closer, $respectQuotes]) {
            if (!str_starts_with($rest, $opener)) {
                continue;
            }

            $end = $this->findClosing($template, $offset + strlen($opener), $closer, $respectQuotes);

            if ($end === null) {
                throw new ViewSyntaxException(
                    $this->template,
                    $tagLine,
                    sprintf('Unclosed output tag %s ... %s', $opener, $closer),
                );
            }

            $inner = substr($template, $offset + strlen($opener), $end - ($offset + strlen($opener)));
            $newOffset = $end + strlen($closer);

            $this->line += substr_count(substr($template, $offset, $newOffset - $offset), "\n");

            return [$kind, $inner, $newOffset, $tagLine];
        }

        return null;
    }

    /**
     * Recognise `@name` or `@name(...)`.
     *
     * An `@` that is not a known directive stays literal text (so
     * `user@example.com` and CSS at-rules keep working), and `@@name` is an
     * escaped literal `@name`.
     *
     * @return array{0: string, 1: ?string, 2: int, 3: int}|null
     */
    private function readDirective(string $template, int $offset): ?array
    {
        $rest = substr($template, $offset);
        $tagLine = $this->line;

        if (str_starts_with($rest, '@@')) {
            $cursor = $offset + 2;

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', substr($template, $cursor), $matches) === 1) {
                $cursor += strlen($matches[0]);

                return ['@literal', '@'.$matches[0], $cursor, $tagLine];
            }

            return ['@literal', '@', $cursor, $tagLine];
        }

        if (preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/', $rest, $matches) !== 1) {
            return null;
        }

        $name = strtolower($matches[1]);
        $cursor = $offset + strlen($matches[0]);

        if (in_array($name, self::REJECTED, true)) {
            throw new ViewSyntaxException(
                $this->template,
                $tagLine,
                sprintf(
                    '@%s is not supported: templates cannot contain PHP. Compute the value in the controller and pass it in',
                    $name,
                ),
            );
        }

        if (!in_array($name, self::DIRECTIVES, true)) {
            return null; // literal text
        }

        $arguments = null;

        // Argument-less directives never consume a following `(`, so
        // `@else (see the docs)` keeps its text; an ADJACENT `@else(...)` is
        // a mistake worth reporting.
        if (in_array($name, self::ARGLESS, true)) {
            if (($template[$cursor] ?? '') === '(') {
                throw new ViewSyntaxException(
                    $this->template,
                    $tagLine,
                    sprintf('@%s does not take arguments.', $name),
                );
            }
        } else {
            // `@if (x)` and `@if(x)` are both valid.
            $gap = preg_match('/\G[ \t]*/', substr($template, $cursor), $gapMatches) === 1
                ? strlen($gapMatches[0])
                : 0;

            if (($template[$cursor + $gap] ?? '') === '(') {
                [$arguments, $cursor] = $this->readBalanced($template, $cursor + $gap, $name, $tagLine);
            }
        }

        $this->line += substr_count(substr($template, $offset, $cursor - $offset), "\n");

        return [$name, $arguments, $cursor, $tagLine];
    }

    /**
     * Read a balanced `( ... )` group, respecting string literals.
     *
     * @return array{0: string, 1: int} inner text and the offset after ')'
     */
    private function readBalanced(string $template, int $offset, string $name, int $line): array
    {
        $length = strlen($template);
        $depth = 0;
        $index = $offset;

        while ($index < $length) {
            $character = $template[$index];

            if ($character === "'" || $character === '"') {
                $skipped = $this->skipString($template, $index);

                if ($skipped === null) {
                    throw new ViewSyntaxException(
                        $this->template,
                        $line,
                        sprintf('Unterminated string literal inside @%s(...)', $name),
                    );
                }

                $index = $skipped;

                continue;
            }

            if ($character === '(') {
                ++$depth;
            } elseif ($character === ')') {
                --$depth;

                if ($depth === 0) {
                    return [substr($template, $offset + 1, $index - $offset - 1), $index + 1];
                }
            }

            ++$index;
        }

        throw new ViewSyntaxException($this->template, $line, sprintf('Unclosed parentheses in @%s(...)', $name));
    }

    /**
     * Find a closing sequence, optionally ignoring matches inside strings.
     */
    private function findClosing(string $template, int $offset, string $closer, bool $respectQuotes): ?int
    {
        $length = strlen($template);
        $index = $offset;

        while ($index < $length) {
            $character = $template[$index];

            if ($respectQuotes && ($character === "'" || $character === '"')) {
                $skipped = $this->skipString($template, $index);

                if ($skipped === null) {
                    return null;
                }

                $index = $skipped;

                continue;
            }

            if (substr($template, $index, strlen($closer)) === $closer) {
                return $index;
            }

            ++$index;
        }

        return null;
    }

    /**
     * Advance past a quoted string starting at $offset, or null when it is
     * unterminated.
     */
    private function skipString(string $template, int $offset): ?int
    {
        $quote = $template[$offset];
        $length = strlen($template);
        $index = $offset + 1;

        while ($index < $length) {
            if ($template[$index] === '\\') {
                $index += 2;

                continue;
            }

            if ($template[$index] === $quote) {
                return $index + 1;
            }

            ++$index;
        }

        return null;
    }

    /**
     * Emit the pending literal text and CLEAR the buffer, so each character
     * of the template is written exactly once.
     */
    private function flushText(string &$text): string
    {
        if ($text === '') {
            return '';
        }

        $code = 'echo '.var_export($text, true).";\n";
        $text = '';

        return $code;
    }

    /* ---------- directives ---------- */

    private function compileDirective(string $name, ?string $arguments, int $line): string
    {
        if ($arguments !== null && in_array($name, self::ARGLESS, true)) {
            throw new ViewSyntaxException($this->template, $line, sprintf('@%s does not take arguments.', $name));
        }

        return match ($name) {
            'if' => $this->openBlock('if', $line, sprintf("if (%s):\n", $this->expression($arguments, '@if', $line))),
            'elseif' => $this->insideBlock('if', $line, '@elseif', sprintf(
                "elseif (%s):\n",
                $this->expression($arguments, '@elseif', $line),
            )),
            'else' => $this->insideBlock('if', $line, '@else', "else:\n"),
            'endif' => $this->closeBlock('if', $line, '@endif', "endif;\n"),
            'foreach' => $this->compileForeach($arguments, $line),
            'endforeach' => $this->compileEndForeach($line),
            'section' => $this->compileSection($arguments, $line),
            'parent' => $this->insideBlock('section', $line, '@parent', "\$__ctx->requireParent();\n"),
            'endsection' => $this->closeBlock('section', $line, '@endsection', "\$__ctx->endSection();\n"),
            'extends' => sprintf("\$__ctx->extend(%s);\n", $this->templateLiteral($arguments, '@extends', $line)),
            'yield' => $this->compileYield($arguments, $line),
            'include' => $this->compileInclude($arguments, $line),
            'json' => sprintf("echo \\%s::json(%s);\n", Escaper::class, $this->expression($arguments, '@json', $line)),
            'asset_css' => $this->compileAsset('css', $arguments, $line),
            'asset_js' => $this->compileAsset('js', $arguments, $line),
            'asset_client' => "echo \$__ctx->assetClient();\n",
            default => throw new ViewSyntaxException($this->template, $line, sprintf('Unknown directive @%s.', $name)),
        };
    }

    /**
     * `@foreach (items as item)` / `@foreach (items as key => item)`.
     *
     * Bindings are ordinary names in the template's data namespace, not PHP
     * variables: the loop value is pushed as a scoped binding for the body
     * and popped again by `@endforeach`, so a template never reads or
     * creates PHP state and nested loops shadow correctly.
     */
    private function compileForeach(?string $arguments, int $line): string
    {
        $source = $this->requireArguments($arguments, '@foreach', $line);
        $asPosition = $this->findTopLevelAs($source);

        if ($asPosition === null) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                '@foreach expects `@foreach (items as item)` or `@foreach (items as key => item)`',
            );
        }

        $iterable = $this->expression(substr($source, 0, $asPosition), '@foreach', $line);
        $binding = trim(substr($source, $asPosition + 2));

        if (str_contains($binding, '=>')) {
            $parts = array_map('trim', explode('=>', $binding, 2));
            $keyName = $parts[0];
            $valueName = $parts[1];
        } else {
            $keyName = null;
            $valueName = $binding;
        }

        if ($valueName === '') {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                '@foreach expects a binding after `as`, e.g. `@foreach (items as item)`',
            );
        }

        foreach ($keyName === null ? [$valueName] : [$valueName, $keyName] as $name) {
            $this->assertBindingName($name, '@foreach', $line);
        }

        $counter = ++$this->loopCounter;
        $valueVariable = '$__loopValue'.$counter;
        $bindings = [[$valueName, $valueVariable]];

        if ($keyName === null) {
            $header = sprintf(
                "foreach (\\%s::iterate(%s) as %s):\n",
                ViewHelpers::class,
                $iterable,
                $valueVariable,
            );
        } else {
            $keyVariable = '$__loopKey'.$counter;

            $header = sprintf(
                "foreach (\\%s::iterate(%s) as %s => %s):\n",
                ViewHelpers::class,
                $iterable,
                $keyVariable,
                $valueVariable,
            );

            $bindings[] = [$keyName, $keyVariable];
        }

        foreach ($bindings as [$name, $variable]) {
            $header .= sprintf("\$__ctx->pushLocal(%s, %s);\n", var_export($name, true), $variable);
        }

        return $this->openBlock('foreach', $line, $header, count($bindings));
    }

    /**
     * `@endforeach`: pop this loop's bindings before closing the loop.
     */
    private function compileEndForeach(int $line): string
    {
        $current = end($this->blocks);

        if ($current === false || $current['name'] !== 'foreach') {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                '@endforeach without a matching open block (expected inside @foreach)',
            );
        }

        array_pop($this->blocks);

        $locals = $current['locals'];

        return ($locals > 0 ? sprintf("\$__ctx->popLocals(%d);\n", $locals) : '')."endforeach;\n";
    }

    private function compileSection(?string $arguments, int $line): string
    {
        [$name, $rest] = $this->splitArguments($this->requireArguments($arguments, '@section', $line));

        $parent = 'false';

        if (trim($rest) !== '') {
            $parentArgument = trim($rest);

            if (!in_array($parentArgument, ['true', 'false'], true)) {
                throw new ViewSyntaxException(
                    $this->template,
                    $line,
                    '@section accepts a name and an optional true/false, or use @parent inside the block',
                );
            }

            $parent = $parentArgument;
        }

        return $this->openBlock(
            'section',
            $line,
            sprintf("\$__ctx->startSection(%s, %s);\n", $this->templateLiteral($name, '@section', $line), $parent),
        );
    }

    private function compileYield(?string $arguments, int $line): string
    {
        [$name, $rest] = $this->splitArguments($this->requireArguments($arguments, '@yield', $line));

        $template = $this->templateLiteral($name, '@yield', $line);

        if (trim($rest) === '') {
            return sprintf("echo \$__ctx->yieldSection(%s);\n", $template);
        }

        return sprintf(
            "echo \$__ctx->yieldSection(%s, %s);\n",
            $template,
            $this->expression($rest, '@yield', $line),
        );
    }

    private function compileInclude(?string $arguments, int $line): string
    {
        $parts = $this->splitTopLevel($this->requireArguments($arguments, '@include', $line), ',');

        if (count($parts) > 2) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                '@include accepts a name and an optional data array: @include(\'partial\', [\'key\' => value])',
            );
        }

        $name = $this->expression(array_shift($parts) ?? '', '@include', $line);
        $data = $parts === [] ? '[]' : $this->arrayLiteral($parts[0], '@include', $line);

        return sprintf("echo \$__ctx->include(%s, %s);\n", $name, $data);
    }

    /**
     * `@asset_css('main')` / `@asset_css('main', ['media' => 'print'])`.
     *
     * The entry is an expression so a controller can choose a page-specific
     * entry, while the optional attributes are a literal array (same rules as
     * `@include` data).
     */
    private function compileAsset(string $type, ?string $arguments, int $line): string
    {
        $directive = '@asset_'.$type;
        $parts = $this->splitTopLevel($this->requireArguments($arguments, $directive, $line), ',');

        if (count($parts) > 2) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('%s accepts an entry name and an optional attributes array.', $directive),
            );
        }

        $entry = $this->expression(array_shift($parts) ?? '', $directive, $line);
        $attributes = $parts === [] ? '[]' : $this->arrayLiteral($parts[0], $directive, $line);
        $method = $type === 'css' ? 'assetCss' : 'assetJs';

        return sprintf("echo \$__ctx->%s(%s, %s);\n", $method, $entry, $attributes);
    }

    /* ---------- literal helpers ---------- */

    /**
     * Compile `['key' => value, other => value]` into safe PHP.
     */
    private function arrayLiteral(string $source, string $directive, int $line): string
    {
        $trimmed = trim($source);

        if (!str_starts_with($trimmed, '[') || !str_ends_with($trimmed, ']')) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('%s expects an array literal such as [\'key\' => value].', $directive),
            );
        }

        $inner = trim(substr($trimmed, 1, -1));

        if ($inner === '') {
            return '[]';
        }

        $pairs = [];

        foreach ($this->splitTopLevel($inner, ',') as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $arrow = $this->findTopLevel($entry, '=>');

            if ($arrow === null) {
                // Positional value: keep the expression as its own element.
                $pairs[] = $this->expression($entry, $directive, $line);

                continue;
            }

            $key = $this->arrayKey(trim(substr($entry, 0, $arrow)), $directive, $line);
            $value = $this->expression(trim(substr($entry, $arrow + 2)), $directive, $line);

            $pairs[] = sprintf('%s => %s', $key, $value);
        }

        return '['.implode(', ', $pairs).']';
    }

    private function arrayKey(string $source, string $directive, int $line): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $source) === 1) {
            return var_export($source, true);
        }

        $literal = $this->unquote($source);

        if ($literal !== null) {
            return var_export($literal, true);
        }

        throw new ViewSyntaxException(
            $this->template,
            $line,
            sprintf('%s array keys must be plain names or quoted strings, got [%s].', $directive, $source),
        );
    }

    /**
     * A template NAME must be a literal string: names are structural, so a
     * typo fails at compile time instead of at render time.
     */
    private function templateLiteral(?string $source, string $directive, int $line): string
    {
        $trimmed = trim((string) $source);
        $literal = $this->unquote($trimmed);

        if ($literal === null) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf("%s expects a quoted template name such as 'layouts.app', got [%s].", $directive, $trimmed),
            );
        }

        return var_export($literal, true);
    }

    /**
     * Decode a simple quoted string literal, or null when it is not one.
     */
    private function unquote(string $source): ?string
    {
        $length = strlen($source);

        if ($length < 2) {
            return null;
        }

        $quote = $source[0];

        if (($quote !== "'" && $quote !== '"') || $source[$length - 1] !== $quote) {
            return null;
        }

        $body = substr($source, 1, -1);

        if (str_contains($body, $quote)) {
            return null; // an unescaped quote would have ended the literal
        }

        return str_replace(['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t'], ['\\', "'", '"', "\n", "\r", "\t"], $body);
    }

    private function expression(?string $source, string $directive, int $line): string
    {
        $trimmed = trim((string) $source);

        if ($trimmed === '') {
            throw new ViewSyntaxException($this->template, $line, sprintf('%s expects an expression.', $directive));
        }

        return (new ExpressionCompiler())->compile($trimmed, $this->template, $line);
    }

    private function requireArguments(?string $arguments, string $directive, int $line): string
    {
        if ($arguments === null || trim($arguments) === '') {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('%s requires arguments in parentheses.', $directive),
            );
        }

        return trim($arguments);
    }

    private function assertBindingName(string $binding, string $directive, int $line): void
    {
        if (str_starts_with($binding, '$')) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf(
                    '%s bindings are names, not PHP variables: write `as %s` instead of `as %s`.',
                    $directive,
                    ltrim($binding, '$'),
                    $binding,
                ),
            );
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $binding) !== 1) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('%s expects a plain name like `item`, got [%s].', $directive, $binding),
            );
        }

        if (in_array(strtolower($binding), self::RESERVED_BINDINGS, true)) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('[%s] cannot be a binding name (%s uses it as a keyword).', $binding, $directive),
            );
        }
    }

    /* ---------- block bookkeeping ---------- */

    private function openBlock(string $kind, int $line, string $header, int $locals = 0): string
    {
        $this->blocks[] = ['name' => $kind, 'line' => $line, 'locals' => $locals];

        return $header;
    }

    private function closeBlock(string $kind, int $line, string $directive, string $footer): string
    {
        if (!$this->isInside($kind)) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('%s without a matching open block (expected inside @%s)', $directive, $kind),
            );
        }

        array_pop($this->blocks);

        return $footer;
    }

    private function insideBlock(string $kind, int $line, string $directive, string $code): string
    {
        if (!$this->isInside($kind)) {
            throw new ViewSyntaxException(
                $this->template,
                $line,
                sprintf('%s is only valid inside an open @%s block', $directive, $kind),
            );
        }

        return $code;
    }

    private function isInside(string $kind): bool
    {
        $current = end($this->blocks);

        return $current !== false && $current['name'] === $kind;
    }

    private function assertAllBlocksClosed(): void
    {
        $current = end($this->blocks);

        if ($current === false) {
            return;
        }

        throw new ViewSyntaxException(
            $this->template,
            $current['line'],
            sprintf('Unclosed @%s block (no matching @end%s)', $current['name'], $current['name']),
        );
    }

    /* ---------- scanning helpers ---------- */

    /**
     * Offset of the top-level `as` keyword of a @foreach header.
     */
    private function findTopLevelAs(string $source): ?int
    {
        $length = strlen($source);
        $depth = 0;
        $index = 0;

        while ($index < $length) {
            $character = $source[$index];

            if ($character === "'" || $character === '"') {
                $skipped = $this->skipString($source, $index);

                if ($skipped === null) {
                    return null;
                }

                $index = $skipped;

                continue;
            }

            if ($character === '(' || $character === '[') {
                ++$depth;
            } elseif ($character === ')' || $character === ']') {
                --$depth;
            } elseif ($depth === 0 && ($character === ' ' || $character === "\t")) {
                if (preg_match('/\G\s+as\s+/', substr($source, $index), $matches) === 1) {
                    $leading = strlen($matches[0]) - strlen(ltrim($matches[0]));

                    return $index + $leading;
                }
            }

            ++$index;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string} first argument and the remainder
     */
    private function splitArguments(string $arguments): array
    {
        $position = $this->findTopLevel($arguments, ',');

        if ($position === null) {
            return [$arguments, ''];
        }

        return [substr($arguments, 0, $position), substr($arguments, $position + 1)];
    }

    /**
     * Offset of the first occurrence of $needle outside strings/brackets.
     */
    private function findTopLevel(string $source, string $needle): ?int
    {
        $parts = $this->splitTopLevel($source, $needle);

        return count($parts) < 2 ? null : strlen($parts[0]);
    }

    /**
     * Split on a separator that appears outside strings and brackets.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $source, string $separator): array
    {
        $parts = [];
        $length = strlen($source);
        $depth = 0;
        $start = 0;
        $index = 0;
        $separatorLength = strlen($separator);

        while ($index < $length) {
            $character = $source[$index];

            if ($character === "'" || $character === '"') {
                $skipped = $this->skipString($source, $index);

                if ($skipped === null) {
                    break;
                }

                $index = $skipped;

                continue;
            }

            if ($character === '(' || $character === '[') {
                ++$depth;
            } elseif ($character === ')' || $character === ']') {
                --$depth;
            } elseif ($depth === 0 && substr($source, $index, $separatorLength) === $separator) {
                $parts[] = substr($source, $start, $index - $start);
                $index += $separatorLength;
                $start = $index;

                continue;
            }

            ++$index;
        }

        $parts[] = substr($source, $start);

        return $parts;
    }

    private function write(string $path, string $template, string $source, string $hash, string $body): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new ViewException(sprintf('Compiled-view directory [%s] could not be created.', $directory));
        }

        $contents = sprintf(
            "<?php /* sahilphp-compiled:%s:%s template:%s source:%s */\n%s",
            self::CACHE_VERSION,
            $hash,
            str_replace('*/', '* /', $template),
            str_replace('*/', '* /', $source),
            $body,
        );

        // Write-then-rename: a concurrent reader never sees a half file.
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temporary, $contents) === false) {
            throw new ViewException(sprintf('Unable to write compiled template [%s].', $temporary));
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new ViewException(sprintf('Unable to publish compiled template [%s].', $path));
        }
    }
}
