<?php

namespace Webman\Openai;

use Generator;
use Workerman\Coroutine;
use Workerman\Http\Response;

class Audio extends Base
{

    /**
     * @var mixed|string Azure api version
     */
    protected $azureApiVersion = '2024-10-21';

    /**
     * Text-to-speech API.
     *
     * Behaviour:
     *  - Async callback mode (returns void): set $options['stream'] and/or $options['complete'].
     *      stream:   callable(string $buffer): void              // raw audio bytes per HTTP chunk
     *      complete: callable(?string $result, ?OpenAIException $exception, ?Response $response): void
     *                On success, $result is the full audio binary (when stream is not used) or empty
     *                payload aggregated by the HTTP client; on failure, $result is null and
     *                $exception carries the upstream error context. Older 1- or 2-parameter
     *                callbacks remain compatible (PHP drops extras).
     *  - Coroutine sync mode (must run inside a coroutine), if neither callback is set:
     *      Returns the audio binary string, or [$binary, ?Response] when
     *      $options['with_response'] === true. HTTP/API errors raise an OpenAIException.
     *      With $data['stream'] truthy (same convention as {@see Chat::completions}), returns a
     *      Generator yielding each raw HTTP body chunk (see {@see waitSpeechStream}), or
     *      [$generator, ?Response] with $options['with_response'] === true. The flag is sent
     *      in the JSON body for OpenAI-compatible streaming TTS.
     *
     * @param array $data
     * @param array{
     *     timeout?: int,
     *     headers?: array<string,string>,
     *     with_response?: bool,
     *     stream?: callable(string): void,
     *     complete?: callable(?string, ?OpenAIException, ?Response): void,
     * } $options
     * @return string|Generator|array|void
     */
    public function speech(array $data, array $options = [])
    {
        $async = isset($options['stream']) || isset($options['complete']);
        if ($async && isset($options['stream'])) {
            $data['stream'] = true;
        }

        if (!$async && !empty($data['stream'])) {
            if (!Coroutine::isCoroutine()) {
                throw new OpenAIException(
                    'Webman\\Openai\\Audio::speech() sync stream mode requires a Workerman coroutine context.'
                );
            }

            return $this->waitSpeechStream($data, $options);
        }

        $ret = $this->syncOrFormatAndSend($options, function (array $opt) use ($data, $options): void {
            $this->sendSpeechRequest($data, $opt, isset($options['stream']));
        });
        if ($ret !== null) {
            return $ret;
        }
    }

    /**
     * Sync coroutine streaming: yields raw HTTP body chunks until the response completes.
     *
     * @param array $data
     * @param array $options
     * @return Generator<string>|array{0: Generator<string>, 1: ?Response}
     */
    protected function waitSpeechStream(array $data, array $options): Generator|array
    {
        $current = Coroutine::getCurrent();
        $queue = [];
        $done = false;
        $waiting = false;
        $errorResult = null;
        $errorResponse = null;
        $headersResponse = null;
        $headersReady = false;
        $withResponse = !empty($options['with_response']);

        $sendOpt = $options;

        $sendOpt['stream'] = function (string $chunk) use (&$queue, &$waiting, $current): void {
            $queue[] = $chunk;
            if ($waiting) {
                $waiting = false;
                $current->resume();
            }
        };
        $sendOpt['response'] = function (Response $response) use (&$headersResponse, &$headersReady, &$waiting, $current, $withResponse): void {
            $headersResponse = $response;
            $headersReady = true;
            if ($withResponse && $waiting) {
                $waiting = false;
                $current->resume();
            }
        };
        $sendOpt['complete'] = function ($result, ?Response $response = null) use (&$errorResult, &$errorResponse, &$done, &$waiting, $current): void {
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

        $this->sendSpeechRequest($data, $this->formatOptions($sendOpt), true);

        $generator = (function () use (&$queue, &$done, &$waiting, &$errorResult, &$errorResponse): Generator {
            while (true) {
                while ($queue) {
                    yield (string) array_shift($queue);
                }
                if ($done) {
                    if ($errorResult !== null) {
                        throw OpenAIException::fromResult($errorResult, $errorResponse, 'OpenAI speech stream failed');
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

        if (!$headersReady && !$done) {
            $waiting = true;
            Coroutine::suspend();
        }
        if (!$headersReady && $errorResult !== null) {
            throw OpenAIException::fromResult($errorResult, $errorResponse, 'OpenAI speech stream failed');
        }

        return [$generator, $headersResponse];
    }

    /**
     * @param array $opt Already {@see formatOptions}-wrapped when coming from {@see syncOrFormatAndSend}.
     */
    protected function sendSpeechRequest(array $data, array $opt, bool $useProgress): void
    {
        $requestOptions = [
            'method' => 'POST',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'headers' => $this->getHeaders($opt),
            'success' => function (Response $response) use ($opt) {
                $result = static::formatResponse((string)$response->getBody());
                $opt['complete']($result, $response);
            },
            'error' => function ($exception) use ($opt) {
                $opt['complete']([
                    'error' => [
                        'code' => 'exception',
                        'message' => $exception->getMessage(),
                        'detail' => (string) $exception,
                    ],
                ], new Response(0));
            },
        ];
        if ($useProgress) {
            $requestOptions['progress'] = function ($buffer) use ($opt) {
                $opt['stream']($buffer);
            };
        }
        if (isset($opt['response'])) {
            $requestOptions['response'] = $opt['response'];
        }
        $http = $this->createHttpClient((int) ($opt['timeout'] ?? 3600));
        $http->request($this->buildSpeechUrl($data['model'] ?? ''), $requestOptions);
    }

    protected function buildSpeechUrl(string $model): string
    {
        $path = parse_url($this->api, PHP_URL_PATH);
        if (!$path) {
            return $this->api . ($this->isAzure
                ? "/openai/deployments/$model/audio/speech?api-version=$this->azureApiVersion"
                : "/v1/audio/speech");
        }
        if ($path[strlen($path) - 1] === '/') {
            return $this->api . 'audio/speech';
        }
        return $this->api;
    }

    /**
     * Format audio response.
     * @param $buffer
     * @return array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        $json = json_decode($buffer, true);
        if ($json && !empty($json['error'])) {
            return $json;
        }
        if (strpos(ltrim($buffer), '<html>') === 0) {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'message' => 'Unable to parse response',
                    'detail' => $buffer,
                ],
            ];
        }

        return $buffer;
    }

}
