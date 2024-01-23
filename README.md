# openai
OpenAI client for webman/workerman

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
