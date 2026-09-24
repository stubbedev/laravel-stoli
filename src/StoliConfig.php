<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Support\Arr;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

use function app_path;

final readonly class StoliConfig
{
    /**
     * @param  array<mixed>  $config  the `stoli` config array
     */
    public function __construct(
        private array $config,
        private TransformerOutput $output = new TransformerOutput,
    ) {}

    public function splitModulesInFiles(): bool
    {
        return (bool) ($this->config['split'] ?? true);
    }

    public function resourcesPath(): string
    {
        return dirname(__DIR__).'/resources';
    }

    /**
     * The raw module definitions; ModulesProvider validates them.
     *
     * @return array<mixed>
     */
    public function modules(): array
    {
        $modules = $this->config['modules'] ?? [];

        return is_array($modules) ? $modules : [];
    }

    public function defaultSingleFileModuleName(): string
    {
        return $this->string('single.name') ?? 'api';
    }

    public function axiosRouter(): bool
    {
        return (bool) ($this->config['axios'] ?? false);
    }

    public function constants(): bool
    {
        return (bool) Arr::get($this->config, 'constants.enabled', true);
    }

    public function constantsFileName(): string
    {
        return $this->string('constants.name') ?? 'constants';
    }

    /**
     * Where the constants file is written; the typescript-transformer output
     * directory unless the config overrides it.
     */
    public function constantsPath(): ?string
    {
        return $this->string('constants.path') ?? $this->defaultOutputPath();
    }

    /**
     * The attributes a class must carry to have its constants exported.
     *
     * @return list<string>
     */
    public function constantsAttributes(): array
    {
        $attributes = Arr::get($this->config, 'constants.attributes');

        if (! is_array($attributes)) {
            return [TypeScriptConstants::class, TypeScript::class];
        }

        return array_values(array_filter($attributes, is_string(...)));
    }

    /**
     * The directories scanned for attributed classes. Falls back to the
     * directories the typescript-transformer itself discovers types in, so
     * constants are picked up wherever the enums already are.
     *
     * @return list<string>
     */
    public function constantsPaths(): array
    {
        $configured = Arr::get($this->config, 'constants.paths');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(
                Utils::absolutePath(...),
                array_filter($configured, is_string(...))
            ));
        }

        return $this->output->directoriesToWatch !== []
            ? $this->output->directoriesToWatch
            : [app_path()];
    }

    /**
     * The typescript-transformer output directory, or null when the transformer
     * has not been registered in the container.
     */
    public function defaultOutputPath(): ?string
    {
        return $this->output->directory;
    }

    /**
     * A non-empty string at the dotted $key, or null when it is missing or not one.
     */
    private function string(string $key): ?string
    {
        $value = Arr::get($this->config, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
