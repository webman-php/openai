# webman/openai

**English** | [简体中文](README.zh-CN.md)

Non-blocking OpenAI client for PHP with coroutine support and a built-in connection pool, designed for [Workerman](https://github.com/walkor/workerman) / [webman](https://github.com/walkor/webman).

## Installation

```bash
composer require webman/openai
```

Requires **PHP 8.1+** and a **Workerman 5.1+** runtime.

> **Enable coroutines in webman**  
> Set `webman.eventLoop` in `config/process.php` to `Workerman\Events\Fiber::class`.  
> If the `swoole` or `swow` extension is installed, you may use `Workerman\Events\Swoole::class` or `Workerman\Events\Swow::class` instead.

---

## Quick overview (Workerman)

### Non-streaming

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Webman\Openai\Chat;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Events\Fiber;
use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:8686');

$worker->eventLoop = Fiber::class;

$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $chat = new Chat([
        'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
        'api' => 'https://api.openai.com',
    ]);

    $result = $chat->completions([
        'model' => 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => 'hello']],
    ]);

    $connection->send(new Response(200, [
        'Content-Type' => 'application/json; charset=utf-8',
    ], json_encode($result, JSON_UNESCAPED_UNICODE)));
};

Worker::runAll();
```

### Streaming

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Webman\Openai\Chat;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Events\Fiber;
use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:8686');

$worker->eventLoop = Fiber::class;

$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $chat = new Chat([
        'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
        'api' => 'https://api.openai.com',
    ]);

    $chunks = $chat->completions([
        'model' => 'gpt-4o-mini',
        'stream' => true,
        'messages' => [['role' => 'user', 'content' => 'hello']],
    ]);

    $connection->send(new Response(200, [
        'Transfer-Encoding' => 'chunked',
        'Content-Type' => 'application/x-ndjson; charset=utf-8',
    ]));

    foreach ($chunks as $chunk) {
        $connection->send(new Chunk(json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n"));
    }
    $connection->close(new Chunk(''));
};

Worker::runAll();
```

---

## Chat: completions (Webman)

> **Note**  
> API usage is the same whether you run under Workerman or webman; the examples below use webman.

### Coroutines · non-streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;

class ChatController
{
    public function completions(Request $request)
    {
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $result = $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);
        return json($result);
    }
}
```

### Coroutines · streaming

Under coroutines, streaming returns a **generator**: send response headers to the client first, then emit each piece with `Chunk`, and finish with `close`.

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;
use Workerman\Protocols\Http\Chunk;

class ChatController
{
    public function completions(Request $request)
    {
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $chunks = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);

        $connection = $request->connection;
        $connection->send(response()->withHeaders([
            'Transfer-Encoding' => 'chunked',
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
        ]));

        foreach ($chunks as $chunk) {
            $connection->send(new Chunk(json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n"));
        }
        $connection->close(new Chunk(''));
        // Stream finished manually; no return needed
    }
}
```

### Async callbacks · streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class ChatController
{
    public function completions(Request $request)
    {
        $connection = $request->connection;
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $chat->completions(
            [
                'model' => 'gpt-4o-mini',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ],
            [
                'stream' => function (array $chunk) use ($connection) {
                    $connection->send(new Chunk(json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n"));
                },
                'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use ($connection) {
                    $connection->send(new Chunk(''));
                },
            ]
        );

        return response()->withHeaders([
            'Transfer-Encoding' => 'chunked',
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
        ]);
    }
}
```

### Async callbacks · non-streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class ChatController
{
    public function completions(Request $request)
    {
        $connection = $request->connection;
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $chat->completions(
            [
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ],
            [
                'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use ($connection) {
                    $connection->send(new Chunk(json_encode($result, JSON_UNESCAPED_UNICODE)));
                    $connection->send(new Chunk(''));
                },
            ]
        );

        return response()->withHeaders([
            'Transfer-Encoding' => 'chunked',
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
```

### Tool / function calling (`tools`)

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;

class ChatController
{
    private function runTool(string $name, array $args): string
    {
        if ($name === 'get_weather') {
            $city = $args['city'] ?? '';
            return json_encode([
                'city' => $city,
                'summary' => 'Clear',
                'temp_c' => 22,
            ], JSON_UNESCAPED_UNICODE);
        }
        return json_encode(['error' => 'unknown tool'], JSON_UNESCAPED_UNICODE);
    }

    public function completions(Request $request)
    {
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get the current weather for a given city',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string'],
                        ],
                        'required' => ['city'],
                    ],
                ],
            ],
        ];

        $messages = [
            ['role' => 'user', 'content' => 'What is the weather in Hangzhou? Use the tool first, then answer.'],
        ];

        $first = $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        $choice = $first['choices'][0] ?? null;
        $assistantMsg = $choice['message'] ?? null;
        if (
            $assistantMsg
            && ($choice['finish_reason'] ?? '') === 'tool_calls'
            && !empty($assistantMsg['tool_calls'])
        ) {
            $messages[] = $assistantMsg;
            foreach ($assistantMsg['tool_calls'] as $tc) {
                $fn = $tc['function']['name'] ?? '';
                $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content' => $this->runTool($fn, $args),
                ];
            }
            $second = $chat->completions([
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'tools' => $tools,
            ]);
            return json([
                'first' => $first,
                'second' => $second,
            ]);
        }

        return json($first);
    }
}
```

---

## Image: generations (Webman)

### Coroutines

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Image;

class ImageController
{
    public function generations(Request $request)
    {
        $image = new Image([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $result = $image->generations([
            'model' => 'dall-e-3',
            'prompt' => 'a dog',
            'n' => 1,
            'size' => '1024x1024',
        ]);
        return json($result);
    }
}
```

