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

    /**
     * Speech-to-text (OpenAI-compatible POST .../v1/audio/transcriptions).
     *
     * Behaviour mirrors {@see Chat::completions} / {@see Audio::speech}:
     *  - Async callback mode (returns void): set $options['stream'] and/or $options['complete'].
     *      stream:   callable(array $event): void   // decoded SSE JSON per line (upstream stream)
     *      complete: callable(array|string|null $result, ?OpenAIException $exception, ?Response $response): void
     *                When only `complete` is used with `$data['stream']` => true, the final payload is an
     *                aggregated array shaped like non-stream JSON (at least `text`, plus `usage` /
     *                `logprobs` when present on `transcript.text.done`).
     *  - Coroutine sync mode (no stream/complete in $options):
     *      - $data['stream'] truthy: Generator yielding each decoded SSE event array.
     *      - Otherwise: decoded JSON array or raw string body (e.g. response_format=text).
     *
     * @param array<string, mixed> $data
     * @param array{
     *     timeout?: int,
     *     headers?: array<string,string>,
     *     with_response?: bool,
     *     stream?: callable(array): void,
     *     complete?: callable(array|string|null, ?OpenAIException, ?Response): void,
     *     response?: callable(Response): void,
     * } $options
     * @return array|string|Generator|array{0: Generator, 1: ?Response}|void|null
     */
    public function transcriptions(array $data, array $options = []): mixed
    {
        return $this->runTranscriptionEndpoint('transcriptions', $data, $options);
    }

    /**
     * Speech translation to English (OpenAI-compatible POST .../v1/audio/translations).
     *
     * Streaming aggregation for async `complete`-only matches {@see transcriptions()}.
     *
     * @param array<string, mixed> $data
     * @param array{
     *     timeout?: int,
     *     headers?: array<string,string>,
     *     with_response?: bool,
     *     stream?: callable(array): void,
     *     complete?: callable(array|string|null, ?OpenAIException, ?Response): void,
     *     response?: callable(Response): void,
     * } $options
     * @return array|string|Generator|array{0: Generator, 1: ?Response}|void|null
     */
    public function translations(array $data, array $options = []): mixed
    {
        return $this->runTranscriptionEndpoint('translations', $data, $options);
    }

    /**
     * @param 'transcriptions'|'translations' $segment
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array|string|Generator|array{0: Generator, 1: ?Response}|void|null
     */
    protected function runTranscriptionEndpoint(string $segment, array $data, array $options): mixed
    {
        $data = array_merge([], $data);
        $async = isset($options['stream']) || isset($options['complete']);
        if ($async && isset($options['stream'])) {
            $data['stream'] = true;
        }

        if (!$async && !empty($data['stream'])) {
            if (!Coroutine::isCoroutine()) {
                throw new OpenAIException(
                    sprintf(
                        'Webman\\Openai\\Audio::%s() sync stream mode requires a Workerman coroutine context.',
                        $segment
                    )
                );
            }

            return $this->waitTranscriptionStream($segment, $data, $options);
        }

        $ret = $this->syncOrFormatAndSend($options, function (array $opt) use ($segment, $data, $options): void {
            $this->sendTranscriptionRequest($segment, $data, $opt, isset($options['stream']));
        });
        if ($ret !== null) {
            return $ret;
        }

        return null;
    }

    /**
     * Coroutine sync streaming for transcriptions/translations (SSE).
     *
     * @param 'transcriptions'|'translations' $segment
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return Generator|array{0: Generator, 1: ?Response}
     */
    protected function waitTranscriptionStream(string $segment, array $data, array $options): Generator|array
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

        $sendOpt = $options;

        $sendOpt['stream'] = function ($chunk) use (&$buffer, &$waiting, $current): void {
            if (!is_array($chunk)) {
                return;
            }
            $buffer[] = $chunk;
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

        $this->sendTranscriptionRequest($segment, $data, $this->formatOptions($sendOpt), true);

        $generator = (function () use (&$buffer, &$done, &$waiting, &$errorResult, &$errorResponse): Generator {
            while (true) {
                while ($buffer) {
                    yield array_shift($buffer);
                }
                if ($done) {
                    if ($errorResult !== null) {
                        throw OpenAIException::fromResult($errorResult, $errorResponse, 'OpenAI transcription stream failed');
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
            throw OpenAIException::fromResult($errorResult, $errorResponse, 'OpenAI transcription stream failed');
        }

        return [$generator, $headersResponse];
    }

    /**
     * @param 'transcriptions'|'translations' $segment
     * @param array<string, mixed> $data
     * @param array<string, mixed> $opt Already {@see formatOptions}-wrapped when coming from {@see syncOrFormatAndSend} or {@see waitTranscriptionStream}.
     */
    protected function sendTranscriptionRequest(string $segment, array $data, array $opt, bool $useProgress): void
    {
        [$multipartElementList, $close] = $this->buildTranscriptionMultipartElements($data);

        $headers = $this->getHeaders($opt);
        unset($headers['Content-Type']);

        $closed = false;
        $safeClose = function () use (&$closed, $close): void {
            if ($closed || $close === null) {
                return;
            }
            $closed = true;
            ($close)();
        };

        $sseTmp = '';
        $requestOptions = [
            'method' => 'POST',
            'data' => ['multipart' => $multipartElementList],
            'headers' => $headers,
            'success' => function (Response $response) use ($opt, $safeClose, $useProgress, $data): void {
                $safeClose();
                if ($response->getStatusCode() >= 400) {
                    $opt['complete'](self::transcriptionHttpErrorPayload($response), $response);
                    return;
                }
                if ($useProgress) {
                    $opt['complete'](null, $response);
                    return;
                }
                $body = (string) $response->getBody();
                if (!empty($data['stream'])) {
                    $opt['complete'](self::aggregateTranscriptionStreamBody($body, $response->getHeaderLine('Content-Type')), $response);
                    return;
                }
                $opt['complete'](self::parseTranscriptionResponse($response), $response);
            },
            'error' => function ($exception) use ($opt, $safeClose): void {
                $safeClose();
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
            $requestOptions['progress'] = function (string $buffer) use ($opt, &$sseTmp): void {
                $sseTmp .= $buffer;
                if ($sseTmp === '' || $sseTmp[strlen($sseTmp) - 1] !== "\n") {
                    return;
                }
                preg_match_all('/data:\s*(\{.*\})[ \r]*\n/', $sseTmp, $matches);
                $sseTmp = '';
                foreach ($matches[1] ?: [] as $match) {
                    $json = json_decode($match, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $opt['stream']($json);
                    }
                }
            };
        }
        if (isset($opt['response'])) {
            $requestOptions['response'] = $opt['response'];
        }
        $url = $this->buildTranscriptionUrl($data['model'] ?? '', $segment);
        $http = $this->createHttpClient((int) ($opt['timeout'] ?? 3600));
        $http->request($url, $requestOptions);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: list<array{name: string, contents: mixed, filename?: string, headers?: array<string,string>}>, 1: ?callable(): void}
     */
    protected function buildTranscriptionMultipartElements(array $data): array
    {
        if (!isset($data['file'])) {
            throw new OpenAIException("The 'file' field is required for audio transcriptions and translations.");
        }

        [$contents, $filename, $mime, $close] = $this->normalizeTranscriptionFile($data['file']);

        $filePart = [
            'name' => 'file',
            'contents' => $contents,
            'filename' => $filename,
        ];
        if ($mime !== null && $mime !== '') {
            $filePart['headers'] = ['Content-Type' => $mime];
        }
        $parts = [$filePart];

        foreach ($data as $key => $value) {
            if ($key === 'file' || $value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = [
                'name' => (string) $key,
                'contents' => (string) $value,
            ];
        }

        return [$parts, $close];
    }

    /**
     * @return array{0: resource|string, 1: string, 2: ?string, 3: ?callable(): void}
     */
    protected function normalizeTranscriptionFile(mixed $file): array
    {
        if (is_string($file)) {
            if (!is_file($file) || !is_readable($file)) {
                throw new OpenAIException('Transcription file not found or not readable: ' . $file);
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new OpenAIException('Unable to open transcription file: ' . $file);
            }

            return [$handle, basename($file), null, static function () use ($handle): void {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }];
        }

        if (!is_array($file)) {
            throw new OpenAIException('Invalid transcription file specification.');
        }

        $filename = (string) ($file['filename'] ?? 'audio.wav');
        $mime = isset($file['mime']) ? (string) $file['mime'] : null;

        if (isset($file['path'])) {
            $path = (string) $file['path'];
            if (!is_file($path) || !is_readable($path)) {
                throw new OpenAIException('Transcription file not found or not readable: ' . $path);
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new OpenAIException('Unable to open transcription file: ' . $path);
            }

            return [$handle, $filename, $mime, static function () use ($handle): void {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }];
        }

        if (array_key_exists('contents', $file)) {
            return [(string) $file['contents'], $filename, $mime, null];
        }

        throw new OpenAIException('Invalid transcription file specification.');
    }

    protected static function parseTranscriptionResponse(Response $response): array|string
    {
        return self::parseTranscriptionBodyFromString(
            (string) $response->getBody(),
            $response->getHeaderLine('Content-Type')
        );
    }

    /**
     * Non-streaming JSON / plain text body (no SSE aggregation).
     *
     * @return array|string
     */
    protected static function parseTranscriptionBodyFromString(string $body, string $contentType): array|string
    {
        $ct = strtolower($contentType);
        if (
            str_contains($ct, 'application/json')
            || ($body !== '' && ($body[0] === '{' || $body[0] === '['))
        ) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $body;
    }

    /**
     * Aggregate OpenAI-style transcription SSE into a single payload similar to non-stream JSON
     * (e.g. {@see parseTranscriptionBodyFromString} for `{"text":"..."}`), so async callers using
     * only `$options['complete']` still receive a usable result when `$data['stream']` is true.
     *
     * Handles: `transcript.text.delta` (concatenate `delta`), `transcript.text.done` (prefer `text`),
     * `transcript.text.segment` (diarized segments), and top-level `error` events.
     *
     * @return array|string
     */
    protected static function aggregateTranscriptionStreamBody(string $body, string $contentType): array|string
    {
        if (!str_contains($body, 'data:')) {
            return self::parseTranscriptionBodyFromString($body, $contentType);
        }

        $deltaText = '';
        $doneText = null;
        $doneUsage = null;
        $doneLogprobs = null;
        $segments = [];

        foreach (preg_split("/\r\n|\n|\r/", $body) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $obj = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
                continue;
            }
            if (isset($obj['error'])) {
                $err = $obj['error'];
                return [
                    'error' => is_array($err) ? $err : [
                        'message' => (string) $err,
                        'type' => 'invalid_request_error',
                        'code' => 'invalid_request_error',
                        'param' => null,
                    ],
                ];
            }
            $type = $obj['type'] ?? '';
            if ($type === 'transcript.text.done' && isset($obj['text']) && is_string($obj['text'])) {
                $doneText = $obj['text'];
                if (isset($obj['usage'])) {
                    $doneUsage = $obj['usage'];
                }
                if (isset($obj['logprobs'])) {
                    $doneLogprobs = $obj['logprobs'];
                }
                continue;
            }
            if ($type === 'transcript.text.delta' && isset($obj['delta']) && is_string($obj['delta'])) {
                $deltaText .= $obj['delta'];
                continue;
            }
            if ($type === 'transcript.text.segment' && isset($obj['text']) && is_string($obj['text'])) {
                $segments[] = $obj['text'];
            }
        }

        $text = $doneText ?? $deltaText;
        if ($text === '' && $segments !== []) {
            $text = implode("\n", $segments);
        }

        $out = ['text' => $text];
        if ($doneUsage !== null) {
            $out['usage'] = $doneUsage;
        }
        if ($doneLogprobs !== null) {
            $out['logprobs'] = $doneLogprobs;
        }

        return $out;
    }

    /**
     * @return array{error: array<string, mixed>}
     */
    protected static function transcriptionHttpErrorPayload(Response $response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error'])) {
            return $decoded;
        }

        return [
            'error' => [
                'message' => $body !== '' ? $body : ('HTTP error ' . $response->getStatusCode()),
                'type' => 'invalid_request_error',
                'code' => (string) $response->getStatusCode(),
                'param' => null,
            ],
        ];
    }

    /**
     * @param 'transcriptions'|'translations' $segment
     */
    protected function buildTranscriptionUrl(string $model, string $segment): string
    {
        $path = parse_url($this->api, PHP_URL_PATH);
        if (!$path) {
            return $this->api . ($this->isAzure
                ? "/openai/deployments/$model/audio/$segment?api-version=$this->azureApiVersion"
                : "/v1/audio/$segment");
        }
        if ($path[strlen($path) - 1] === '/') {
            return $this->api . 'audio/' . $segment;
        }

        return $this->api;
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
