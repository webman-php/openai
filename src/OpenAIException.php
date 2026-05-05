<?php

namespace Webman\Openai;

use RuntimeException;
use Throwable;
use Workerman\Http\Response;

/**
 * Carries the full OpenAI error context (similar in spirit to the JS/Python SDKs' APIError).
 *
 * Inspect $statusCode / $errorCode / $errorType / $errorParam to make precise decisions
 * (retry on rate limit, surface validation errors, fall back on transport failures, …).
 * $raw and $response retain the full payload for logging or fallback handling.
 */
class OpenAIException extends RuntimeException
{
    /** Library-defined or API-returned error code. */
    public string $errorCode = 'exception';

    /** API-returned error type (e.g. "invalid_request_error"). */
    public ?string $errorType = null;

    /** API-returned error param (e.g. "model"). */
    public ?string $errorParam = null;

    /** HTTP status code. 0 means transport-level failure (no HTTP response). */
    public int $statusCode = 0;

    /** Full decoded response payload. */
    public ?array $raw = null;

    /** Original Workerman HTTP response. */
    public ?Response $response = null;

    /**
     * Build an exception from the conventional [error => [...]] payload + optional response.
     */
    public static function fromResult(array $result, ?Response $response = null, ?string $fallbackMessage = null, ?Throwable $previous = null): self
    {
        $error = $result['error'] ?? [];
        $message = $error['message'] ?? $fallbackMessage ?? 'OpenAI request failed';
        $exception = new self((string)$message, 0, $previous);
        $exception->errorCode = (string)($error['code'] ?? 'exception');
        $exception->errorType = isset($error['type']) ? (string)$error['type'] : null;
        $exception->errorParam = isset($error['param']) ? (string)$error['param'] : null;
        $exception->statusCode = $response ? $response->getStatusCode() : 0;
        $exception->raw = $result;
        $exception->response = $response;
        return $exception;
    }
}
