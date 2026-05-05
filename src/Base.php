<?php

namespace Webman\Openai;

use Throwable;
use Workerman\Coroutine;
use Workerman\Http\Client;
use Workerman\Http\Response;

class Base
{

    /**
     * @var string api
     */
    protected $api = 'https://api.openai.com';

    /**
     * @var mixed|string apikey
     */
    protected $apikey = '';

    /**
     * @var bool|mixed Azure
     */
    protected $isAzure = false;

    /**
     * @var mixed|string Azure api version
     */
    protected $azureApiVersion = '';

    /**
     * @var mixed|string[] default headers
     */
    protected $defaultHeaders = [
        'Content-Type' => 'application/json'
    ];

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->api = $options['api'] ?? $this->api;
        $this->apikey = $options['apikey'] ?? '';
        $this->isAzure = $options['isAzure'] ?? false;
        $this->azureApiVersion = $options['azureApiVersion'] ?? $this->azureApiVersion;
        $this->defaultHeaders = array_merge($this->defaultHeaders, $options['headers'] ?? []);
    }

    /**
     * Get headers.
     * @param $options
     * @return string[]
     */
    protected function getHeaders($options): array
    {
        $defaultHeaders = array_merge($this->defaultHeaders, $options['headers'] ?? []);
        if ($this->isAzure) {
            $defaultHeaders['api-key'] = $this->apikey;
        } else {
            $defaultHeaders['Authorization'] = 'Bearer ' . $this->apikey;
        }
        return array_merge($defaultHeaders, $options['headers'] ?? []);
    }

    /**
     * Format options.
     *
     * Wraps each user-provided callback in a try/catch so a faulty handler can never
     * propagate into the Workerman event loop. Only keys that are already present in
     * $options are wrapped; missing keys are left untouched.
     *
     * @param array $options
     * @return array
     */
    public function formatOptions(array $options)
    {
        foreach (['complete', 'stream', 'response'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $options[$key] = function (...$args) use ($options, $key) {
                try {
                    if ($options[$key]) {
                        $options[$key](...$args);
                    }
                } catch (Throwable $e) {
                    error_log((string)$e);
                }
            };
        }
        return $options;
    }

    /**
     * Async when $options['stream'] or $options['complete'] is set and not null (isset semantics).
     * Otherwise inject a complete handler, format options, run the request, suspend, and return the payload.
     *
     * Async callback contract (mirrors the sync exception model):
     *  - complete: callable(?array|?string $result, ?OpenAIException $exception, ?Response $response): void
     *      $exception === null  => success, $result is the decoded payload (or audio binary).
     *      $exception !== null  => failure, $result is null. Inspect $exception->statusCode /
     *                              errorCode / errorType / errorParam / raw / response.
     *      $response is the raw HTTP response (headers, status, …); may be a placeholder
     *      Response(0) when the failure is transport-level (no HTTP exchange).
     *      User callbacks may declare 1, 2, or 3 parameters — extra positional args are
     *      silently dropped by PHP, so older 2-param callbacks remain compatible.
     *  - stream:   callable($chunk): void  // only normal data chunks; errors are reported via complete.
     *
     * Sync mode option:
     *  - with_response: bool — when true, returns [$payload, ?Response] instead of just $payload.
     *
     * @param callable(array $formattedOptions): void $sendRequest
     * @return mixed|null null means an async request was dispatched (no return value to caller)
     * @throws OpenAIException when sync mode is used outside a coroutine, or the API returns an error
     */
    protected function syncOrFormatAndSend(array $options, callable $sendRequest): mixed
    {
        if (isset($options['stream']) || isset($options['complete'])) {
            $userComplete = $options['complete'] ?? null;
            $options['complete'] = static function ($result, ?Response $response = null) use ($userComplete): void {
                if (!$userComplete) {
                    return;
                }
                if (is_array($result) && isset($result['error'])) {
                    $userComplete(null, OpenAIException::fromResult($result, $response), $response);
                    return;
                }
                $userComplete($result, null, $response);
            };
            $options += ['stream' => null];
            $sendRequest($this->formatOptions($options));
            return null;
        }
        if (!Coroutine::isCoroutine()) {
            throw new OpenAIException(
                'Synchronous Webman\\Openai calls require a Workerman coroutine context, or pass stream/complete callbacks for async mode.'
            );
        }
        $current = Coroutine::getCurrent();
        $result = null;
        $rawResponse = null;
        $withResponse = !empty($options['with_response']);
        $options += ['stream' => null, 'complete' => null];
        $options['complete'] = static function ($r, ?Response $response = null) use (&$result, &$rawResponse, $current): void {
            $result = $r;
            $rawResponse = $response;
            $current->resume();
        };
        $sendRequest($this->formatOptions($options));
        Coroutine::suspend();
        if (is_array($result) && isset($result['error'])) {
            throw OpenAIException::fromResult($result, $rawResponse);
        }
        return $withResponse ? [$result, $rawResponse] : $result;
    }

    /**
     * Factory for the underlying HTTP client (override in tests).
     */
    protected function createHttpClient(int $timeout): Client
    {
        return new Client(['timeout' => $timeout]);
    }

}