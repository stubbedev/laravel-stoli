<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

/**
 * Maps Laravel route-parameter regex constraints to their TypeScript equivalents.
 *
 * Precedence:
 *  1. Exact match against Laravel's named-constraint helper patterns (whereNumber, whereAlpha, whereUuid, whereUlid).
 *  2. Simple literal alternation produced by whereIn  →  union of string literals ('a' | 'b').
 *  3. Regex that only matches digit-like strings  →  number.
 *  4. Everything else  →  string.
 */
final class ConstraintTypeMapper
{
    /**
     * The exact regex strings produced by Laravel's named constraint helpers.
     * Mapping: regex pattern => TypeScript type.
     *
     * @var array<string, string>
     */
    private const EXACT = [
        // whereNumber()
        '[0-9]+'                                                                         => 'number',
        // whereAlpha()
        '[a-zA-Z]+'                                                                      => 'string',
        // whereAlphaNumeric()
        '[a-zA-Z0-9]+'                                                                   => 'string',
        // whereUuid()
        '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}'       => 'string',
        // whereUlid()
        '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}'                                      => 'string',
    ];

    public function map(string $regex): string
    {
        return self::EXACT[$regex] ?? $this->infer($regex);
    }

    /**
     * Infer a TypeScript type for a regex that did not match any known exact pattern.
     */
    private function infer(string $regex): string
    {
        // Simple literal alternation (e.g. "users|groups|all", produced by whereIn) → union of string literals.
        if (preg_match('/^[a-zA-Z0-9_-]+(\|[a-zA-Z0-9_-]+)*$/', $regex)) {
            return implode(' | ', array_map(
                fn(string $v) => "'{$v}'",
                explode('|', $regex),
            ));
        }

        // Generic numeric-only pattern (e.g. "\d+", "[1-9][0-9]*") → number.
        // We test that the anchored pattern matches digits but not letters.
        // The error-suppression (@) guards against malformed regex strings.
        $anchored = "/^(?:{$regex})$/";
        if (
            @preg_match($anchored, '') !== false
            && preg_match($anchored, '123') === 1
            && preg_match($anchored, 'abc') === 0
        ) {
            return 'number';
        }

        return 'string';
    }
}
