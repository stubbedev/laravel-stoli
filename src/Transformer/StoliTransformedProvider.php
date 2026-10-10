<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Transformer;

use Illuminate\Contracts\Container\Container;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\WatchEventResult;
use Spatie\TypeScriptTransformer\Events\WatchEvent;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\WatchingTransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;

/**
 * Gives Stoli a place in the transformed collection: a single item with nothing to write
 * of its own, whose writer generates Stoli's files. See StoliWriter.
 *
 * In watch mode, a PHP file changing under the routes or the application makes the
 * transformer start over: routes, controllers and Data classes are read by reflection,
 * which a running process cannot do again for a class it has loaded.
 */
final readonly class StoliTransformedProvider implements TransformedProvider, WatchingTransformedProvider
{
    /**
     * @param  list<string>  $directories  the directories whose PHP files the routes are read from
     */
    public function __construct(
        private Container $container,
        private array $directories = [],
    ) {}

    public function provide(): array
    {
        return [new Transformed(
            new TypeScriptRaw(''),
            new CustomReference('stoli', 'generated'),
            [],
            export: false,
            writer: new StoliWriter($this->container),
        )];
    }

    public function directoriesToWatch(): array
    {
        return array_values(array_filter($this->directories, is_dir(...)));
    }

    public function handleWatchEvent(WatchEvent $watchEvent, TransformedCollection $transformedCollection): ?WatchEventResult
    {
        $path = property_exists($watchEvent, 'path') && is_string($watchEvent->path) ? $watchEvent->path : '';

        return str_ends_with($path, '.php') ? WatchEventResult::completeRefresh() : null;
    }
}
