<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\GlobalNamespaceResolvedReference;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGenericTypeParameter;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StubbeDev\LaravelStoli\Items\DataType;
use Throwable;

use function Illuminate\Filesystem\join_paths;

/**
 * The TypeScript types the typescript-transformer generates for PHP classes, read from
 * the transformer itself: it is asked which classes it transforms and how its writer
 * references each of them, so nothing is parsed out of the file it writes.
 *
 * A type written by the GlobalNamespaceWriter is an ambient global referenced by its
 * dotted path; a type written to a module is imported by name from that file.
 *
 * Inside typescript:transform, the collection being written is handed over with use();
 * on its own, the transformer is asked to resolve its collection.
 */
final class TransformedTypes
{
    /**
     * Class => its type, and how many type arguments it requires.
     *
     * @var array<string, array{type: DataType, arity: int}>|null
     */
    private ?array $types = null;

    public function __construct(private readonly ?TypeScriptTransformerConfig $config = null) {}

    /**
     * Read the types from $collection, the one the transformer is writing.
     */
    public function use(TransformedCollection $collection): void
    {
        $this->types = $this->read($collection);
    }

    /**
     * The type of a class, applied to $arguments. The type arguments it requires and is
     * not given are `unknown`, so the reference always compiles.
     *
     * @param  list<DataType>  $arguments
     */
    public function find(string $class, array $arguments = []): ?DataType
    {
        $found = $this->types()[ltrim($class, '\\')] ?? null;

        if ($found === null) {
            return null;
        }

        $missing = max(0, $found['arity'] - count($arguments));

        return $found['type']->withArguments(...$arguments, ...array_fill(0, $missing, new DataType('unknown')));
    }

    /**
     * @return array<string, array{type: DataType, arity: int}>
     *
     * @throws StoliException when the transformer cannot resolve its types
     */
    private function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        if ($this->config === null) {
            return $this->types = [];
        }

        try {
            [$collection] = TypeScriptTransformer::create($this->config)->resolveState();
            $collection->ensureEachTransformedHasAWriter($this->config->typesWriter);
        } catch (Throwable $error) {
            throw StoliException::cantResolveTypes($error);
        }

        return $this->types = $this->read($collection);
    }

    /**
     * @return array<string, array{type: DataType, arity: int}>
     */
    private function read(TransformedCollection $collection): array
    {
        $types = [];

        foreach ($collection as $transformed) {
            $reference = $transformed->getReference();
            $name = $transformed->getName();

            if (! $reference instanceof ClassStringReference || $name === null || ! $transformed->isExported()) {
                continue;
            }

            $types[$reference->classString] = [
                'type' => $this->reference($transformed),
                'arity' => self::arity($transformed),
            ];
        }

        return $types;
    }

    private function reference(Transformed $transformed): DataType
    {
        $resolved = $transformed->getWriter()->resolveReference($transformed);

        return $resolved instanceof GlobalNamespaceResolvedReference
            ? new DataType($resolved->qualifiedName)
            : DataType::imported($resolved->name, join_paths((string) $this->config?->outputDirectory, $resolved->path));
    }

    /**
     * The number of type parameters without a default.
     */
    private static function arity(Transformed $transformed): int
    {
        $node = $transformed->getNode();

        if (! $node instanceof TypeScriptAlias || ! $node->identifier instanceof TypeScriptGeneric) {
            return 0;
        }

        return count(array_filter(
            $node->identifier->genericTypes,
            static fn (mixed $parameter): bool => ! $parameter instanceof TypeScriptGenericTypeParameter || $parameter->default === null,
        ));
    }
}
