<?php

declare(strict_types=1);

namespace Webman\Openai\Tests;

use PHPUnit\Framework\TestCase;
use Webman\Openai\Audio;
use Webman\Openai\OpenAIException;
use Webman\Openai\Tests\Support\IntegrationEnv;
use Workerman\Coroutine;
use Workerman\Http\Response;
use Workerman\Timer;

/**
 * {@see Audio::speech} — mock success, DNS failure, OpenAI JSON 401.
 */
final class AudioSpeechTest extends TestCase
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

    private function mockAudio(): Audio
    {
        return new Audio([
            'api' => $this->mockApiBase(),
            'apikey' => 'sk-mock-not-used',
        ]);
    }

    private function dnsFailureAudio(): Audio
    {
        return new Audio([
            'api' => 'http://openai-will-not-resolve.invalid',
            'apikey' => 'sk-any',
        ]);
    }

    /** @return array{model: string, input: string, voice: string, stream?: true} */
    private function speechPayload(bool $streaming = false): array
    {
        $p = [
            'model' => 'tts-1',
            'input' => 'hello',
            'voice' => 'alloy',
        ];
        if ($streaming) {
            $p['stream'] = true;
        }

        return $p;
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

    // --- Mock: success ---

    public function testSpeechMockNonStreamSync(): void
    {
        $audio = $this->mockAudio();
        $bin = $audio->speech($this->speechPayload());
        $this->assertSame('MOCK_TTS_SINGLE', $bin);
    }

    public function testSpeechMockNonStreamSyncWithResponse(): void
    {
        $audio = $this->mockAudio();
        [$bin, $response] = $audio->speech($this->speechPayload(), ['with_response' => true]);
        $this->assertSame('MOCK_TTS_SINGLE', $bin);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_speech', $response->getHeaderLine('x-mock-request-id'));
    }

    public function testSpeechMockStreamSyncGenerator(): void
    {
        $audio = $this->mockAudio();
        $gen = $audio->speech($this->speechPayload(true), [
            'headers' => ['X-Test-Scenario' => 'speech-chunked'],
        ]);
        $joined = '';
        foreach ($gen as $chunk) {
            $joined .= $chunk;
        }
        $this->assertSame('MOCK_TTS_CHUNK', $joined);
    }

    public function testSpeechMockStreamSyncGeneratorWithResponse(): void
    {
        $audio = $this->mockAudio();
        try {
            [$gen, $response] = $audio->speech($this->speechPayload(true), [
                'with_response' => true,
                'headers' => ['X-Test-Scenario' => 'speech-chunked'],
            ]);
        } catch (OpenAIException $e) {
            $this->fail('Expected HTTP 200 headers then generator: ' . $e->getMessage());
        }
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_speech_chunked', $response->getHeaderLine('x-mock-request-id'));
        $joined = '';
        foreach ($gen as $chunk) {
            $joined .= $chunk;
        }
        $this->assertSame('MOCK_TTS_CHUNK', $joined);
    }

    public function testSpeechMockNonStreamAsyncComplete(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech non-stream)');
        $this->assertSame('MOCK_TTS_SINGLE', $caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testSpeechMockNonStreamAsyncCompleteIncludesResponse(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech non-stream + response)');
        $this->assertSame('MOCK_TTS_SINGLE', $caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_speech', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    public function testSpeechMockStreamAsyncComplete(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $parts = [];
        $audio->speech($this->speechPayload(), [
            'headers' => ['X-Test-Scenario' => 'speech-chunked'],
            'stream' => function (string $buffer) use (&$parts): void {
                $parts[] = $buffer;
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech stream)');
        $this->assertSame('MOCK_TTS_CHUNK', implode('', $parts));
        $this->assertSame('MOCK_TTS_CHUNK', $caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testSpeechMockStreamAsyncCompleteIncludesResponse(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $parts = [];
        $audio->speech($this->speechPayload(), [
            'headers' => ['X-Test-Scenario' => 'speech-chunked'],
            'stream' => function (string $buffer) use (&$parts): void {
                $parts[] = $buffer;
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech stream + response)');
        $this->assertSame('MOCK_TTS_CHUNK', implode('', $parts));
        $this->assertSame('MOCK_TTS_CHUNK', $caught[0]);
        $this->assertNull($caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
        $this->assertSame('req_mock_speech_chunked', $caught[2]->getHeaderLine('x-mock-request-id'));
    }

    // ——— DNS ———

    public function testSpeechDnsFailureNonStreamSyncThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $this->expectException(OpenAIException::class);
        $audio->speech($this->speechPayload());
    }

    public function testSpeechDnsFailureNonStreamSyncWithResponseThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $this->expectException(OpenAIException::class);
        $audio->speech($this->speechPayload(), ['with_response' => true]);
    }

    public function testSpeechDnsFailureStreamSyncGeneratorThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $gen = $audio->speech($this->speechPayload(true));
        $this->expectException(OpenAIException::class);
        foreach ($gen as $_) {
        }
    }

    public function testSpeechDnsFailureStreamSyncGeneratorWithResponseThrows(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $this->expectException(OpenAIException::class);
        $audio->speech($this->speechPayload(true), [
            'with_response' => true,
        ]);
    }

    public function testSpeechDnsFailureNonStreamAsyncComplete(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech dns non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testSpeechDnsFailureNonStreamAsyncCompleteIncludesResponse(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech dns non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    public function testSpeechDnsFailureStreamAsyncComplete(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $done = false;
        $caught = null;
        $n = 0;
        $audio->speech($this->speechPayload(), [
            'stream' => function (string $_b) use (&$n): void {
                $n++;
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech dns stream)');
        $this->assertSame(0, $n);
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testSpeechDnsFailureStreamAsyncCompleteIncludesResponse(): void
    {
        $this->skipIfDnsTestDisabled();
        $audio = $this->dnsFailureAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'stream' => function (string $_b): void {
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech dns stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(0, $caught[2]->getStatusCode());
    }

    // --- OpenAI returns 401 JSON ---

    public function testSpeechOpenaiErrorNonStreamSyncThrows(): void
    {
        $audio = $this->mockAudio();
        $this->expectException(OpenAIException::class);
        try {
            $audio->speech($this->speechPayload(), [
                'headers' => ['X-Test-Scenario' => 'http-401'],
            ]);
        } catch (OpenAIException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('invalid_api_key', $e->errorCode);
            throw $e;
        }
    }

    public function testSpeechOpenaiErrorNonStreamSyncWithResponseThrows(): void
    {
        $audio = $this->mockAudio();
        try {
            $audio->speech($this->speechPayload(), [
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

    public function testSpeechOpenaiErrorNonStreamAsyncComplete(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech openai error non-stream)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame(401, $caught[1]->statusCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testSpeechOpenaiErrorNonStreamAsyncCompleteIncludesResponse(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech openai error non-stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('invalid_api_key', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(401, $caught[2]->getStatusCode());
    }

    public function testSpeechOpenaiErrorStreamSyncGeneratorThrows(): void
    {
        $audio = $this->mockAudio();
        $gen = $audio->speech($this->speechPayload(true), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
        ]);
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException');
        } catch (OpenAIException $e) {
            $this->assertSame('invalid_api_key', $e->errorCode);
        }
    }

    public function testSpeechOpenaiErrorStreamSyncGeneratorWithResponseThrows(): void
    {
        $audio = $this->mockAudio();
        try {
            [$gen, $response] = $audio->speech($this->speechPayload(true), [
                'with_response' => true,
                'headers' => ['X-Test-Scenario' => 'http-401'],
            ]);
        } catch (OpenAIException $e) {
            $this->fail('Expected HTTP 401 response then generator failure: ' . $e->getMessage());
        }
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        try {
            foreach ($gen as $_) {
            }
            $this->fail('Expected OpenAIException while consuming stream');
        } catch (OpenAIException $e) {
            $this->assertSame('invalid_api_key', $e->errorCode);
        }
    }

    public function testSpeechOpenaiErrorStreamAsyncComplete(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $n = 0;
        $audio->speech($this->speechPayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'stream' => function (string $_b) use (&$n): void {
                $n++;
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech openai error stream)');
        $this->assertLessThanOrEqual(2, $n, 'progress may deliver error JSON body in one or more chunks');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('invalid_api_key', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    public function testSpeechOpenaiErrorStreamAsyncCompleteIncludesResponse(): void
    {
        $audio = $this->mockAudio();
        $done = false;
        $caught = null;
        $audio->speech($this->speechPayload(), [
            'headers' => ['X-Test-Scenario' => 'http-401'],
            'stream' => function (string $_b): void {
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'async complete not invoked (speech openai error stream + response)');
        $this->assertNull($caught[0]);
        $this->assertInstanceOf(OpenAIException::class, $caught[1]);
        $this->assertSame('invalid_api_key', $caught[1]->errorCode);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(401, $caught[2]->getStatusCode());
    }

    // --- Live API (requires OPENAI_API_KEY; optional OPENAI_TTS_MODEL, OPENAI_TTS_VOICE, OPENAI_API_BASE) ---

    /** @group live */
    public function testSpeechLiveNonStreamSync(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        $bin = $audio->speech(IntegrationEnv::speechPayloadNonStream());
        $this->assertIsString($bin);
        $this->assertGreaterThan(100, strlen($bin));
    }

    /** @group live */
    public function testSpeechLiveNonStreamSyncWithResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        [$bin, $response] = $audio->speech(IntegrationEnv::speechPayloadNonStream(), ['with_response' => true]);
        $this->assertIsString($bin);
        $this->assertGreaterThan(100, strlen($bin));
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    /** @group live */
    public function testSpeechLiveStreamSyncGenerator(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        $gen = $audio->speech(IntegrationEnv::speechPayloadStream());
        $total = 0;
        foreach ($gen as $chunk) {
            $total += strlen((string) $chunk);
            if ($total > 500000) {
                break;
            }
        }
        $this->assertGreaterThan(0, $total);
    }

    /** @group live */
    public function testSpeechLiveStreamSyncGeneratorWithResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        [$gen, $response] = $audio->speech(IntegrationEnv::speechPayloadStream(), ['with_response' => true]);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $total = 0;
        foreach ($gen as $chunk) {
            $total += strlen((string) $chunk);
            if ($total > 500000) {
                break;
            }
        }
        $this->assertGreaterThan(0, $total);
    }

    /** @group live */
    public function testSpeechLiveNonStreamAsyncComplete(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $audio->speech(IntegrationEnv::speechPayloadNonStream(), [
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live speech async non-stream');
        $this->assertNull(
            $caught[1],
            $caught[1] instanceof OpenAIException
                ? 'Live TTS upstream error (set OPENAI_TTS_MODEL / voice for your OPENAI_API_BASE): ' . $caught[1]->getMessage()
                : ''
        );
        $this->assertIsString($caught[0]);
        $this->assertGreaterThan(100, strlen($caught[0]));
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /** @group live */
    public function testSpeechLiveNonStreamAsyncCompleteIncludesResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $audio->speech(IntegrationEnv::speechPayloadNonStream(), [
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live speech async non-stream + response');
        $this->assertNull(
            $caught[1],
            $caught[1] instanceof OpenAIException
                ? 'Live TTS upstream error (set OPENAI_TTS_MODEL / voice for your OPENAI_API_BASE): ' . $caught[1]->getMessage()
                : ''
        );
        $this->assertIsString($caught[0]);
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
    }

    /** @group live */
    public function testSpeechLiveStreamAsyncComplete(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $parts = [];
        $audio->speech(IntegrationEnv::speechPayloadNonStream(), [
            'stream' => function (string $buffer) use (&$parts): void {
                $parts[] = $buffer;
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live speech async stream');
        $this->assertNull(
            $caught[1],
            $caught[1] instanceof OpenAIException
                ? 'Live TTS upstream error (set OPENAI_TTS_MODEL / voice for your OPENAI_API_BASE): ' . $caught[1]->getMessage()
                : ''
        );
        $this->assertGreaterThan(0, strlen(implode('', $parts)));
        $this->assertInstanceOf(Response::class, $caught[2]);
    }

    /** @group live */
    public function testSpeechLiveStreamAsyncCompleteIncludesResponse(): void
    {
        IntegrationEnv::skipUnlessLive($this);
        $audio = new Audio(IntegrationEnv::liveHttpClientConfig());
        $done = false;
        $caught = null;
        $parts = [];
        $audio->speech(IntegrationEnv::speechPayloadNonStream(), [
            'stream' => function (string $buffer) use (&$parts): void {
                $parts[] = $buffer;
            },
            'complete' => function (?string $result, ?OpenAIException $e, ?Response $response) use (&$done, &$caught): void {
                $done = true;
                $caught = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$done) {
            return $done;
        }, 'live speech async stream + response');
        $this->assertNull(
            $caught[1],
            $caught[1] instanceof OpenAIException
                ? 'Live TTS upstream error (set OPENAI_TTS_MODEL / voice for your OPENAI_API_BASE): ' . $caught[1]->getMessage()
                : ''
        );
        $this->assertGreaterThan(0, strlen(implode('', $parts)));
        $this->assertInstanceOf(Response::class, $caught[2]);
        $this->assertSame(200, $caught[2]->getStatusCode());
    }
}
