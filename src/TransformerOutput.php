<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Container\Container;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Throwable;

/**
 * What Stoli reads from the spatie/typescript-transformer setup: the directory it
 * writes to, the formatter it runs, the directories it discovers classes in, and the
 * config itself, which TransformedTypes asks for the types.
 *
 * Everything is null or empty when the transformer is not bound in the container,
 * so the exporters that depend on it write nothing instead of failing.
 */
final readonly class TransformerOutput
{
    /**
     * @param  list<string>  $directoriesToWatch
     */
    public function __construct(
        public ?string $directory = null,
        public ?Formatter $formatter = null,
        public array $directoriesToWatch = [],
        public ?TypeScriptTransformerConfig $config = null,
    ) {}

    public static function fromConfig(TypeScriptTransformerConfig $config): self
    {
        return new self(
            directory: rtrim($config->outputDirectory, '/\\'),
            formatter: $config->formatter,
            directoriesToWatch: array_values(array_unique(array_filter($config->directoriesToWatch, is_string(...)))),
            config: $config,
        );
    }

    public static function fromContainer(Container $container): self
    {
        try {
            $config = $container->make(TypeScriptTransformerConfig::class);
        } catch (Throwable) {
            return new self;
        }

        return self::fromConfig($config);
    }
}
