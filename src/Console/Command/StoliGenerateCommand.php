<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Console\Command;

use Illuminate\Console\Command;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Publisher;

class StoliGenerateCommand extends Command
{
    protected $signature = 'stoli:generate
        {--F|force    : Force re-publish the runtime library files}
        {--type-check : Run tsc --noEmit after generation to validate the generated TypeScript}';

    protected $description = 'Publish the route files for the Laravel Stoli library';

    public function handle(Publisher $publisher): int
    {
        try {
            $publisher->publish(
                overrideLibrary: (bool) $this->option('force'),
            );
            $this->info('Routes published');
        } catch (StoliException $exception) {
            $this->error('Sorry we have an exception: ' . $exception->getMessage());
            return 1;
        }

        if ($this->option('type-check')) {
            return $this->runTypeCheck();
        }

        return 0;
    }

    private function runTypeCheck(): int
    {
        // Locate tsc in the project's node_modules first, then fall back to global.
        $tsc = base_path('node_modules/.bin/tsc');

        if (!file_exists($tsc)) {
            $tsc = trim((string) shell_exec('which tsc 2>/dev/null'));
        }

        if ($tsc === '' || !file_exists($tsc)) {
            $this->warn('--type-check: TypeScript compiler (tsc) not found. Install it with: npm install --save-dev typescript');
            return 1;
        }

        $exitCode = 0;
        passthru(escapeshellarg($tsc) . ' --noEmit 2>&1', $exitCode);

        if ($exitCode !== 0) {
            $this->error('TypeScript type-check failed. See output above.');
            return 1;
        }

        $this->info('TypeScript type-check passed.');
        return 0;
    }
}
