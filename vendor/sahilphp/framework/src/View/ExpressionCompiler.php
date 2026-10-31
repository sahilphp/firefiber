<?php

declare(strict_types=1);

namespace Sahil\View;

/**
 * Compiles a template expression into PHP.
 *
 * This is the whole reason the template language is safe: expressions are
 * PARSED into a validated grammar and then re-emitted as PHP we author
 * ourselves. Nothing is ever passed to `eval()`, `create_function()`, a
 * string-included file or a variable function call, and no part of the
 * input can become a statement — only an expression.
 */
final class ExpressionCompiler
{
    /**
     * @var array<string, array{method: string, arity: int, context?: bool}>
     */
    private const FUNCTIONS = [
        'count' => ['method' => 'count', 'arity' => 1],
        'empty' => ['method' => 'isEmpty', 'arity' => 1],
        'upper' => ['method' => 'upper', 'arity' => 1],
        'lower' => ['method' => 'lower', 'arity' => 1],
        'trim' => ['method' => 'trim', 'arity' => 1],
        'first' => ['method' => 'first', 'arity' => 1],
        'last' => ['method' => 'last', 'arity' => 1],
        'join' => ['method' => 'join', 'arity' => 2],
        'asset' => ['method' => 'asset', 'arity' => 1, 'context' => true],
    ];

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];

    private int $position = 0;

    private string $template = '';

    private int $line = 1;

    public function compile(string $expression, string $template = '(expression)', int $line = 1): string
    {
        $this->template = $template;
        $this->line = $line;
        $this->position = 0;
        $this->tokens = $this->tokenize($expression);

        if ($this->tokens === []) {
            throw $this->error('Empty expression.');
        }

        $php = $this->parseTernary();

        if (!$this->isAtEnd()) {
            throw $this->error(sprintf(
                'Unexpected [%s] after a complete expression.',
                $this->current()['value'],
            ));
        }

        return $php;
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            $character = $source[$offset];

            if (ctype_space($character)) {
                ++$offset;

                continue;
            }

            if ($character === "'" || $character === '"') {
                [$value, $offset] = $this->readString($source, $offset, $character);
                $tokens[] = ['type' => 'string', 'value' => $value];

                continue;
            }

            if (ctype_digit($character)) {
                $matched = preg_match('/\G\d+(\.\d+)?/', $source, $matches, 0, $offset);

                if ($matched !== 1) {
                    throw $this->error(sprintf('Malformed number at offset %d.', $offset));
                }

                $offset += strlen($matches[0]);
                $tokens[] = ['type' => 'number', 'value' => $matches[0]];

                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $matches, 0, $offset) === 1) {
                $offset += strlen($matches[0]);

                $tokens[] = [
                    'type' => 'ident',
                    'value' => $matches[0],
                ];

                continue;
            }

            foreach (['===', '!==', '==', '!=', '<=', '>=', '&&', '||', '??', '=>'] as $operator) {
                if (substr($source, $offset, strlen($operator)) === $operator) {
                    $offset += strlen($operator);
                    $tokens[] = ['type' => 'op', 'value' => $operator];

                    continue 2;
                }
            }

            if ($character === '$') {
                $offset++;

                if (preg_match('/\\G[A-Za-z_][A-Za-z0-9_]*/', $source, $matches, 0, $offset) === 1) {
                    $offset += strlen($matches[0]);

                    $tokens[] = ['type' => 'ident', 'value' => '$'.$matches[0]];

                    continue;
                }

                throw $this->error(sprintf('Unexpected [%s].', $character));
            }

            $single = '()[],?:.+-*/%<>!';

            if (str_contains($single, $character)) {
                $type = str_contains('()[],.', $character) ? 'punct' : 'op';
                $tokens[] = ['type' => $type, 'value' => $character];
                ++$offset;

                continue;
            }

            throw $this->error($character === '$'
                ? 'Templates cannot contain PHP variables; read data by name instead, e.g. `post.title`.'
                : sprintf(
                    'Unexpected character [%s] in expression; templates cannot contain PHP syntax.',
                    $character,
                ));
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: int} literal value and the offset after the closing quote
     */
    private function readString(string $source, int $offset, string $quote): array
    {
        $length = strlen($source);
        $value = '';
        $index = $offset + 1;

        while ($index < $length) {
            $character = $source[$index];

            if ($character === '\\') {
                if ($index + 1 >= $length) {
                    break;
                }

                $escaped = $source[$index + 1];

                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '\\' => '\\',
                    "'" => "'",
                    '"' => '"',
                    default => '\\'.$escaped,
                };

                $index += 2;

                continue;
            }

            if ($character === $quote) {
                return [$value, $index + 1];
            }

            $value .= $character;
            ++$index;
        }

        throw $this->error('Unterminated string literal in expression.');
    }

    private function parseTernary(): string
    {
        $condition = $this->parseCoalesce();

        if (!$this->matchOperator('?')) {
            return $condition;
        }

        if ($this->matchOperator(':')) {
            return sprintf('(%s ?: %s)', $condition, $this->parseTernary());
        }

        $whenTrue = $this->parseTernary();
        $this->expectOperator(':');
        $whenFalse = $this->parseTernary();

        return sprintf('(%s ? %s : %s)', $condition, $whenTrue, $whenFalse);
    }

    private function parseCoalesce(): string
    {
        $left = $this->parseOr();

        while ($this->matchOperator('??')) {
            $left = sprintf('(%s ?? %s)', $left, $this->parseOr());
        }

        return $left;
    }

    private function parseOr(): string
    {
        $left = $this->parseAnd();

        while (true) {
            if ($this->matchOperator('||') || $this->matchKeyword('or')) {
                $left = sprintf('(%s || %s)', $left, $this->parseAnd());

                continue;
            }

            return $left;
        }
    }

    private function parseAnd(): string
    {
        $left = $this->parseNot();

        while (true) {
            if ($this->matchOperator('&&') || $this->matchKeyword('and')) {
                $left = sprintf('(%s && %s)', $left, $this->parseNot());

                continue;
            }

            return $left;
        }
    }

    private function parseNot(): string
    {
        if ($this->matchKeyword('not')) {
            return sprintf('!%s', $this->parseNot());
        }

        return $this->parseEquality();
    }

    private function parseEquality(): string
    {
        $left = $this->parseRelational();

        while (true) {
            foreach (['===', '!==', '==', '!='] as $operator) {
                if ($this->matchOperator($operator)) {
                    $left = sprintf('(%s %s %s)', $left, $operator, $this->parseRelational());

                    continue 2;
                }
            }

            if ($this->matchKeyword('in')) {
                $left = sprintf('%s::contains(%s, %s)', ViewHelpers::class, $left, $this->parseAdditive());

                continue;
            }

            if ($this->currentIsKeyword('not') && $this->peekIsKeyword('in')) {
                $this->position += 2;
                $left = sprintf('!%s::contains(%s, %s)', ViewHelpers::class, $left, $this->parseAdditive());

                continue;
            }

            return $left;
        }
    }

    private function parseRelational(): string
    {
        $left = $this->parseAdditive();

        while (true) {
            foreach (['<=', '>=', '<', '>'] as $operator) {
                if ($this->matchOperator($operator)) {
                    $left = sprintf('(%s %s %s)', $left, $operator, $this->parseAdditive());

                    continue 2;
                }
            }

            if ($this->matchKeyword('in')) {
                $left = sprintf('%s::contains(%s, %s)', ViewHelpers::class, $left, $this->parseAdditive());

                continue;
            }

            if ($this->currentIsKeyword('not') && $this->peekIsKeyword('in')) {
                $this->position += 2;
                $left = sprintf('!%s::contains(%s, %s)', ViewHelpers::class, $left, $this->parseAdditive());

                continue;
            }

            return $left;
        }
    }

    private function parseAdditive(): string
    {
        $left = $this->parseMultiplicative();

        while (true) {
            foreach (['+', '-'] as $operator) {
                if ($this->matchOperator($operator)) {
                    $left = sprintf('(%s %s %s)', $left, $operator, $this->parseMultiplicative());

                    continue 2;
                }
            }

            return $left;
        }
    }

    private function parseMultiplicative(): string
    {
        $left = $this->parseUnary();

        while (true) {
            foreach (['*', '/', '%'] as $operator) {
                if ($this->matchOperator($operator)) {
                    $left = sprintf('(%s %s %s)', $left, $operator, $this->parseUnary());

                    continue 2;
                }
            }

            return $left;
        }
    }

    private function parseUnary(): string
    {
        if ($this->matchOperator('!')) {
            return sprintf('!%s', $this->parseUnary());
        }

        if ($this->matchOperator('-')) {
            return sprintf('-%s', $this->parseUnary());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): string
    {
        if ($this->isAtEnd()) {
            throw $this->error('Unexpected end of expression.');
        }

        $token = $this->current();

        if ($this->matchPunct('(')) {
            $inner = $this->parseTernary();
            $this->expectPunct(')');

            return '('.$inner.')';
        }

        // Array literal: [1, 2, 'x'] or ['name' => 'Sahil']. Keys and values
        // are ordinary expressions; nesting is allowed.
        if ($this->matchPunct('[')) {
            $items = [];

            while (!$this->currentIsPunct(']')) {
                $item = $this->parseTernary();

                if ($this->matchOperator('=>')) {
                    $item .= ' => '.$this->parseTernary();
                }

                $items[] = $item;

                // A trailing comma is allowed; the loop condition then sees
                // the closing bracket.
                if (!$this->matchPunct(',')) {
                    break;
                }
            }

            $this->expectPunct(']');

            return '['.implode(', ', $items).']';
        }

        if ($token['type'] === 'number') {
            ++$this->position;

            return $token['value'];
        }

        if ($token['type'] === 'string') {
            ++$this->position;

            return var_export($token['value'], true);
        }

        if ($token['type'] === 'ident') {
            $value = $token['value'];

            // Literal keywords.
            if ($value === 'true' || $value === 'false' || $value === 'null') {
                ++$this->position;

                return $value;
            }

            // If the identifier is immediately followed by '(' it is a function
            // call; otherwise a plain identifier is a dotted data path. Both
            // helpers consume the identifier themselves.
            $next = $this->tokens[$this->position + 1] ?? null;

            if ($value[0] !== '$'
                && $next !== null
                && $next['type'] === 'punct'
                && $next['value'] === '('
            ) {
                return $this->parseCall();
            }

            // A data path is a literal key, not a PHP constant: `user.name`
            // becomes `$__ctx->get('user.name')`.
            return sprintf('$__ctx->get(%s)', var_export($this->parseIdentifier(), true));
        }

        throw $this->error(sprintf('Unexpected [%s].', $token['value']));
    }

    private function parseIdentifier(): string
    {
        $token = $this->current();

        if ($token['type'] !== 'ident' && $token['type'] !== 'number') {
            throw $this->error(sprintf('Expected an identifier or number, got [%s].', $token['value']));
        }

        $value = $token['value'];
        ++$this->position;

        if ($value[0] === '$') {
            // A variable is a single name; dotted paths are reserved for data.
            if (!$this->isAtEnd() && $this->currentIsPunct('.')) {
                throw $this->error(sprintf('Variables cannot contain a dot path; got [%s].', $value));
            }

            return $value;
        }

        // A plain identifier may be a dotted data path (user.name, items.0.name).
        while (!$this->isAtEnd() && $this->currentIsPunct('.')) {
            $this->position++;
            $this->expectIdentOrNumber();
            $value .= '.'.$this->current()['value'];
            $this->position++;
        }

        return $value;
    }

    private function expectIdentOrNumber(): void
    {
        $token = $this->current();

        if ($token['type'] !== 'ident' && $token['type'] !== 'number') {
            throw $this->error(sprintf('Expected an identifier or number, got [%s].', $token['value']));
        }
    }

    private function parseCall(): string
    {
        $token = $this->current();

        if ($token['type'] !== 'ident') {
            throw $this->error(sprintf('Expected a function name, got [%s].', $token['value']));
        }

        $name = $token['value'];
        $this->position++;

        $lower = strtolower($name);

        if (!isset(self::FUNCTIONS[$lower])) {
            throw $this->error(sprintf(
                'Unknown function [%s]. Available: %s. Compute other values in the controller instead.',
                $name,
                implode(', ', array_keys(self::FUNCTIONS)),
            ));
        }

        $this->expectPunct('(');

        $arguments = [];

        if (!$this->currentIsPunct(')')) {
            do {
                $arguments[] = $this->parseTernary();
            } while ($this->matchPunct(','));
        }

        $this->expectPunct(')');

        $definition = self::FUNCTIONS[$lower];
        $expected = $definition['arity'];

        if (count($arguments) !== $expected) {
            throw $this->error(sprintf(
                '%s() takes exactly %d argument%s, %d given.',
                $name,
                $expected,
                $expected === 1 ? '' : 's',
                count($arguments),
            ));
        }

        $arguments = implode(', ', $arguments);

        return ($definition['context'] ?? false)
            ? sprintf('$__ctx->%s(%s)', $definition['method'], $arguments)
            : sprintf('\\%s::%s(%s)', ViewHelpers::class, $definition['method'], $arguments);
    }

    private function isAtEnd(): bool
    {
        return $this->position >= count($this->tokens);
    }

    /**
     * @return array{type: string, value: string}
     */
    private function current(): array
    {
        return $this->tokens[$this->position] ?? ['type' => 'eof', 'value' => '(end of expression)'];
    }

    private function currentIsOperator(string $value): bool
    {
        $token = $this->current();

        return $token['type'] === 'op' && $token['value'] === $value;
    }

    private function currentIsPunct(string $value): bool
    {
        $token = $this->current();

        return $token['type'] === 'punct' && $token['value'] === $value;
    }

    private function matchOperator(string $value): bool
    {
        if ($this->currentIsOperator($value)) {
            ++$this->position;

            return true;
        }

        return false;
    }

    private function matchPunct(string $value): bool
    {
        if ($this->currentIsPunct($value)) {
            ++$this->position;

            return true;
        }

        return false;
    }

    private function currentIsKeyword(string $keyword): bool
    {
        $token = $this->current();

        return $token['type'] === 'ident' && strtolower($token['value']) === $keyword;
    }

    private function peekIsKeyword(string $keyword): bool
    {
        $token = $this->tokens[$this->position + 1] ?? null;

        return $token !== null && $token['type'] === 'ident' && strtolower($token['value']) === $keyword;
    }

    private function matchKeyword(string $keyword): bool
    {
        if ($this->currentIsKeyword($keyword)) {
            ++$this->position;

            return true;
        }

        return false;
    }

    private function expectOperator(string $value): void
    {
        if (!$this->matchOperator($value)) {
            throw $this->error(sprintf('Expected [%s].', $value));
        }
    }

    private function expectPunct(string $value): void
    {
        if (!$this->matchPunct($value)) {
            throw $this->error(sprintf('Expected [%s].', $value));
        }
    }

    private function error(string $reason): ViewSyntaxException
    {
        return new ViewSyntaxException($this->template, $this->line, $reason);
    }
}
