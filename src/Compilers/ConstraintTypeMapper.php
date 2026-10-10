<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

/**
 * Maps Laravel route-parameter regex constraints to their TypeScript equivalents.
 *
 * Precedence:
 *  1. Exact match against Laravel's named-constraint helper patterns (whereNumber, whereAlpha, whereUuid, whereUlid).
 *  2. Simple literal alternation produced by whereIn  →  union of literals ('a' | 'b'; '1' | 1 for integers).
 *  3. Regex that only matches digit-like strings  →  number.
 *  4. Everything else  →  string.
 */
final class ConstraintTypeMapper
{
    /**
     * Without a constraint, a parameter takes anything that prints into a URL.
     */
    public const UNCONSTRAINED = 'string | number';

    /**
     * The exact regex strings produced by Laravel's named constraint helpers.
     * Mapping: regex pattern => TypeScript type.
     *
     * @var array<string, string>
     */
    private const EXACT = [
        // whereNumber()
        '[0-9]+' => 'number',
        // whereAlpha()
        '[a-zA-Z]+' => 'string',
        // whereAlphaNumeric()
        '[a-zA-Z0-9]+' => 'string',
        // whereUuid()
        '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}' => '`${string}-${string}-${string}-${string}-${string}`',
        // whereUlid()
        '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}' => 'string',
    ];

    /**
     * A value whereIn() joins into an alternation as it is.
     */
    private const LITERAL = '/^[\w-]+$/';

    /**
     * A value that is an integer, which a URL segment holding it may be given as.
     */
    private const INTEGER = '/^(0|[1-9][0-9]*)$/';

    /**
     * Samples a numeric constraint must match and refuse.
     */
    private const DIGITS = '123';

    private const LETTERS = 'abc';

    public function map(?string $regex): string
    {
        return $regex === null ? self::UNCONSTRAINED : self::EXACT[$regex] ?? $this->infer($regex);
    }

    /**
     * Infer a TypeScript type for a regex that did not match any known exact pattern.
     */
    private function infer(string $regex): string
    {
        // Simple literal alternation (e.g. "users|groups|all", produced by whereIn) → union of literals.
        // An integer value is taken as a number too, since a URL segment is text either way.
        $values = explode('|', $regex);

        if (array_filter($values, static fn (string $value): bool => preg_match(self::LITERAL, $value) !== 1) === []) {
            $literals = [];

            foreach ($values as $value) {
                $literals[] = TypeScript::string($value);

                if (preg_match(self::INTEGER, $value) === 1) {
                    $literals[] = $value;
                }
            }

            return TypeScript::union($literals);
        }

        // Generic numeric-only pattern (e.g. "\d+", "[1-9][0-9]*") → number.
        // We test that the anchored pattern matches digits but not letters.
        // The error-suppression (@) guards against malformed regex strings.
        $anchored = "/^(?:{$regex})$/";
        if (
            @preg_match($anchored, '') !== false
            && preg_match($anchored, self::DIGITS) === 1
            && preg_match($anchored, self::LETTERS) === 0
        ) {
            return 'number';
        }

        return 'string';
    }
}
