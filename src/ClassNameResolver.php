<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use PhpToken;
use ReflectionClass;

/**
 * Resolves a class name as written in a PHPDoc tag the way PHP would resolve it in the
 * declaring file: through its `use` imports, grouped ones included, and otherwise
 * relative to its namespace.
 */
final class ClassNameResolver
{
    /**
     * The class imports per file: lowercased alias => fully-qualified name.
     *
     * @var array<string, array<string, string>>
     */
    private array $imports = [];

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $context  the class the tag was written in
     * @return class-string|null
     */
    public function resolve(string $name, ReflectionClass $context): ?string
    {
        if (str_starts_with($name, '\\')) {
            return self::existing(ltrim($name, '\\'));
        }

        $file = $context->getFileName();
        $imports = $file === false ? [] : $this->imports($file);

        // The first segment of a name is what an import can alias: Data\UserData
        // resolves through an import of the Data namespace.
        $first = strtolower(explode('\\', $name, 2)[0]);

        if (isset($imports[$first])) {
            return self::existing($imports[$first].substr($name, strlen($first)));
        }

        $namespace = $context->getNamespaceName();

        return self::existing($namespace === '' ? $name : "{$namespace}\\{$name}");
    }

    /**
     * @return class-string|null
     */
    private static function existing(string $class): ?string
    {
        return class_exists($class) || interface_exists($class) ? $class : null;
    }

    /**
     * @return array<string, string>
     */
    private function imports(string $file): array
    {
        if (! isset($this->imports[$file])) {
            $source = is_file($file) ? @file_get_contents($file) : false;
            $this->imports[$file] = $source === false ? [] : self::parse($source);
        }

        return $this->imports[$file];
    }

    /**
     * Read the class imports of a file: `use A\B;`, `use A\B as C;`, `use A\B, C\D;`
     * and `use A\{B, C as D};`. Function and constant imports are skipped, and so is
     * everything after the first class-like declaration, where `use` imports traits.
     *
     * @return array<string, string>
     */
    private static function parse(string $source): array
    {
        $imports = [];
        $statement = null;

        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                break;
            }

            if ($statement === null) {
                $statement = $token->is(T_USE) ? '' : null;

                continue;
            }

            if ($token->text !== ';') {
                $statement .= $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]) ? ' ' : $token->text;

                continue;
            }

            foreach (self::parseStatement($statement) as $alias => $class) {
                $imports[$alias] = $class;
            }

            $statement = null;
        }

        return $imports;
    }

    /**
     * @return array<string, string>
     */
    private static function parseStatement(string $statement): array
    {
        $statement = trim((string) preg_replace('/\s+/', ' ', $statement));

        if (preg_match('/^(function|const)\b/i', $statement) === 1) {
            return [];
        }

        $prefix = '';

        if (preg_match('/^([^{]*)\{(.*)\}$/s', $statement, $group) === 1) {
            $prefix = trim(trim($group[1]), '\\').'\\';
            $statement = $group[2];
        }

        $imports = [];

        foreach (explode(',', $statement) as $clause) {
            if (preg_match('/^\s*\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*$/i', $clause, $match) !== 1) {
                continue;
            }

            $class = $prefix.$match[1];
            $alias = $match[2] ?? '';
            $alias = $alias !== '' ? $alias : class_basename($class);
            $imports[strtolower($alias)] = $class;
        }

        return $imports;
    }
}
