<?php

declare(strict_types=1);

namespace Webman\Openai\Tests;

use PHPUnit\Framework\TestCase;
use Webman\Openai\Embedding;
use Webman\Openai\OpenAIException;
use Webman\Openai\Tests\Support\IntegrationEnv;
use Workerman\Coroutine;
use Workerman\Http\Response;
use Workerman\Timer;

/**
 * {@see Embedding::create} — mock success, DNS failure, OpenAI JSON 401.
 */
final class EmbeddingCreateTest extends TestCase
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

    private function mockEmbedding(): Embedding
    {
        return new Embedding([
            'api' => $this->mockApiBase(),
            'apikey' => 'sk-mock-not-used',
        ]);
    }

    private function dnsFailureEmbedding(): Embedding
    {
        return new Embedding([
            'api' => 'http://openai-will-not-resolve.invalid',
            'apikey' => 'sk-any',
        ]);
    }

    /** @return array{model: string, input: string} */
    private function embeddingPayload(): array
    {
        return [
            'model' => 'text-embedding-3-small',
            'input' => 'hello',
        ];
    }

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

    private function skipIfDnsTestDisabled(): void
    {
        if (getenv('SKIP_DNS_TEST')) {
            $this->markTestSkipped('SKIP_DNS_TEST set.');
        }
    }

    private function assertMockEmbeddingResult(array $result): void
    {
        $this->assertArrayHasKey('data', $result);
        $this->assertSame([0.1, 0.2, 0.3], $result['data'][0]['embedding'] ?? null);
        $this->assertSame('text-embedding-3-small', $result['model'] ?? null);
    }

    // --- Mock: success ---

    public function testEmbeddingMockNonStreamSync(): void
    {
        $emb = $this->mockEmbedding();
        $result = $emb->create($this->embeddingPayload());
        $this->assertMockEmbeddingResult($result);
    }

    public function testEmbeddingMockNonStreamSyncWithResponse(): void
    {
        $emb = $this->mockEmbedding();
        [$result, $response] = $emb->create($this->embeddingPayload(), ['with_response' => true]);
        $this->assertMockEmbeddingResult($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_embeddings', $response->getHeaderLine('x-mock-request-id'));
    }

    public function testEmbeddingMockNonStreamAsyncComplete(): void
    {
        $emb = $this->mockEmbedding();
        $done = false;
        $caught = null;
        $emb->create($this->embeddingPayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (embedding non-stream)');
        $this->assertIsArray($caught[0]);
        $this->assertMockEmbeddingResult($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testEmbeddingMockNonStreamAsyncCompleteIncludesResponse(): void
    {
        $emb = $this->mockEmbedding();
        $done = false;
        $caught = null;
        $emb->create($this->embeddingPayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (embedding non-stream + response)');
        $this->assertIsArray($caught[0]);
        $this->assertMockEmbeddingResult($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_embeddings', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    // ——— DNS ———

    public function testEmbeddingDnsFailureNonStreamSyncThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $emb = $this->dnsFailureEmbedding();
        $this->expectException(OpenAIException::class);
        $emb->create($this->embeddingPayload());
    }

    public function testEmbeddingDnsFailureNonStreamSyncWithResponseThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $emb = $this->dnsFailureEmbedding();
        $this->expectException(OpenAIException::class);
        $emb->create($this->embeddingPayload(), ['with_response' => true]);
    }

    public function testEmbeddingDnsFailureNonStreamAsyncComplete(): void
    {
        $this->skipIfDnsTestDisabled();
        $emb = $this->dnsFailureEmbedding();
        $done = false;
        $caught = null;
        $emb->create($this->embeddingPayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (embedding dns non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testEmbeddingDnsFailureNonStreamAsyncCompleteIncludesResponse(): void
    {
        $this->skipIfDnsTestDisabled();
        $emb = $this->dnsFailureEmbedding();
        $done = false;
        $caught = null;
        $emb->create($this->embeddingPayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (embedding dns non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    // --- OpenAI returns 401 JSON ---

    public function testEmbeddingOpenaiErrorNonStreamSyncThrows(): void
    {
        $emb = $this->mockEmbedding();
        $this->expectException(OpenAIException::class);
        try {
            $emb->create($this->embeddingPayload(), [
                'headers' => ['X-Test-Scenario' => 'http-401'],
            ]);
        } catch (OpenAIException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('invalid_api_key', $e->errorCode);
            throw $e;
        }
    }

    public function testEmbeddingOpenaiErrorNonStreamSyncWithResponseThrows(): void
    {
        $emb = $this->mockEmbedding();
        try {
            $emb->create($this->embeddingPayload(), [
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

    public function testEmbeddingOpenaiErrorNonStreamAsyncComplete(): void
    {
        $emb = $this->mockEmbedding();
        $done = false;
        $caught = null;
        $emb->create($this->embeddingPayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (embedding openai error non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame(401, $caught[1]->statusCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testEmbeddingOpenaiErrorNonStreamAsyncCompleteIncludesResponse(): void
    {
        $emb = $this->mockEmbedding();
        $done = false;
        $caught = null;
        $emb->create($this->embeddingPayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (embedding openai error non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('invalid_api_key', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(401, $caught[2]->getStatusCode());
    }

    // --- Live API (requires OPENAI_API_KEY; optional OPENAI_EMBEDDING_MODEL, OPENAI_API_BASE) ---

    private function assertLiveEmbeddingShape(array $result): void
    {
        $this->assertArrayHasKey('data', $result);
        $vec = $result['data'][0]['embedding'] ?? null;
        $this->assertIsArray($vec);
        $this->assertGreaterThan(0, count($vec));
        $this->assertTrue(is_numeric($vec[0]));
    }

    /** @group live */
    public function testEmbeddingLiveNonStreamSync(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $emb = new Embedding(IntegrationEnv::liveHttpClientConfig());
        $result = $emb->create(IntegrationEnv::embeddingCreatePayload());
        $this->assertLiveEmbeddingShape($result);
    }

    /** @group live */
    public function testEmbeddingLiveNonStreamSyncWithResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $emb = new Embedding(IntegrationEnv::liveHttpClientConfig());
        [$result, $response] = $emb->create(IntegrationEnv::embeddingCreatePayload(), ['with_response' => true]);
        $this->assertLiveEmbeddingShape($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    /** @group live */
    public function testEmbeddingLiveNonStreamAsyncComplete(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $emb = new Embedding(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $emb->create(IntegrationEnv::embeddingCreatePayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live embedding async complete');
        $this->assertIsArray($caught[0]);
        $this->assertLiveEmbeddingShape($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /** @group live */
    public function testEmbeddingLiveNonStreamAsyncCompleteIncludesResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $emb = new Embedding(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $emb->create(IntegrationEnv::embeddingCreatePayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live embedding async + response');
        $this->assertIsArray($caught[0]);
        $this->assertLiveEmbeddingShape($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
    }
}
