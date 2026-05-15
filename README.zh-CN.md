# webman/openai

[English](README.md) | **简体中文**

PHP 非阻塞 OpenAI 客户端，支持协程，内置连接池，适用于 [Workerman](https://github.com/walkor/workerman) / [webman](https://github.com/walkor/webman)。

## 安装

```bash
composer require webman/openai
```

需要 **PHP 8.1+**、**Workerman 5.1+运行环境**。

> **webman开启协程**  
> 开启协程需要设置 `config/process.php` 中 `webman.eventLoop` 为 `Workerman\Events\Fiber::class`。  
> 若已安装 `swoole` 或 `swow` 扩展，也可设为 `Workerman\Events\Swoole::class` 或 `Workerman\Events\Swow::class`。

---


## 快速预览(Workerman环境) 

### 非流式

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

### 流式 

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

## Chat：对话补全 (Webman环境)

> **提示**  
> 不管是在workerman还是webman运行环境，接口使用方法是一样的，以下以webman为例。

### 协程 · 非流式

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

### 协程 · 流式

流式在协程下返回 **生成器**：必须先向客户端写出响应头，再逐块 `Chunk`，最后 `close` 结束。

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
        // 流式已手动发完，无需 return
    }
}
```

### 异步回调 · 流式

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

### 异步回调 · 非流式

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

### Tool / Function calling（`tools`）

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
                'summary' => '晴',
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
                    'description' => '查询指定城市的当前天气',
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
            ['role' => 'user', 'content' => '杭州天气怎样？先查工具再回答。'],
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

## Image：图像生成 (Webman环境)


### 协程

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

### 异步回调 (Webman环境)

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

## Embedding：向量 (Webman环境)

### 协程

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

### 异步回调

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

## Audio：语音合成（TTS）(Webman环境)

### 协程

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
            'input' => '你好，有什么可以帮您？',
            'voice' => 'alloy',
        ]);
        return response($binary)->withHeaders([
            'Content-Type' => 'audio/mpeg',
        ]);
    }
}
```

### 协程 · 流式

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
            'input' => '你好，有什么可以帮您？',
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

### 异步回调 · 流式

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
                'input' => '你好，有什么可以帮您？',
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

## Audio：语音转文字（STT / `transcriptions`）(Webman环境)


### 协程 · 非流式

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
            // 'response_format' => 'json', // 默认 JSON；纯文本时可改为 'text' 等
        ]);

        // $result 一般为 ['text' => '...', ...]；纯文本响应时为 string
        return json($result);
    }
}
```

### 协程 · 流式（SSE 事件）

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

### 异步回调 · 非流式

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
                    // 成功：$result 为数组或字符串；失败：$e 非空
                },
            ]
        );

        return json(['ok' => true, 'note' => '结果在 complete 回调中处理']);
    }
}
```

### 异步回调 · 流式

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
                    // 例如 transcript.text.delta / transcript.text.done 等
                },
                'complete' => function (array|string|null $result, ?OpenAIException $e, ?Response $response) {
                    // 若上面提供了 stream：成功完成时 $result 多为 null。
                    // 若仅 complete + $data['stream']：$result 为聚合后的数组（含 text 等）。
                },
            ]
        );

        return json(['ok' => true]);
    }
}
```

---

## 兼容网关：Azure OpenAI

### 协程 · Chat 流式

```php
$chat = new Chat([
    'api' => 'https://YOUR_RESOURCE.openai.azure.com',
    'apikey' => getenv('AZURE_OPENAI_KEY') ?: 'xxx',
    'isAzure' => true,
    // 可选：'azureApiVersion' => '2023-05-15',
]);

$chunks = $chat->completions([
    'model' => 'YOUR_DEPLOYMENT_NAME',
    'stream' => true,
    'messages' => [['role' => 'user', 'content' => 'hello']],
]);
```

### 异步回调 · Chat 流式

```php
$chat = new Chat([
    'api' => 'https://YOUR_RESOURCE.openai.azure.com',
    'apikey' => getenv('AZURE_OPENAI_KEY') ?: 'xxx',
    'isAzure' => true,
]);
```

---

## 兼容网关：阿里云 DashScope（OpenAI 兼容模式）

文档：<https://help.aliyun.com/zh/dashscope/developer-reference/compatibility-of-openai-with-dashscope>

### 协程 · Chat 流式

`Chat` 在 `api` **带有路径**时，若路径以 **`/` 结尾**，会自动拼接 `chat/completions`（与 OpenAI 兼容路径一致）。DashScope 兼容模式请使用以 **`/v1/`** 结尾的 base：

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

### 异步回调 · Chat 流式

```php
$chat = new Chat([
    'api' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/',
    'apikey' => getenv('DASHSCOPE_API_KEY') ?: 'xxx',
]);
```

---

## 可选参数

各接口第二个参数 `$options` 中可传：

- **`timeout`**：超时秒数（各接口默认值不完全相同，见源码中的 Client 构造）。
- **`headers`**：额外 HTTP 头（会与默认头合并）。

---

## 获取响应头：`with_response`

### 非流式
```php
[$result, $response] = $chat->completions([
    'model' => 'gpt-4o-mini',
    'messages' => [['role' => 'user', 'content' => 'hello']],
], ['with_response' => true]);

echo $response->getHeaderLine('x-request-id');
```

> **提示**
> 其它接口也支持`with_response`获取`response`，获取方式相同

### 流式

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

## 异常与错误

错误模型在协程与异步两种用法下完全统一，使用同一个 **`Webman\Openai\OpenAIException`** 类。

---


#### 协程 · 非流式

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

#### 协程 · 流式

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

#### 异步回调 · 非流式

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