### Async callbacks

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Image;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class ImageController
{
    public function generations(Request $request)
    {
        $connection = $request->connection;
        $image = new Image([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $image->generations(
            [
                'model' => 'dall-e-3',
                'prompt' => 'a dog',
                'n' => 1,
                'size' => '1024x1024',
            ],
            [
                'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use ($connection) {
                    $connection->send(new Chunk(json_encode($result, JSON_UNESCAPED_UNICODE)));
                    $connection->send(new Chunk(''));
                },
            ]
        );

        return response()->withHeaders([
            'Content-Type' => 'application/json; charset=utf-8',
            'Transfer-Encoding' => 'chunked',
        ]);
    }
}
```

---

## Embedding: vectors (Webman)

### Coroutines

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Embedding;

class EmbeddingController
{
    public function create(Request $request)
    {
        $embedding = new Embedding([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $result = $embedding->create([
            'model' => 'text-embedding-3-small',
            'input' => 'Some words',
            'encoding_format' => 'float',
        ]);
        return json($result);
    }
}
```

### Async callbacks

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Embedding;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class EmbeddingController
{
    public function create(Request $request)
    {
        $connection = $request->connection;
        $embedding = new Embedding([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $embedding->create(
            [
                'model' => 'text-embedding-3-small',
                'input' => 'Some words',
                'encoding_format' => 'float',
            ],
            [
                'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use ($connection) {
                    $connection->send(new Chunk(json_encode($result, JSON_UNESCAPED_UNICODE)));
                    $connection->send(new Chunk(''));
                },
            ]
        );

        return response()->withHeaders([
            'Content-Type' => 'application/json; charset=utf-8',
            'Transfer-Encoding' => 'chunked',
        ]);
    }
}
```

---

## Audio: text-to-speech (TTS) (Webman)

### Coroutines

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;

class AudioController
{
    public function speech(Request $request)
    {
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $binary = $audio->speech([
            'model' => 'gpt-4o-mini-tts',
            'input' => 'Hello, how can I help you?',
            'voice' => 'alloy',
        ]);
        return response($binary)->withHeaders([
            'Content-Type' => 'audio/mpeg',
        ]);
    }
}
```

### Coroutines · streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;
use Workerman\Protocols\Http\Chunk;

class AudioController
{
    public function speechStream(Request $request)
    {
        $connection = $request->connection;
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $chunks = $audio->speech([
            'model' => 'gpt-4o-mini-tts',
            'input' => 'Hello, how can I help you?',
            'voice' => 'alloy',
            'stream' => true,
        ]);

        $connection->send(response()->withHeaders([
            'Content-Type' => 'audio/mpeg',
            'Transfer-Encoding' => 'chunked',
        ]));

        foreach ($chunks as $buffer) {
            $connection->send(new Chunk((string) $buffer));
        }
        $connection->close(new Chunk(''));
    }
}
```

### Async callbacks · streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class AudioController
{
    public function speech(Request $request)
    {
        $connection = $request->connection;
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $audio->speech(
            [
                'model' => 'gpt-4o-mini-tts',
                'input' => 'Hello, how can I help you?',
                'voice' => 'alloy',
            ],
            [
                'stream' => function (string $buffer) use ($connection) {
                    $connection->send(new Chunk($buffer));
                },
                'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use ($connection) {
                    $connection->send(new Chunk(''));
                },
            ]
        );

        return response()->withHeaders([
            'Content-Type' => 'audio/mpeg',
            'Transfer-Encoding' => 'chunked',
        ]);
    }
}
```

---

## Audio: speech-to-text (STT / `transcriptions`) (Webman)


### Coroutines · non-streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;

class AudioController
{
    public function transcribe(Request $request)
    {
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $result = $audio->transcriptions([
            'model' => 'gpt-4o-mini-transcribe',
            'file' => '/path/to/audio.mp3',
            // 'response_format' => 'json', // default JSON; use 'text' for plain-text responses, etc.
        ]);

        // Usually ['text' => '...', ...] for JSON; string when the response body is plain text
        return json($result);
    }
}
```

### Coroutines · streaming (SSE events)

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;

class AudioController
{
    public function transcribeStream(Request $request)
    {
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $events = $audio->transcriptions([
            'model' => 'gpt-4o-mini-transcribe',
            'file' => [
                'contents' => (string) file_get_contents('/path/to/audio.mp3'),
                'filename' => 'clip.mp3',
                'mime' => 'audio/mpeg',
            ],
            'stream' => true,
        ]);

        $lines = [];
        foreach ($events as $ev) {
            $lines[] = $ev;
        }

        return json(['events' => $lines]);
    }
}
```

### Async callbacks · non-streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;

class AudioController
{
    public function transcribeAsync(Request $request)
    {
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $audio->transcriptions(
            [
                'model' => 'gpt-4o-mini-transcribe',
                'file' => '/path/to/audio.mp3',
            ],
            [
                'complete' => function (array|string|null $result, ?OpenAIException $e, ?Response $response) {
                    // On success: $result is array|string; on failure: $e is non-null
                },
            ]
        );

        return json(['ok' => true, 'note' => 'Handle the result inside the complete callback']);
    }
}
```

### Async callbacks · streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Audio;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;

class AudioController
{
    public function transcribeStreamAsync(Request $request)
    {
        $audio = new Audio([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $audio->transcriptions(
            [
                'model' => 'gpt-4o-mini-transcribe',
                'file' => '/path/to/audio.mp3',
                'stream' => true,
            ],
            [
                'stream' => function (array $event) {
                    // e.g. transcript.text.delta / transcript.text.done
                },
                'complete' => function (array|string|null $result, ?OpenAIException $e, ?Response $response) {
                    // If stream is set above, $result is often null on success.
                    // If only complete + $data['stream'], $result is an aggregated array (includes text, etc.).
                },
            ]
        );

        return json(['ok' => true]);
    }
}
```

---

## Gateway compatibility: Azure OpenAI

### Coroutines · Chat streaming

```php
$chat = new Chat([
    'api' => 'https://YOUR_RESOURCE.openai.azure.com',
    'apikey' => getenv('AZURE_OPENAI_KEY') ?: 'xxx',
    'isAzure' => true,
    // optional: 'azureApiVersion' => '2023-05-15',
]);

$chunks = $chat->completions([
    'model' => 'YOUR_DEPLOYMENT_NAME',
    'stream' => true,
    'messages' => [['role' => 'user', 'content' => 'hello']],
]);
```

### Async callbacks · Chat streaming

```php
$chat = new Chat([
    'api' => 'https://YOUR_RESOURCE.openai.azure.com',
    'apikey' => getenv('AZURE_OPENAI_KEY') ?: 'xxx',
    'isAzure' => true,
]);
```

---

## Gateway compatibility: Alibaba Cloud DashScope (OpenAI-compatible mode)

Documentation: <https://help.aliyun.com/zh/dashscope/developer-reference/compatibility-of-openai-with-dashscope>

### Coroutines · Chat streaming

When `Chat` is configured with an `api` URL **that includes a path**, if the path ends with a **trailing slash (`/`)**, `chat/completions` is appended automatically (same pattern as the OpenAI-compatible path). For DashScope compatibility mode, use a base URL ending with **`/v1/`**:

```php
$chat = new Chat([
    'api' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/',
    'apikey' => getenv('DASHSCOPE_API_KEY') ?: 'xxx',
]);

$chunks = $chat->completions([
    'model' => 'qwen-turbo',
    'stream' => true,
    'messages' => [['role' => 'user', 'content' => 'hello']],
]);
```

### Async callbacks · Chat streaming

```php
$chat = new Chat([
    'api' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/',
    'apikey' => getenv('DASHSCOPE_API_KEY') ?: 'xxx',
]);
```

---

## Optional parameters

The second argument `$options` on each API method may include:

- **`timeout`**: timeout in seconds (defaults differ slightly per API; see the Client constructor in the source).
- **`headers`**: extra HTTP headers (merged with the defaults).

---

## Response headers: `with_response`

### Non-streaming

```php
[$result, $response] = $chat->completions([
    'model' => 'gpt-4o-mini',
    'messages' => [['role' => 'user', 'content' => 'hello']],
], ['with_response' => true]);

echo $response->getHeaderLine('x-request-id');
```

> **Note**  
> Other endpoints also support `with_response` to obtain the `Response` object; usage is the same.

### Streaming

```php
[$chunks, $response] = $chat->completions([
    'model' => 'gpt-4o-mini',
    'stream' => true,
    'messages' => [['role' => 'user', 'content' => 'hello']],
], ['with_response' => true]);

echo $response->getHeaderLine('x-request-id');

foreach ($chunks as $chunk) {
    echo $chunk['choices'][0]['delta']['content'] ?? '';
}
```

---

## Exceptions and errors

Error handling is unified across coroutine and async styles via the same **`Webman\Openai\OpenAIException`** class.

---

#### Coroutines · non-streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;

class ChatController
{
    public function completions(Request $request)
    {
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        try {
            $result = $chat->completions([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ]);
            return json($result);
        } catch (OpenAIException $e) {
            return json([
                'ok' => false,
                'message' => $e->getMessage(),
                'http_status' => $e->statusCode,
                'error_code' => $e->errorCode,
                'error_type' => $e->errorType,
                'error_param' => $e->errorParam,
                'raw' => $e->raw,
            ], $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 500);
        }
    }
}
```

#### Coroutines · streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class ChatController
{
    public function completions(Request $request)
    {
        $connection = $request->connection;
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        try {
            $chunks = $chat->completions([
                'model' => 'gpt-4o-mini',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ]);
        } catch (OpenAIException $e) {
            return json([
                'ok' => false,
                'message' => $e->getMessage(),
                'http_status' => $e->statusCode,
            ], 500);
        }

        $connection->send(response()->withHeaders([
            'Transfer-Encoding' => 'chunked',
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
        ]));

        try {
            foreach ($chunks as $chunk) {
                $connection->send(new Chunk(json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n"));
            }
        } catch (OpenAIException $e) {
            $connection->send(new Chunk(json_encode([
                '_stream_error' => true,
                'message' => $e->getMessage(),
                'http_status' => $e->statusCode,
            ], JSON_UNESCAPED_UNICODE) . "\n"));
        }
        $connection->close(new Chunk(''));
    }
}
```

#### Async callbacks · non-streaming

```php
<?php

namespace app\controller;

use support\Request;
use Webman\Openai\Chat;
use Webman\Openai\OpenAIException;
use Workerman\Http\Response;
use Workerman\Protocols\Http\Chunk;

class ChatController
{
    public function completions(Request $request)
    {
        $connection = $request->connection;
        $chat = new Chat([
            'apikey' => getenv('OPENAI_API_KEY') ?: 'sk-xxx',
            'api' => 'https://api.openai.com',
        ]);

        $chat->completions(
            [
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ],
            [
                'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use ($connection) {
                    if ($e !== null) {
                        $payload = [
                            'ok' => false,
                            'message' => $e->getMessage(),
                            'http_status' => $e->statusCode,
                            'error_code' => $e->errorCode,
                            'error_type' => $e->errorType,
                            'error_param' => $e->errorParam,
                        ];
                    } else {
                        $payload = ['ok' => true, 'data' => $result];
                    }
                    $connection->send(new Chunk(json_encode($payload, JSON_UNESCAPED_UNICODE)));
                    $connection->send(new Chunk(''));
                },
            ]
        );

        return response()->withHeaders([
            'Transfer-Encoding' => 'chunked',
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
```
