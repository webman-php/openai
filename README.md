# openai
OpenAI PHP asynchronous client for workerman and webman.

# Documentation

## Chat with stream
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
        $connection = $request->connection;
        $chat = new Chat(['apikey' => 'sk-xx', 'api' => 'https://api.openai.com']);
        $chat->completions(
            [
                'model' => 'gpt-3.5-turbo',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ], [
            'stream' => function($data) use ($connection) {
                $connection->send(new Chunk(json_encode($data, JSON_UNESCAPED_UNICODE) . "\n"));
            },
            'complete' => function($result, $response) use ($connection) {
                if (isset($result['error'])) {
                    $connection->send(new Chunk(json_encode($result, JSON_UNESCAPED_UNICODE) . "\n"));
                }
                $connection->send(new Chunk(''));
            },
        ]);
        return response()->withHeaders([
            "Transfer-Encoding" => "chunked",
        ]);
    }
}
```

## Chat without stream
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
        $connection = $request->connection;
        $chat = new Chat(['apikey' => 'sk-xxx', 'api' => 'https://api.openai.com']);
        $chat->completions(
            [
                'model' => 'gpt-3.5-turbo',
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ], [
            'complete' => function($result, $response) use ($connection) {
                $connection->send(new Chunk(json_encode($result, JSON_UNESCAPED_UNICODE) . "\n"));
                $connection->send(new Chunk(''));
            },
        ]);
        return response()->withHeaders([
            "Transfer-Encoding" => "chunked",
        ]);
    }
}
```

## Image generations
```php
<?php
namespace app\controller;
use support\Request;

use Webman\Openai\Image;
use Workerman\Protocols\Http\Chunk;

class ImageController
{
    public function generations(Request $request)
    {
        $connection = $request->connection;
        $image = new Image(['apikey' => 'sk-xxx', 'api' => 'https://api.openai.com']);
        $image->generations([
            'model' => 'dall-e-3',
            'prompt' => 'a dog',
            'n' => 1,
            'size' => "1024x1024"
        ], [
            'complete' => function($result) use ($connection) {
                $connection->send(new Chunk(json_encode($result)));
                $connection->send(new Chunk(''));
            }
        ]);
        return response()->withHeaders([
            "Content-Type" => "application/json",
            "Transfer-Encoding" => "chunked",
        ]);
    }

}
```

## Audio speech
```php
<?php
namespace app\controller;
use support\Request;

use Webman\Openai\Audio;
use Workerman\Protocols\Http\Chunk;

class AudioController
{
    public function speech(Request $request)
    {
        $model = $request->input('model', 'tts-1');
        $connection = $request->connection;
        $audio = new Audio(['apikey' => 'sk-xxx', 'api' => 'https://api.openai.com']);
        $audio->speech([
            'model' => $model,
            'input' => '你好，有什么可以帮您？',
            'voice' => 'echo'
        ], [
            'stream' => function($buffer) use ($connection) {
                $connection->send(new Chunk($buffer));
            },
            'complete' => function($result, $response) use ($connection) {
                $connection->send(new Chunk(''));
            }
        ]);
        return response()->withHeaders([
            "Content-Type" => "audio/mpeg",
            "Transfer-Encoding" => "chunked",
        ]);
    }
}
```

## Embeddings
```php
<?php
namespace app\controller;
use support\Request;

use Webman\Openai\Embedding;
use Workerman\Protocols\Http\Chunk;

class EmbeddingController
{
    public function create(Request $request)
    {
        $connection = $request->connection;
        $embedding = new Embedding(['apikey' => 'sk-xxx', 'api' => 'https://api.openai.com']);
        $embedding->create([
            'model' => 'text-embedding-ada-002',
            'input' => 'Some words',
            'encodding_format' => 'float',
        ], [
            'complete' => function($result) use ($connection) {
                $connection->send(new Chunk(json_encode($result)));
                $connection->send(new Chunk(''));
            }
        ]);
        return response()->withHeaders([
            "Content-Type" => "application/json",
            "Transfer-Encoding" => "chunked",
        ]);
    }
}
```

## Azure openai
```php
public function completions(Request $request)
{
    $connection = $request->connection;
    $chat = new Chat(['api' => 'https://xxx.openai.azure.com', 'apikey' => 'xxx', 'isAzure' => true]);
    $chat->completions(
        [
            'model' => 'gpt-3.5-turbo',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ], [
        'stream' => function($data) use ($connection) {
            $connection->send(new Chunk(json_encode($data, JSON_UNESCAPED_UNICODE) . "\n"));
        },
        'complete' => function($result, $response) use ($connection) {
            if (isset($result['error'])) {
                $connection->send(new Chunk(json_encode($result, JSON_UNESCAPED_UNICODE) . "\n"));
            }
            $connection->send(new Chunk(''));
        },
    ]);
    return response()->withHeaders([
        "Transfer-Encoding" => "chunked",
    ]);
}
```
