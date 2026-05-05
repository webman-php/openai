<?php

declare(strict_types=1);

namespace Webman\Openai\Tests\Support;

use Webman\Openai\Chat;
use Workerman\Http\Client;

/**
 * Captures the last {@see Client} created by HTTP requests for tests that need
 * reflection-based access to the connection pool.
 */
class TestableChat extends Chat
{
    public ?Client $capturedHttpClient = null;

    protected function createHttpClient(int $timeout): Client
    {
        $this->capturedHttpClient = new Client(['timeout' => $timeout]);
        return $this->capturedHttpClient;
    }
}
