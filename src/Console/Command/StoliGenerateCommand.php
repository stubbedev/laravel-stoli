<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Console\Command;

use Illuminate\Console\Command;
use StubbeDev\LaravelStoli\Publisher;
use StubbeDev\LaravelStoli\StoliException;

final class StoliGenerateCommand extends Command
{
    protected $signature = 'stoli:generate';

    protected $description = 'Publish the route files for the Laravel Stoli library';

    public function handle(Publisher $publisher): int
    {
        try {
            $publisher->publish();
        } catch (StoliException $exception) {
            $this->error('Could not generate the Stoli files: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Routes published');

        return self::SUCCESS;
    }
}
