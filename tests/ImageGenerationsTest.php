<?php

declare(strict_types=1);

namespace Webman\Openai\Tests;

use PHPUnit\Framework\TestCase;
use Webman\Openai\Image;
use Webman\Openai\OpenAIException;
use Webman\Openai\Tests\Support\IntegrationEnv;
use Workerman\Coroutine;
use Workerman\Http\Response;
use Workerman\Timer;

/**
 * {@see Image::generations} — mock success, DNS failure, OpenAI JSON 401.
 */
final class ImageGenerationsTest extends TestCase
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

    private function mockImage(): Image
    {
        return new Image([
            'api' => $this->mockApiBase(),
            'apikey' => 'sk-mock-not-used',
        ]);
    }

    private function dnsFailureImage(): Image
    {
        return new Image([
            'api' => 'http://openai-will-not-resolve.invalid',
            'apikey' => 'sk-any',
        ]);
    }

    /**
     * Non-GPT-Image path ({@code dall-e-3}); sizes must match the Image API for that model.
     *
     * @return array{model: string, prompt: string, n: int, size: string}
     */
    private function imagePayload(): array
    {
        return [
            'model' => 'dall-e-3',
            'prompt' => 'a test image',
            'n' => 1,
            'size' => '1024x1024',
        ];
    }

    /**
     * {@code gpt-image-2}: docs require total pixels ∈ [655360, 8294400], both sides multiples of 16, long:short ≤ 3:1.
     * {@code 640x1024} sits at the lower bound; live tall images often cost less output than 1024². Mock still only validates JSON shape.
     *
     * @return array{model: string, prompt: string, n: int, size: string}
     */
    private function imagePayloadGptImage2(): array
    {
        return [
            'model' => 'gpt-image-2',
            'prompt' => 'a test image for gpt-image-2',
            'n' => 1,
            'size' => '640x1024',
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

    private function assertMockImageResult(array $result): void
    {
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('https://mock.openai.test/generated.png', $result['data'][0]['url'] ?? null);
        $this->assertSame('mock-revised-prompt', $result['data'][0]['revised_prompt'] ?? null);
    }

    /** GPT Image (e.g. gpt-image-2): {@code data[0].b64_json} is PNG base64; no {@code url}. */
    private function assertMockGptImage2Result(array $result): void
    {
        $this->assertArrayHasKey('data', $result);
        $row = $result['data'][0] ?? null;
        $this->assertIsArray($row);
        $this->assertArrayHasKey('b64_json', $row);
        $this->assertIsString($row['b64_json']);
        $this->assertArrayNotHasKey('url', $row);
        $bin = base64_decode($row['b64_json'], true);
        $this->assertNotFalse($bin, 'b64_json must be valid base64');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $bin, 'GPT Image b64_json decodes to PNG');
    }

    // --- Mock: success ---

    public function testImageMockNonStreamSync(): void
    {
        $image = $this->mockImage();
        $result = $image->generations($this->imagePayload());
        $this->assertMockImageResult($result);
    }

    /** Mock: request uses {@code gpt-image-2}; response matches official GPT Image ({@code data[0].b64_json}, no url). */
    public function testImageMockGptImage2NonStreamSync(): void
    {
        $image = $this->mockImage();
        $result = $image->generations($this->imagePayloadGptImage2());
        $this->assertMockGptImage2Result($result);
    }

    public function testImageMockGptImage2NonStreamSyncWithResponse(): void
    {
        $image = $this->mockImage();
        [$result, $response] = $image->generations($this->imagePayloadGptImage2(), ['with_response' => true]);
        $this->assertMockGptImage2Result($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_image_gpt_image', $response->getHeaderLine('x-mock-request-id'));
    }

    public function testImageMockNonStreamSyncWithResponse(): void
    {
        $image = $this->mockImage();
        [$result, $response] = $image->generations($this->imagePayload(), ['with_response' => true]);
        $this->assertMockImageResult($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_image_generations', $response->getHeaderLine('x-mock-request-id'));
    }

    public function testImageMockNonStreamAsyncComplete(): void
    {
        $image = $this->mockImage();
        $done = false;
        $caught = null;
        $image->generations($this->imagePayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (image non-stream)');
        $this->assertIsArray($caught[0]);
        $this->assertMockImageResult($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testImageMockNonStreamAsyncCompleteIncludesResponse(): void
    {
        $image = $this->mockImage();
        $done = false;
        $caught = null;
        $image->generations($this->imagePayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (image non-stream + response)');
        $this->assertIsArray($caught[0]);
        $this->assertMockImageResult($caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_image_generations', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    // ——— DNS ———

    public function testImageDnsFailureNonStreamSyncThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $image = $this->dnsFailureImage();
        $this->expectException(OpenAIException::class);
        $image->generations($this->imagePayload());
    }

    public function testImageDnsFailureNonStreamSyncWithResponseThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $image = $this->dnsFailureImage();
        $this->expectException(OpenAIException::class);
        $image->generations($this->imagePayload(), ['with_response' => true]);
    }

    public function testImageDnsFailureNonStreamAsyncComplete(): void
    {
        $this->skipIfDnsTestDisabled();
        $image = $this->dnsFailureImage();
        $done = false;
        $caught = null;
        $image->generations($this->imagePayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (image dns non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testImageDnsFailureNonStreamAsyncCompleteIncludesResponse(): void
    {
        $this->skipIfDnsTestDisabled();
        $image = $this->dnsFailureImage();
        $done = false;
        $caught = null;
        $image->generations($this->imagePayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (image dns non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    // --- OpenAI returns 401 JSON ---

    public function testImageOpenaiErrorNonStreamSyncThrows(): void
    {
        $image = $this->mockImage();
        $this->expectException(OpenAIException::class);
        try {
            $image->generations($this->imagePayload(), [
                'headers' => ['X-Test-Scenario' => 'http-401'],
            ]);
        } catch (OpenAIException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('invalid_api_key', $e->errorCode);
            throw $e;
        }
    }

    public function testImageOpenaiErrorNonStreamSyncWithResponseThrows(): void
    {
        $image = $this->mockImage();
        try {
            $image->generations($this->imagePayload(), [
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

    public function testImageOpenaiErrorNonStreamAsyncComplete(): void
    {
        $image = $this->mockImage();
        $done = false;
        $caught = null;
        $image->generations($this->imagePayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (image openai error non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame(401, $caught[1]->statusCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testImageOpenaiErrorNonStreamAsyncCompleteIncludesResponse(): void
    {
        $image = $this->mockImage();
        $done = false;
        $caught = null;
        $image->generations($this->imagePayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (image openai error non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('invalid_api_key', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(401, $caught[2]->getStatusCode());
    }

    // --- Live API (requires OPENAI_API_KEY; optional OPENAI_IMAGE_MODEL, OPENAI_IMAGE_SIZE, OPENAI_API_BASE) ---

    private function assertLiveImageHasAsset(array $result): void
    {
        $this->assertArrayHasKey('data', $result);
        $row = $result['data'][0] ?? null;
        $this->assertIsArray($row);
        $hasUrl = isset($row['url']) && $row['url'] !== '';
        $hasB64 = isset($row['b64_json']) && $row['b64_json'] !== '';
        $this->assertTrue($hasUrl || $hasB64, 'expected url or b64_json in image response');
    }

    /** @group live */
    public function testImageLiveNonStreamSync(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $image = new Image(IntegrationEnv::liveHttpClientConfig());
        $result = $image->generations(IntegrationEnv::imageGenerationsPayload());
        $this->assertLiveImageHasAsset($result);
    }

    /** @group live */
    public function testImageLiveNonStreamSyncWithResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $image = new Image(IntegrationEnv::liveHttpClientConfig());
        [$result, $response] = $image->generations(IntegrationEnv::imageGenerationsPayload(), ['with_response' => true]);
        $this->assertLiveImageHasAsset($result);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    /** @group live */
    public function testImageLiveNonStreamAsyncComplete(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $image = new Image(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $image->generations(IntegrationEnv::imageGenerationsPayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live image async complete', 180.0);
        $this->assertNull(
            $caught[1],
            $caught[1] instanceof OpenAIException
                ? 'Live image upstream error (set OPENAI_IMAGE_MODEL / OPENAI_IMAGE_SIZE for your OPENAI_API_BASE): ' . $caught[1]->getMessage()
                : ''
        );
        $this->assertIsArray($caught[0]);
        $this->assertLiveImageHasAsset($caught[0]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /** @group live */
    public function testImageLiveNonStreamAsyncCompleteIncludesResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $image = new Image(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $image->generations(IntegrationEnv::imageGenerationsPayload(), [
            'complete' => function (?array $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live image async + response', 180.0);
        $this->assertNull(
            $caught[1],
            $caught[1] instanceof OpenAIException
                ? 'Live image upstream error (set OPENAI_IMAGE_MODEL / OPENAI_IMAGE_SIZE for your OPENAI_API_BASE): ' . $caught[1]->getMessage()
                : ''
        );
        $this->assertIsArray($caught[0]);
        $this->assertLiveImageHasAsset($caught[0]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
    }
}
