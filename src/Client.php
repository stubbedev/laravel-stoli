<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

/**
 * The HTTP client a generated router makes its requests with. Both routers take the
 * same calls and respond in the same shape, so switching is a config change.
 */
enum Client: string
{
    use RuntimeModule;

    case Axios = 'axios';
    case Fetch = 'fetch';
}
