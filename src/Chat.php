<?php

namespace Webman\Openai;

use Generator;
use Throwable;
use Workerman\Coroutine;
use Workerman\Http\Response;

class Chat extends Base
{

    /**
     * @var mixed|string Azure api version
     */
    protected $azureApiVersion = '2024-10-21';

    /**
     * Chat completions.
     *
     * Behaviour:
     *  - Async callback mode (returns void): set $options['stream'] and/or $options['complete'].
     *      stream:   callable(array $chunk): void           // SSE JSON chunk
     *      complete: callable(?array $result, ?OpenAIException $exception, ?Response $response): void
     *                $exception === null => success, $result is the decoded payload.
     *                $exception !== null => failure, $result is null.
     *                $response is the underlying HTTP response (headers/status); may be a
     *                placeholder Response(0) when the failure is transport-level. Older
     *                callbacks declaring only 1 or 2 parameters remain compatible.
     *  - Coroutine sync mode (must run inside a coroutine), if neither callback is set:
     *      - $data['stream'] truthy: returns a Generator yielding each SSE JSON chunk.
     *        With $options['with_response'] === true, returns [$generator, ?Response] and
     *        suspends the coroutine until response headers arrive (or transport fails).
     *      - Otherwise: returns the full decoded result array. With $options['with_response']
     *        === true, returns [$result, ?Response] instead.
     *  - In sync mode, HTTP/API errors raise an OpenAIException carrying the full context.
     *
     * @param array $data
     * @param array{
     *     timeout?: int,
     *     headers?: array<string,string>,
     *     with_response?: bool,
     *     stream?: callable(array): void,
     *     complete?: callable(?array, ?OpenAIException, ?Response): void,
     * } $options
     * @return array|Generator|void
     */
    public function completions(array $data, array $options = [])
    {
        $async = isset($options['stream']) || isset($options['complete']);
        if ($async && isset($options['stream'])) {
            $data['stream'] = true;
        }
        if (!$async && !empty($data['stream'])) {
            if (!Coroutine::isCoroutine()) {
                throw new OpenAIException(
                    'Webman\\Openai\\Chat::completions() sync stream mode requires a coroutine context.'
                );
            }
            return $this->waitStream($data, $options);
        }
        $ret = $this->syncOrFormatAndSend($options, function (array $opt) use ($data): void {
            $this->request($data, $opt);
        });
        if ($ret !== null) {
            return $ret;
        }
    }

    /**
     * Coroutine sync streaming.
     *
     * - Default: returns a Generator that yields SSE chunks and raises OpenAIException at
     *   end-of-stream if the upstream produced an error.
     * - With $options['with_response'] === true: suspends the current coroutine until the
     *   response headers arrive (or transport fails before any headers), then returns
     *   [$generator, ?Response]. Any body chunks that arrived during the suspension are
     *   buffered and yielded by the generator on the first iterations.
     *
     * @param array $data
     * @param array $options
     * @return Generator|array{0: Generator, 1: ?Response}
     */
    protected function waitStream(array $data, array $options): Generator|array
    {
        $current = Coroutine::getCurrent();
        $buffer = [];
        $done = false;
        $waiting = false;
        $errorResult = null;
        $errorResponse = null;
        $headersResponse = null;
        $headersReady = false;
        $withResponse = !empty($options['with_response']);

        $options['stream'] = function ($chunk) use (&$buffer, &$waiting, $current) {
            $buffer[] = $chunk;
            if ($waiting) {
                $waiting = false;
                $current->resume();
            }
        };
        $options['response'] = function (Response $response) use (&$headersResponse, &$headersReady, &$waiting, $current, $withResponse) {
            $headersResponse = $response;
            $headersReady = true;
            // Only the with_response handshake suspends here; the streaming generator never waits on this.
            if ($withResponse && $waiting) {
                $waiting = false;
                $current->resume();
            }
        };
        $options['complete'] = function ($result, ?Response $response = null) use (&$errorResult, &$errorResponse, &$done, &$waiting, $current) {
            if (is_array($result) && isset($result['error'])) {
                $errorResult = $result;
                $errorResponse = $response;
            }
            $done = true;
            if ($waiting) {
                $waiting = false;
                $current->resume();
            }
        };

        $this->request($data, $options);

        $generator = (function () use (&$buffer, &$done, &$waiting, &$errorResult, &$errorResponse): Generator {
            while (true) {
                while ($buffer) {
                    yield array_shift($buffer);
                }
                if ($done) {
                    if ($errorResult !== null) {
                        throw OpenAIException::fromResult($errorResult, $errorResponse, 'OpenAI stream failed');
                    }
                    return;
                }
                $waiting = true;
                Coroutine::suspend();
            }
        })();

        if (!$withResponse) {
            return $generator;
        }

        // Wait for headers (success path) OR transport failure that completes before any headers.
        if (!$headersReady && !$done) {
            $waiting = true;
            Coroutine::suspend();
        }
        // Transport failed before any headers were received → nothing to stream, fail fast.
        if (!$headersReady && $errorResult !== null) {
            throw OpenAIException::fromResult($errorResult, $errorResponse, 'OpenAI stream failed');
        }
        return [$generator, $headersResponse];
    }

