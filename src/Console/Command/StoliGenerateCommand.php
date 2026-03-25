<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Console\Command;

use Illuminate\Console\Command;
use StubbeDev\LaravelStoli\Publisher;
use StubbeDev\LaravelStoli\StoliException;

class StoliGenerateCommand extends Command
{
    protected $signature = 'stoli:generate';

    protected $description = 'Publish the route files for the Laravel Stoli library';

    public function handle(Publisher $publisher): int
    {
        try {
            $publisher->publish();
            $this->info('Routes published');
        } catch (StoliException $exception) {
            $this->error('Sorry we have an exception: '.$exception->getMessage());

            return 1;
        }

        return 0;
    }
}
