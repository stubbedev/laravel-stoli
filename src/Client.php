<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

/**
 * The HTTP client a generated router makes its requests with. Both routers take the
 * same calls and respond in the same shape, so switching is a config change.
 */
enum Client: string
{
    case Axios = 'axios';
    case Fetch = 'fetch';

    /**
     * The runtime module the router is built on, without its extension.
     */
    public function module(): string
    {
        return "stoli-{$this->value}";
    }
}
