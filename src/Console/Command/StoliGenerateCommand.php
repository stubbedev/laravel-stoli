<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Console\Command;

use Illuminate\Console\Command;
use StubbeDev\LaravelStoli\Publisher;
use StubbeDev\LaravelStoli\StoliException;

final class StoliGenerateCommand extends Command
{
    protected $signature = 'stoli:generate
        {--check : Write nothing; fail when the generated files are missing, changed or stale}';

    protected $description = 'Generate the typed route files, route service, router and constants';

    public function handle(Publisher $publisher): int
    {
        try {
            if ($this->option('check')) {
                return $this->check($publisher);
            }

            $publisher->publish();
        } catch (StoliException $exception) {
            $this->error('Could not generate the Stoli files: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Routes published');

        return self::SUCCESS;
    }

    private function check(Publisher $publisher): int
    {
        $stale = $publisher->stale();

        if ($stale === []) {
            $this->info('The generated files are up to date');

            return self::SUCCESS;
        }

        $this->error('The generated files are out of date; run `php artisan stoli:generate`:');

        foreach ($stale as $path) {
            $this->line("  {$path}");
        }

        return self::FAILURE;
    }
}