    /**
     * Fire the underlying async HTTP request (Workerman Http Client).
     *
     * @param array $data
     * @param array $options
     * @return void
     */
    protected function request(array $data, array $options): void
    {
        $headers = $this->getHeaders($options);
        $stream = !empty($data['stream']) && isset($options['stream']);
        $options += ['stream' => null, 'complete' => null];
        $options = $this->formatOptions($options);
        $requestOptions = [
            'method' => 'POST',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'headers' => $headers,
            'progress' => function ($buffer) use ($options) {
                static $tmp = '';
                $tmp .= $buffer;
                if ($tmp === '' || $tmp[strlen($tmp) - 1] !== "\n") {
                    return null;
                }
                preg_match_all('/data:\s*(\{.*\})[ \r]*\n/', $tmp, $matches);
                $tmp = '';
                foreach ($matches[1] ?: [] as $match) {
                    if ($json = json_decode($match, true)) {
                        $options['stream']($json);
                    }
                }
            },
            'success' => function (Response $response) use ($options) {
                $result = static::formatResponse((string)$response->getBody());
                $options['complete']($result, $response);
            },
            'error' => function ($exception) use ($options) {
                $options['complete']([
                    'error' => [
                        'code' => 'exception',
                        'message' => $exception->getMessage(),
                        'detail' => (string)$exception
                    ],
                ], new Response(0));
            }
        ];
        if (!$stream) {
            unset($requestOptions['progress']);
        }
        if (isset($options['response'])) {
            $requestOptions['response'] = $options['response'];
        }
        $url = $this->buildUrl($data['model'] ?? '');
        $http = $this->createHttpClient((int)($options['timeout'] ?? 3600));
        $http->request($url, $requestOptions);
    }

    /**
     * Build the chat completions endpoint URL.
     *
     * @param string $model
     * @return string
     */
    protected function buildUrl(string $model): string
    {
        $path = parse_url($this->api, PHP_URL_PATH);
        if (!$path) {
            return $this->api . ($this->isAzure
                ? "/openai/deployments/$model/chat/completions?api-version=$this->azureApiVersion"
                : "/v1/chat/completions");
        }
        if ($path[strlen($path) - 1] === '/') {
            return $this->api . 'chat/completions';
        }
        return $this->api;
    }

    /**
     * Format chat response.
     * @param $buffer
     * @return array|array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        if (!$buffer || $buffer[0] === '') {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'message' => 'Empty response from api',
                    'detail' => $buffer
                ]
            ];
        }
        $json = json_decode($buffer, true);
        if ($json) {
            return $json;
        }
        if ($buffer[0] === '<') {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'message' => 'Invalid response from api',
                    'detail' => $buffer
                ]
            ];
        }
        $chunks = explode("\n", $buffer);
        $content = '';
        $reasoning_content = '';
        $finishReason = null;
        $model = '';
        $promptFilterResults = null;
        $contentFilterResults = null;
        $contentFilterOffsets = null;
        $toolCalls = [];
        $usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0
        ];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === "") {
                continue;
            }
            if (strpos($chunk, 'data:{') === 0) {
                $chunk = substr($chunk, 5);
            } else {
                $chunk = substr($chunk, 6);
            }
            if ($chunk === "" || $chunk === "[DONE]") {
                continue;
            }
            try {
                $data = json_decode($chunk, true);
                if (isset($data['model'])) {
                    $model = $data['model'];
                }
                if (isset($data['prompt_filter_results'])) {
                    $promptFilterResults = $data['prompt_filter_results'];
                }
                if (isset($data['error'])) {
                    $err = $data['error'];
                    return [
                        'error' => is_array($err) ? $err : [
                            'message' => (string)$err,
                            'type' => 'invalid_request_error',
                            'code' => 'invalid_request_error',
                            'param' => null,
                        ],
                    ];
                }
                foreach ($data['choices'] ?? [] as $item) {
                    $content .= $item['delta']['content'] ?? "";
                    $reasoning_content .= $item['delta']['reasoning_content'] ?? "";
                    foreach ($item['delta']['tool_calls'] ?? [] as $function) {
                        if (isset($function['function']['name']) && $function['function']['name'] !== '') {
                            $toolCalls[$function['index']] = $function;
                        } elseif (isset($function['function']['arguments']) && $function['function']['arguments'] !== '') {
                            $toolCalls[$function['index']]['function']['arguments'] .= $function['function']['arguments'];
                        }
                    }
                    if (isset($item['finish_reason'])) {
                        $finishReason = $item['finish_reason'];
                    }
                    if (isset($item['content_filter_results'])) {
                        $contentFilterResults = $item['content_filter_results'];
                    }
                    if (isset($item['content_filter_offsets'])) {
                        $contentFilterOffsets = $item['content_filter_offsets'];
                    }
                }
                if (isset($data['usage'])) {
                    $usage = $data['usage'];
                }
            } catch (Throwable $e) {
                error_log((string)$e);
            }
        }
        $result = [
            'choices' => [
                [
                    'finish_reason' => $finishReason,
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                        'reasoning_content' => $reasoning_content,
                    ],
                ]
            ],
            'model' => $model,
            'usage' => $usage
        ];
        if ($promptFilterResults) {
            $result['prompt_filter_results'] = $promptFilterResults;
        }
        if ($contentFilterResults) {
            $result['choices'][0]['content_filter_results'] = $contentFilterResults;
        }
        if ($contentFilterOffsets) {
            $result['choices'][0]['content_filter_offsets'] = $contentFilterOffsets;
        }
        if ($toolCalls) {
            $result['choices'][0]['message']['tool_calls'] = array_values($toolCalls);
        }
        return $result;
    }

}
