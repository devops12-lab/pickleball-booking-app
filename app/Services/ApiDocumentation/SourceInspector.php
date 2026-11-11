<?php

namespace App\Services\ApiDocumentation;

use PhpToken;
use ReflectionClass;
use ReflectionFunctionAbstract;

/**
 * Reads PHP source code statically (never executes it) so the documentation
 * can describe array literals, method calls and imported class names.
 *
 * Parsed array nodes have the shape:
 *   ['type' => 'array', 'entries' => list<array{key: ?string, value: array}>]
 * Parsed expression nodes have the shape:
 *   ['type' => 'expr', 'text' => string, 'tokens' => list<PhpToken>]
 */
class SourceInspector
{
    /**
     * @var array<string, array<string, string>>
     */
    protected array $importCache = [];

    /**
     * Get the source code of a function or method body.
     */
    public function source(ReflectionFunctionAbstract $function): ?string
    {
        $file = $function->getFileName();

        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);

        if ($lines === false) {
            return null;
        }

        $start = $function->getStartLine() - 1;
        $length = $function->getEndLine() - $start;

        return implode('', array_slice($lines, $start, $length));
    }

    /**
     * Get the summary and description from a docblock.
     *
     * @return array{summary: ?string, description: ?string}
     */
    public function docblock(ReflectionFunctionAbstract $function): array
    {
        $comment = $function->getDocComment();

        if ($comment === false) {
            return ['summary' => null, 'description' => null];
        }

        $lines = [];

        foreach (preg_split('/\R/', $comment) as $line) {
            $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)\s?#', '', $line));

            if (str_starts_with($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        $paragraphs = array_values(array_filter(
            array_map('trim', explode("\n\n", trim(implode("\n", $lines)))),
            fn (string $paragraph): bool => $paragraph !== '',
        ));

        return [
            'summary' => isset($paragraphs[0]) ? rtrim(preg_replace('/\s+/', ' ', $paragraphs[0]), '.') : null,
            'description' => count($paragraphs) > 1 ? implode("\n\n", array_slice($paragraphs, 1)) : null,
        ];
    }

    /**
     * Tokenize a source fragment.
     *
     * @return list<PhpToken>
     */
    public function tokenize(string $source): array
    {
        return array_values(array_filter(
            PhpToken::tokenize('<?php '.$source),
            fn (PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    /**
     * Find the index of the last call to the given method name.
     *
     * @param  list<PhpToken>  $tokens
     */
    public function lastCallIndex(array $tokens, string $method): ?int
    {
        $found = null;

        foreach ($tokens as $index => $token) {
            if ($token->text === $method && ($tokens[$index + 1]->text ?? null) === '(') {
                $found = $index + 1;
            }
        }

        return $found;
    }

    /**
     * Find every call to the given method name, returning the "(" indexes.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<int>
     */
    public function callIndexes(array $tokens, string $method): array
    {
        $indexes = [];

        foreach ($tokens as $index => $token) {
            if ($token->text === $method && ($tokens[$index + 1]->text ?? null) === '(') {
                $indexes[] = $index + 1;
            }
        }

        return $indexes;
    }

    /**
     * Find the index of the bracket that closes the one at $start.
     *
     * @param  list<PhpToken>  $tokens
     */
    public function matchingIndex(array $tokens, int $start): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $depth += $this->depthChange($tokens[$index]);

            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Split the arguments of a call whose "(" is at $openIndex.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{positional: list<list<PhpToken>>, named: array<string, list<PhpToken>>}
     */
    public function callArguments(array $tokens, int $openIndex): array
    {
        $closeIndex = $this->matchingIndex($tokens, $openIndex);
        $arguments = ['positional' => [], 'named' => []];

        if ($closeIndex === null) {
            return $arguments;
        }

        $inner = array_slice($tokens, $openIndex + 1, $closeIndex - $openIndex - 1);

        foreach ($this->splitTopLevel($inner, ',') as $argument) {
            if ($argument === []) {
                continue;
            }

            if (count($argument) > 2 && $argument[0]->is(T_STRING) && $argument[1]->text === ':') {
                $arguments['named'][$argument[0]->text] = array_slice($argument, 2);

                continue;
            }

            $arguments['positional'][] = $argument;
        }

        return $arguments;
    }

    /**
     * Find the array literal returned at the top level of a function body
     * ("return [ ... ]"), ignoring returns inside nested closures.
     */
    public function returnedArray(ReflectionFunctionAbstract $function): ?array
    {
        $source = $this->source($function);

        if ($source === null) {
            return null;
        }

        $tokens = $this->tokenize($source);
        $result = null;
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($depth === 1 && $token->is(T_RETURN) && ($tokens[$index + 1]->text ?? null) === '[') {
                $result = $this->arrayAt($tokens, $index + 1);
            }

            $depth += $this->depthChange($token);
        }

        return $result;
    }

    /**
     * Find the right-hand side of the last "$variable = ..." assignment.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>|null
     */
    public function assignment(array $tokens, string $variable): ?array
    {
        $found = null;
        $count = count($tokens);

        foreach ($tokens as $index => $token) {
            if ($token->is(T_VARIABLE) && $token->text === $variable && ($tokens[$index + 1]->text ?? null) === '=') {
                $expression = [];
                $depth = 0;

                for ($cursor = $index + 2; $cursor < $count; $cursor++) {
                    $depth += $this->depthChange($tokens[$cursor]);

                    if ($depth === 0 && $tokens[$cursor]->text === ';') {
                        break;
                    }

                    $expression[] = $tokens[$cursor];
                }

                $found = $expression;
            }
        }

        return $found;
    }

    /**
     * Parse the array literal whose "[" is at $openIndex.
     *
     * @param  list<PhpToken>  $tokens
     */
    public function arrayAt(array $tokens, int $openIndex): ?array
    {
        $closeIndex = $this->matchingIndex($tokens, $openIndex);

        if ($closeIndex === null) {
            return null;
        }

        return $this->parseArray(array_slice($tokens, $openIndex + 1, $closeIndex - $openIndex - 1));
    }

    /**
     * Describe an expression: an array literal becomes an array node.
     *
     * @param  list<PhpToken>  $tokens
     */
    public function node(array $tokens): array
    {
        $tokens = array_values($tokens);
        $last = count($tokens) - 1;

        if ($last >= 1 && $tokens[0]->text === '[' && $this->matchingIndex($tokens, 0) === $last) {
            return $this->parseArray(array_slice($tokens, 1, $last - 1));
        }

        return [
            'type' => 'expr',
            'text' => implode(' ', array_map(fn (PhpToken $token): string => $token->text, $tokens)),
            'tokens' => $tokens,
        ];
    }

    /**
     * Get the value of a single string literal token list, if it is one.
     *
     * @param  list<PhpToken>  $tokens
     */
    public function stringLiteral(array $tokens): ?string
    {
        if (count($tokens) !== 1) {
            return null;
        }

        return $this->literalText($tokens[0]);
    }

    /**
     * Get the literal text of a quoted string token.
     */
    public function literalText(PhpToken $token): ?string
    {
        if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $quote = $token->text[0];
        $inner = substr($token->text, 1, -1);

        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : stripcslashes($inner);
    }

    /**
     * Get a readable version of a (possibly interpolated) string expression.
     *
     * @param  list<PhpToken>  $tokens
     */
    public function readableString(array $tokens): ?string
    {
        $literal = $this->stringLiteral($tokens);

        if ($literal !== null) {
            return $literal;
        }

        if (($tokens[0]->text ?? null) !== '"') {
            return null;
        }

        $text = '';

        foreach (array_slice($tokens, 1, -1) as $token) {
            $text .= $token->is(T_ENCAPSED_AND_WHITESPACE) ? stripcslashes($token->text) : '';

            if ($token->is(T_VARIABLE)) {
                $text .= '{'.ltrim($token->text, '$').'}';
            }
        }

        return $text;
    }

    /**
     * Resolve a short class name against the imports of the given class.
     */
    public function resolveClass(string $name, ReflectionClass $context): ?string
    {
        $name = ltrim($name, '\\');

        if (str_contains($name, '\\') && class_exists($name)) {
            return $name;
        }

        $imports = $this->imports($context);
        $first = strtok($name, '\\');

        if (isset($imports[$first])) {
            $candidate = $imports[$first].substr($name, strlen($first));

            return class_exists($candidate) ? $candidate : null;
        }

        $candidate = $context->getNamespaceName().'\\'.$name;

        return class_exists($candidate) ? $candidate : null;
    }

    /**
     * Parse the inner tokens of an array literal.
     *
     * @param  list<PhpToken>  $tokens
     */
    protected function parseArray(array $tokens): array
    {
        $entries = [];

        foreach ($this->splitTopLevel($tokens, ',') as $element) {
            if ($element === []) {
                continue;
            }

            $parts = $this->splitTopLevel($element, '=>');

            if (count($parts) >= 2) {
                $keyTokens = $parts[0];
                $key = $this->stringLiteral($keyTokens)
                    ?? implode('', array_map(fn (PhpToken $token): string => $token->text, $keyTokens));

                $entries[] = ['key' => $key, 'value' => $this->node(array_merge(...array_slice($parts, 1)))];

                continue;
            }

            $entries[] = ['key' => null, 'value' => $this->node($element)];
        }

        return ['type' => 'array', 'entries' => $entries];
    }

    /**
     * Split tokens on a separator that sits outside any brackets.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<list<PhpToken>>
     */
    protected function splitTopLevel(array $tokens, string $separator): array
    {
        $parts = [[]];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($depth === 0 && $token->text === $separator) {
                $parts[] = [];

                continue;
            }

            $depth += $this->depthChange($token);
            $parts[count($parts) - 1][] = $token;
        }

        return $parts;
    }

    /**
     * Get how a token changes bracket depth.
     */
    protected function depthChange(PhpToken $token): int
    {
        if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            return 1;
        }

        return in_array($token->text, [')', ']', '}'], true) ? -1 : 0;
    }

    /**
     * Get the "use" imports declared in the file of a class.
     *
     * @return array<string, string>
     */
    protected function imports(ReflectionClass $context): array
    {
        $file = $context->getFileName();

        if ($file === false) {
            return [];
        }

        if (isset($this->importCache[$file])) {
            return $this->importCache[$file];
        }

        $imports = [];
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', (string) file_get_contents($file), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $alias = $match[2] ?? '';
            $imports[$alias !== '' ? $alias : class_basename($match[1])] = $match[1];
        }

        return $this->importCache[$file] = $imports;
    }
}
