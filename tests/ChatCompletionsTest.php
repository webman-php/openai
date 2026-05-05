<?php

declare(strict_types=1);

namespace Webman\Openai\Tests;

use PHPUnit\Framework\TestCase;
use Webman\Openai\Chat;
use Webman\Openai\OpenAIException;
use Webman\Openai\Tests\Support\ConnectionPoolHelper;
use Webman\Openai\Tests\Support\IntegrationEnv;
use Webman\Openai\Tests\Support\TestableChat;
use Workerman\Coroutine;
use Workerman\Http\Response;
use Workerman\Timer;

final class ChatCompletionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Coroutine::isCoroutine()) {
            $this->markTestSkipped('These tests must run under Workerman (use `php tests/start.php`).');
        }
    }

    private function mockApiBase(): string
    {
        $port = getenv('MOCK_OPENAI_HTTP_PORT') ?: '17171';
        $host = getenv('MOCK_OPENAI_HTTP_LISTEN') ?: '127.0.0.1';

        return 'http://' . $host . ':' . $port;
    }

    private function mockChat(): Chat
    {
        return new Chat([
            'api' => $this->mockApiBase(),
            'apikey' => 'sk-mock-not-used',
        ]);
    }

    /**
     * Wait until predicate is true. Predicate must read live state via reference capture, e.g.
     * `function () use (&$flag) { return $flag; }` — do not use `static fn () => $flag` (by-value capture).
     */
    private function awaitAsync(callable $predicate, string $message, float $timeoutSeconds = 20): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return;
            }
            Timer::sleep(0.05);
        }
        $this->fail($message);
    }

    /** Distinct from “nothing listening” failures: mock closes mid-body read and http-client reports connection closed. */
    private function assertExceptionIndicatesPeerClosed(OpenAIException $e, string $context): void
    {
        $this->assertStringContainsString(
            'closed',
            strtolower($e->getMessage()),
            $context . ' (got: ' . $e->getMessage() . ')'
        );
    }

    private function skipIfDnsTestDisabled(): void
    {
        if (getenv('SKIP_DNS_TEST')) {
            $this->markTestSkipped('SKIP_DNS_TEST set.');
        }
    }

    /** RFC 2606 {@code .invalid} — expect resolution to fail (depends on local resolver behavior). */
    private function dnsFailureChat(): Chat
    {
        return new Chat([
            'api' => 'http://openai-will-not-resolve.invalid',
            'apikey' => 'sk-any',
        ]);
    }

    /** Nothing listening on the port; expect connection refused (ECONNREFUSED). */
    private function connectionRefusedChat(): Chat
    {
        return new Chat([
            'api' => 'http://127.0.0.1:1',
            'apikey' => 'sk-any',
        ]);
    }

    // ——— Mock server (no real OpenAI key) ———

    public function testMockNonStreamSync(): void
    {
        $chat = $this->mockChat();
        $result = $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('chat.completion', $result['object'] ?? null);
        $this->assertStringContainsString('mock-non-stream-reply', (string)($result['choices'][0]['message']['content'] ?? ''));
    }

    public function testMockNonStreamSyncWithResponse(): void
    {
        $chat = $this->mockChat();
        [$result, $response] = $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], ['with_response' => true]);
        $this->assertIsArray($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_nonstream', $response->getHeaderLine('x-mock-request-id'));
    }

    public function testMockNonStreamAsyncComplete(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (mock non-stream)');
        $this->assertIsArray($caught[0]);
        $this->assertSame('chat.completion', $caught[0]['object'] ?? null);
        $this->assertStringContainsString('mock-non-stream-reply', (string)($caught[0]['choices'][0]['message']['content'] ?? ''));
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /**
     * Async mode has no {@code with_response}; on success the third argument is still the full HTTP {@see Response}.
     */
    public function testMockNonStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (mock non-stream + response)');
        $this->assertIsArray($caught[0]);
        $this->assertSame('chat.completion', $caught[0]['object'] ?? null);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_nonstream', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    /**
     * Legacy two-argument {@code complete($result, $response)} is **semantically incompatible** with this library, but PHP
     * **silently drops extra arguments** passed to user-defined functions and does not throw {@see \ArgumentCountError}, so the callback **still runs**.
     * The library invokes {@code ($result, ?OpenAIException, ?Response)}; a two-parameter function only receives the first two—on success the second is {@code null}
     * (exception slot) and the real {@see Response} is third, **unreachable**; on failure the second is {@see OpenAIException}, again not the old “second arg is Response” contract.
     */
    public function testMockNonStreamAsyncLegacyTwoParamCompleteBindsOnlyFirstTwoArgs(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function ($result, $second) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $second];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'legacy 2-param complete not invoked');
        $this->assertIsArray($caught[0]);
        $this->assertStringContainsString('mock-non-stream-reply', (string)($caught[0]['choices'][0]['message']['content'] ?? ''));
        $this->assertNull($caught[1], 'success path: second positional arg is the exception slot (null), not HTTP Response');
    }

    public function testMockStreamSync(): void
    {
        $chat = $this->mockChat();
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
        $contents = [];
        foreach ($gen as $chunk) {
            $this->assertIsArray($chunk);
            $contents[] = $chunk;
        }
        $this->assertNotEmpty($contents);
        $last = $contents[array_key_last($contents)];
        $this->assertArrayHasKey('choices', $last);
    }

    public function testMockStreamSyncWithResponse(): void
    {
        $chat = $this->mockChat();
        [$gen, $response] = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], ['with_response' => true]);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_stream', $response->getHeaderLine('x-mock-request-id'));
        $n = 0;
        foreach ($gen as $_) {
            $n++;
        }
        $this->assertGreaterThan(0, $n);
    }

    // --- OpenAI API error (non-stream JSON 401 / streaming SSE first-packet error) ---

    public function testOpenaiErrorNonStreamSyncThrows(): void
    {
        $chat = $this->mockChat();
        $this->expectException(OpenAIException::class);
        try {
            $chat->completions([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'http-401'],
            ]);
        } catch (OpenAIException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('invalid_api_key', $e->errorCode);
            throw $e;
        }
    }

    public function testOpenaiErrorNonStreamSyncWithResponseThrows(): void
    {
        $chat = $this->mockChat();
        try {
            $chat->completions([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'http-401'],
                'with_response' => true,
            ]);
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('invalid_api_key', $e->errorCode);
            $this->assertInstanceOf(Response::class, $e->response);
            $this->assertSame(401, $e->response->getStatusCode());
        }
    }

    public function testOpenaiErrorNonStreamAsyncComplete(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (openai error non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame(401, $caught[1]->statusCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /**
     * Async mode has no {@code with_response}; on failure the third argument is still HTTP {@see Response} (401 here).
     */
    public function testOpenaiErrorNonStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (openai error non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('invalid_api_key', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(401, $caught[2]->getStatusCode());
    }

    public function testOpenaiErrorStreamSyncThrows(): void
    {
        $chat = $this->mockChat();
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'stream-open-error'],
        ]);
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertSame('mock_stream_open_error', $e->errorCode);
        }
    }

    public function testOpenaiErrorStreamSyncWithResponseThrows(): void
    {
        $chat = $this->mockChat();
        try {
            [$gen, $response] = $chat->completions([
                'model' => 'gpt-4o-mini',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'stream-open-error'],
                'with_response' => true,
            ]);
        } catch (OpenAIException $e) {
            $this->fail(
                'Expected HTTP 200 headers then stream iterator (is mock HTTP worker listening?): '
                . $e->getMessage()
            );
        }
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_stream_open_error', $response->getHeaderLine('x-mock-request-id'));
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException while reading stream');
        } catch (OpenAIException $e) {
            $this->assertSame('mock_stream_open_error', $e->errorCode);
        }
    }

    public function testOpenaiErrorStreamAsyncComplete(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'stream-open-error'],
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (openai error stream)');
        $this->assertSame(1, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('mock_stream_open_error', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testOpenaiErrorStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'stream-open-error'],
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (openai error stream + response)');
        $this->assertSame(1, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('mock_stream_open_error', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_stream_open_error', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    public function testMockModelNotFoundSyncThrows(): void
    {
        $chat = $this->mockChat();
        $this->expectException(OpenAIException::class);
        try {
            $chat->completions([
                'model' => 'not-a-real-model',
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'http-400-model'],
            ]);
        } catch (OpenAIException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('model_not_found', $e->errorCode);
            throw $e;
        }
    }

    public function testMockStreamAsync(): void
    {
        $chat = $this->mockChat();
        $chunks = [];
        $done = false;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'stream' => function (array $chunk) use (&$chunks) {
                $chunks[] = $chunk;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done) {
                $done = true;
                $this->assertNull($e);
                $this->assertIsArray($result);
                $this->assertInstanceOf(Response::class, $response);
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async stream complete not invoked');
        $this->assertNotEmpty($chunks);
    }

    // --- Mock: server closes mid-body read / mid-stream ---

    public function testDisconnectMidNonStreamSyncThrows(): void
    {
        $chat = $this->mockChat();
        try {
            $chat->completions([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'disconnect-mid-nonstream'],
            ]);
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertExceptionIndicatesPeerClosed($e, 'disconnect mid non-stream sync');
        }
    }

    public function testDisconnectMidNonStreamSyncWithResponseThrows(): void
    {
        $chat = $this->mockChat();
        try {
            $chat->completions([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'disconnect-mid-nonstream'],
                'with_response' => true,
            ]);
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertExceptionIndicatesPeerClosed($e, 'disconnect mid non-stream sync + with_response');
        }
    }

    public function testDisconnectMidStreamSyncThrows(): void
    {
        $chat = $this->mockChat();
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'disconnect-mid-stream'],
        ]);
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertExceptionIndicatesPeerClosed($e, 'disconnect mid stream sync');
        }
    }

    public function testDisconnectMidStreamSyncWithResponseThrows(): void
    {
        $chat = $this->mockChat();
        try {
            [$gen, $response] = $chat->completions([
                'model' => 'gpt-4o-mini',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'disconnect-mid-stream'],
                'with_response' => true,
            ]);
        } catch (OpenAIException $e) {
            $this->fail(
                'Expected HTTP 200 headers then stream iterator (is mock HTTP worker listening?): '
                . $e->getMessage()
            );
        }
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException while reading stream');
        } catch (OpenAIException $e) {
            $this->assertExceptionIndicatesPeerClosed($e, 'disconnect mid stream sync + with_response');
        }
    }

    public function testDisconnectMidNonStreamAsyncComplete(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'disconnect-mid-nonstream'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (disconnect mid non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertExceptionIndicatesPeerClosed($caught[1], 'disconnect mid non-stream async');
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /**
     * Async mode has no {@code with_response} flag; the third argument to {@code complete} is a placeholder {@code Response(0)} on transport failure.
     */
    public function testDisconnectMidNonStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'disconnect-mid-nonstream'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (disconnect mid non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertExceptionIndicatesPeerClosed($caught[1], 'disconnect mid non-stream async + response');
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    public function testDisconnectMidStreamAsyncComplete(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'disconnect-mid-stream'],
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (disconnect mid stream)');
        $this->assertLessThanOrEqual(2, $streamCount, 'mock sends at most one SSE data line before close');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertExceptionIndicatesPeerClosed($caught[1], 'disconnect mid stream async');
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testDisconnectMidStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'disconnect-mid-stream'],
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (disconnect mid stream + response)');
        $this->assertLessThanOrEqual(2, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertExceptionIndicatesPeerClosed($caught[1], 'disconnect mid stream async + response');
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    // --- Mock: SSE error event inside streamed body (HTTP 200) ---

    public function testStreamMidErrorSyncThrows(): void
    {
        $chat = $this->mockChat();
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'stream-mid-error'],
        ]);
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertSame('mock_stream_mid_error', $e->errorCode);
        }
    }

    public function testStreamMidErrorSyncWithResponseThrows(): void
    {
        $chat = $this->mockChat();
        try {
            [$gen, $response] = $chat->completions([
                'model' => 'gpt-4o-mini',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ], [
                'headers' => ['X-Test-Scenario' => 'stream-mid-error'],
                'with_response' => true,
            ]);
        } catch (OpenAIException $e) {
            $this->fail(
                'Expected HTTP 200 headers then stream iterator (is mock HTTP worker listening?): '
                . $e->getMessage()
            );
        }
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_stream_mid_error', $response->getHeaderLine('x-mock-request-id'));
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException while reading stream');
        } catch (OpenAIException $e) {
            $this->assertSame('mock_stream_mid_error', $e->errorCode);
        }
    }

    public function testStreamMidErrorAsyncComplete(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'stream-mid-error'],
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (stream mid error)');
        $this->assertSame(2, $streamCount, 'normal delta + error object');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('mock_stream_mid_error', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /**
     * Async mode has no {@code with_response}; the third argument is the full HTTP response—HTTP 200 even when the error is inside SSE.
     */
    public function testStreamMidErrorAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->mockChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'stream-mid-error'],
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (stream mid error + response)');
        $this->assertSame(2, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('mock_stream_mid_error', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_stream_mid_error', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    /**
     * After headers, the mock keeps the chunked body open; we force-close the pooled TCP connection from the client.
     *
     * Note: do not {@see Assert::assert*} inside {@see Timer} callbacks — a failure becomes an
     * uncaught exception and will tear down the Workerman worker (e.g. exit 64000). Assertions
     * belong after the expected {@see OpenAIException} from the generator.
     */
    public function testReflectClosePooledConnectionDuringHungStream(): void
    {
        $chat = new TestableChat([
            'api' => $this->mockApiBase(),
            'apikey' => 'sk-mock-not-used',
        ]);
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'headers' => ['X-Test-Scenario' => 'reflect-hang'],
        ]);
        $this->assertNotNull($chat->capturedHttpClient);

        $closedTotal = 0;
        $attempts = 0;
        $tryClose = function () use ($chat, &$closedTotal): void {
            try {
                $closedTotal += ConnectionPoolHelper::closeAllPooledConnections($chat->capturedHttpClient);
            } catch (\Throwable) {
            }
        };
        Timer::add(0, $tryClose, null, false);
        $timerId = Timer::repeat(0.02, function () use ($tryClose, &$closedTotal, &$attempts, &$timerId) {
            $tryClose();
            $attempts++;
            if ($closedTotal > 0 || $attempts >= 150) {
                Timer::del($timerId);
            }
        });

        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException after forcing connection close');
        } catch (OpenAIException) {
            $this->assertGreaterThan(
                0,
                $closedTotal,
                'expected to force-close at least one pooled connection (using or idle)'
            );
        }
    }

    // ——— Transport / DNS (no mock server) ———

    public function testDnsFailureNonStreamSyncThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $this->expectException(OpenAIException::class);
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
    }

    public function testDnsFailureNonStreamSyncWithResponseThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $this->expectException(OpenAIException::class);
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], ['with_response' => true]);
    }

    public function testDnsFailureStreamSyncThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
        $this->expectException(OpenAIException::class);
        foreach ($gen as $_) {
        }
    }

    public function testDnsFailureStreamSyncWithResponseThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $this->expectException(OpenAIException::class);
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], ['with_response' => true]);
    }

    public function testDnsFailureNonStreamAsyncComplete(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (dns non-stream)');
        $this->assertIsArray($caught);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /**
     * Async mode has no {@code with_response} flag; the third argument to {@code complete} is the underlying HTTP {@see Response} (placeholder {@code Response(0)} on transport failure).
     */
    public function testDnsFailureNonStreamAsyncCompleteIncludesResponse(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (dns non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    public function testDnsFailureStreamAsyncComplete(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (dns stream)');
        $this->assertSame(0, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testDnsFailureStreamAsyncCompleteIncludesResponse(): void
    {
        $this->skipIfDnsTestDisabled();
        $chat = $this->dnsFailureChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (dns stream + response)');
        $this->assertSame(0, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    // ——— Transport / connection refused (127.0.0.1:1, no listener) ———

    public function testConnectionRefusedNonStreamSyncThrows(): void
    {
        $chat = $this->connectionRefusedChat();
        $this->expectException(OpenAIException::class);
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
    }

    public function testConnectionRefusedNonStreamSyncWithResponseThrows(): void
    {
        $chat = $this->connectionRefusedChat();
        $this->expectException(OpenAIException::class);
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], ['with_response' => true]);
    }

    public function testConnectionRefusedStreamSyncThrows(): void
    {
        $chat = $this->connectionRefusedChat();
        $gen = $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
        $this->expectException(OpenAIException::class);
        foreach ($gen as $_) {
        }
    }

    public function testConnectionRefusedStreamSyncWithResponseThrows(): void
    {
        $chat = $this->connectionRefusedChat();
        $this->expectException(OpenAIException::class);
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], ['with_response' => true]);
    }

    public function testConnectionRefusedNonStreamAsyncComplete(): void
    {
        $chat = $this->connectionRefusedChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (connection refused non-stream)');
        $this->assertIsArray($caught);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /**
     * Async mode has no {@code with_response} flag; the third argument to {@code complete} is the underlying HTTP {@see Response} (placeholder {@code Response(0)} on transport failure).
     */
    public function testConnectionRefusedNonStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->connectionRefusedChat();
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (connection refused non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    public function testConnectionRefusedStreamAsyncComplete(): void
    {
        $chat = $this->connectionRefusedChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (connection refused stream)');
        $this->assertSame(0, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testConnectionRefusedStreamAsyncCompleteIncludesResponse(): void
    {
        $chat = $this->connectionRefusedChat();
        $done = false;
        $caught = null;
        $streamCount = 0;
        $chat->completions([
            'model' => 'gpt-4o-mini',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], [
            'stream' => function (array $_chunk) use (&$streamCount) {
                $streamCount++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught) {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (connection refused stream + response)');
        $this->assertSame(0, $streamCount);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    // --- Live API (export OPENAI_API_KEY; optional OPENAI_API_BASE, OPENAI_CHAT_MODEL) ---

    /** @group live */
    public function testLiveNonStreamSync(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $result = $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'Say the word ok and nothing else.']],
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('choices', $result);
        $this->assertNotEmpty($result['choices'][0]['message']['content'] ?? null);
    }

    /** @group live */
    public function testLiveNonStreamWithResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        [$result, $response] = $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'Reply with ok.']],
        ], ['with_response' => true]);
        $this->assertIsArray($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    /** @group live */
    public function testLiveStreamSync(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $gen = $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 16,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Count from 1 to 3 separated by commas.']],
        ]);
        $count = 0;
        foreach ($gen as $chunk) {
            $this->assertIsArray($chunk);
            $count++;
            if ($count > 500) {
                break;
            }
        }
        $this->assertGreaterThan(0, $count);
    }

    /** @group live */
    public function testLiveStreamSyncWithResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        [$gen, $response] = $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 16,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Count from 1 to 3 separated by commas.']],
        ], ['with_response' => true]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('text/event-stream', $response->getHeaderLine('content-type'));
        $count = 0;
        foreach ($gen as $chunk) {
            $this->assertIsArray($chunk);
            $count++;
            if ($count > 500) {
                break;
            }
        }
        $this->assertGreaterThan(0, $count);
    }

    /** @group live */
    public function testLiveNonStreamAsyncComplete(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ok']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live async complete not invoked (chat non-stream)');
        $this->assertIsArray($caught[0]);
        $this->assertArrayHasKey('choices', $caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /** @group live */
    public function testLiveNonStreamAsyncCompleteIncludesResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'Reply with ok.']],
        ], [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live async complete not invoked (chat non-stream + response check)');
        $this->assertIsArray($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
    }

    /** @group live */
    public function testLiveStreamAsyncComplete(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $n = 0;
        $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 16,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Say 1 then 2 then 3.']],
        ], [
            'stream' => function (array $_chunk) use (&$n): void {
                $n++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live async complete not invoked (chat stream)');
        $this->assertGreaterThan(0, $n);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /** @group live */
    public function testLiveStreamAsyncCompleteIncludesResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $n = 0;
        $chat->completions([
            'model' => IntegrationEnv::chatModel(),
            'max_tokens' => 16,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Say 1 then 2 then 3.']],
        ], [
            'stream' => function (array $_chunk) use (&$n): void {
                $n++;
            },
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live async complete not invoked (chat stream + response)');
        $this->assertGreaterThan(0, $n);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
    }

    /** @group live */
    public function testLiveInvalidModelSyncThrows(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat(IntegrationEnv::liveHttpClientConfig());
        $this->expectException(OpenAIException::class);
        try {
            $chat->completions([
                'model' => 'gpt-4o-mini-model-that-does-not-exist-zzzz',
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ]);
        } catch (OpenAIException $e) {
            $this->assertNotSame(0, $e->statusCode);
            throw $e;
        }
    }

    /** @group live */
    public function testLiveInvalidKeySyncThrows(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $chat = new Chat([
            'api' => IntegrationEnv::apiBase(),
            'apikey' => 'sk-invalid-on-purpose-for-test',
        ]);
        $this->expectException(OpenAIException::class);
        try {
            $chat->completions([
                'model' => IntegrationEnv::chatModel(),
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ]);
        } catch (OpenAIException $e) {
            $this->assertContains($e->statusCode, [401, 403]);
            throw $e;
        }
    }
}
