<?php

declare(strict_types=1);

namespace Webman\Openai\Tests;

use PHPUnit\Framework\TestCase;
use Webman\Openai\Audio;
use Webman\Openai\OpenAIException;
use Workerman\Coroutine;
use Workerman\Http\Response;
use Workerman\Timer;

/**
 * {@see Audio::transcriptions} / {@see Audio::translations} against {@see MockOpenaiHttpWorker}.
 *
 * Fixture audio: Chinese “你好” , embedded as base64 below).
 * Regenerate: {@code php tests/_build_audio_transcription_test.php}
 */
final class AudioTranscriptionTest extends TestCase
{
    private const FIXTURE_MP3_B64 =
            '/+NIxAAAAAAAAAAAAFhpbmcAAAAPAAAAGgAAFagADg4OFRUVFR8fHx8mJiYmMTExMTs7OztCQkJNTU1N'
            .
            'VFRUVGJiYmJwcHBwenp6eoWFhY+Pj4+ampqapKSkpK+vr6+5ubm5xMTExM7OztXV1dXg4ODg5+fn5/Hx'
            .
            '8fH8/Pz8////AAAAWkxBTUUzLjk5cgQoAAAAAAAAAAA1CCQDyyEAAeoAABWorlVqqwAAAAAAAAAAAAAA'
            .
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
            .
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/+NIxAARUDZcXkpC'
            .
            'AEGMm/AAbSFCEA5oAg4RhiKMZQBiMgHwQuqDAeWUBAmTZoP8nD9JAnqOKdKOPnP+oMTieQy9Tqjhz+H1'
            .
            'Ouf63/7sv/+GJdUCnak5dwAIT5yG6BfDmXBEcKlZSbkmIIJG0IXHx9Nde83wbmMvZhBZGpZWoyk6Juc4'
            .
            'gwBhDvTmEPECgZnGJZXyph3PujgoIJAVdTl2S75zrFd3fhEc85p5p+Q+00FzZUlY0IkIqZKNYVigIbdC'
            .
            '1nxrCJC9j1vJi75nEMCqOkcwlo0YQPM7BOgFGn3m7twAPmjkB2hLUaVyVSuxpRopJ30+VymaIymVzadi'
            .
            'HIUklWnNUIm4mjCXJyUVNOmhNIJ+0UoXexaVZYETAPsMKKRS/+MoxM0hwj5tnnpGGESGBS7qKc+xLwVd'
            .
            '1MXT03HNim0q2i2Uslj/M2Mx7mZFGNONEamUECx3FoEBjhiFJjGo7DDFmAAYcqccQ4kJ0xRI9h7eVQpm'
            .
            'mhVpmRGxGdU+mvkmkpzNPI87qQLZ55dcI6wiiZFsh+Dp8fEJOt1otzfgAftfBwty7XC+dpqxkBo/PUqs'
            .
            '/+M4xMksBFZuPnpGmU0xEikRsIK7xyLVz2/rthOyztzCXi+SDQ0FQ9lkQldYHG4LNM8/OvOaxSVPxf+R'
            .
            '6ywevmdEhiQhIMMWAjQBZoDhJHyFGEIqYoVqK7F895DW6xF3UVgukH5jKNSHdStMGmVPph3eQFYcdmKO'
            .
            'Tmz++TI4U1NoRZIfS28ohzpPX7/wzkem9TrUL/MoCwXKbOQNpcv43G3NuAB/bbsmFkK5/OzEsykMimJy'
            .
            'CZ2mmqWeuLMMNogpBwTLEgr0+FmiwwjIGlGWDUi6bz7N+OJ4/+MoxOQqRDZyXnsGPZrkKxLObiNVVA0t'
            .
            'KUDaBQ1Fddm7izk2kfRJqHw1cTSsaXZbuvc94JukxhxUOy1rMuiztjobidBqVPGpHw8gTgiEix6DcXuQ'
            .
            '8I2Yzpscm6lbtZMzON8zXFPy5XIv/nG0lybco5bZlkvb/Ybxiy3KHUIUihBqeSc2AA/qYIFVxUj+kc50'
            .
            '/+M4xL4rHG5xvsJGfG2ePGduydm4+ZLNoUJWNBpCNiAjEzYjQlWE7isK0sDDyZtdksxGCd9uYibwhaFJ'
            .
            'OJyqBE5VU0SQnHW23uc5MQtc2lBZqgO9Bh6JJaNxTnX9rN++6Zu0N02/x+/HM2p+Mem3e8P1PWZlxT+n'
            .
            'Xq2g5Ap9bPDvuz//3l89YFRk42dI+7k9Ni/VPVfYjazH/vad5dmmaRzxjMk7d2jZKpLMBk87HAXBaVk5'
            .
            'LgAPuSJYUDIeaV475bik727Yh6zOYhCSpOZokCirZARMnWig/+M4xNwtBCZlnnpMXUyRLWizAr1U22gU'
            .
            'ncKJIo2mmJo2prMJbaiDKe2vnkjZQzjC5MQgeQMzriIokgQpEKmBIxUW1USsDmLPIRnmhOQMwogQQI45'
            .
            'iGB4zMujJ2fAKIi3zRFOZHYcYvbN/pmfkFXpPCK3IiKhIXpnC9M8i8G3kvUhnkStrFRs+U/LIw3Mj4U0'
            .
            'W5knLQAPjn2JwwjkDTEYE1fGW9YmdlZlzPt9FrDVzM9nbdJ6dcxFM/OZVqVokUTCcqeMlEDGZCEuJpM5'
            .
            '+qNOnSukObYjxieQ/+MoxPMqfE5pnsJGXZXGzQFkbaZK7IjSiJwVZHNSI2x5u1Vkyw0Vr5aX3Zl/Gcpk'
            .
            '3VBK0DP0JC8iSP3SyWnmW0RlI+qTfuSz34p7XSl8XJC5qEZlms2NKz605nl6le8IoX46/9ZvP5NparDI'
            .
            '4zzRali+rcstAA8kM4Bb3s+GB8wVjQkG1H7FQ9npEpAVD2eP/+M4xMwq29JpnnmHCR4M/h2Vb6Rzix15'
            .
            '49fGLKw2p8kEkHhPpqMO+sTYzlJk55eTJNi2D682fp98tyOJoGFuK27b15h8rCdxBDbXCqOa5XsvKDYH'
            .
            'O4ZFUTQbYyIuSMEva0R3LucUs/Tp/mvbPtHDqR8e6277wk7wfkgos7eHMO3hvAFX5j8DFur4CFBVw+up'
            .
            'yOUADmojUWO05rDot+7T2xt3oy9603zi0CrvAV7TQYAhaJkOsElctA2KBbTlE+IpR20aMcDDoLtzgwKy'
            .
            '0guhtVdGKJom3K2y/+MoxOsn6yZtnnmG1cHJ9GT4he9uL7yNYiVWg03Jsw8mPCJFTpPkt1k9ehWZpqS8'
            .
            '0pS89JV3P7WIoGEbqZgQ7Yr7ZmpLTLyJHYKtzQdC+h5PAjg4IjGc5cCCFwXKFz0eSsEzeh8N5VnkV7k8'
            .
            'zukDVqa3OrMxWDdN2+Ims7h/W43/+da9N2+f9Z17fO42M617/+NIxM441G5tnsJenG8x4ElK5eUiMlIE'
            .
            'L5z9azjG/fPzLVXgpORFNOSgAZ6IAYxweMvPAMQA0VNRkTbxExMNacx192VTQNHQCRCQ6PCKVMDiMFMV'
            .
            'EwEJsSUARvUzVPAbhMxUvUEbykep6Iy/z7uBTsKci1D7O407LfKkeFl5XWHCxt488fHEi1ISHGqt7Jws'
            .
            'u7BXrfGxHrCyi9eh3e7apjLVuzPbXKW+bbM0pOzPbOzM7NKUn9vlHN5+Hp8/m9b1td2YEZeOy4eB0bD1'
            .
            'cWM1Vhz/ubAqmQVGn41lAOkIrxMtRSKNYUdppEmGw6WvxGKj+MolkNv42jwU7XMKlLD9aWTFnP5ilwp+'
            .
            '95U3SYf+f/hX3/539Y4Ycww1vsw/mNjD/+NIxP1GvG52Xtsw9Cm406DySiITkxD8ofjduMYPpFMsbdaz'
            .
            '3VfK7DduVpIQInE56jKABjiFAxAIHrAFNlUudTcBj7B0DI87T/g0wX/ZLGKSXShP1f0KkNeniC80Ul7S'
            .
            'qprtPPOpln3O28D801JXr7aw8K9Zn9PsSThXaFAqW/sRkqKLOb86m3H6gIt2kiS2mSSAmI0TLT15Uc08'
            .
            'ianSX3f2f5FTpSFcDFPm4/4r/i2mVqf1kkoakh6NGEFKcSKi4TiEoiMHwpTsaZA2E6JoPwqEpQ0iipn1'
            .
            'yH2v9K+ef+aqp1dDFgUU7DvQWFQkZxo0RwFBYOFQ2jB4NBeSyxpTCMUgopeAJhSNy/NSAAfXdoKCI4Rx'
            .
            'lJjUoXoOYT+LIQsW/+M4xPU2PG6O/tJQ7GkUYZ4kGlY8rIkNlgjFCa6u9y4EltKQIxKCaZ0KSmlakU7l'
            .
            'ePYXE6NNiEdiwosyniaiEubJ3MPORnsvrMwXPO0mlNEhwHIoINUOn9nS77eFm009USe53pz6iuP/4n+v'
            .
            '7flB9ZqIMkuxZroYEYcWcPNGmuaPYra1fS+iCyDZ37eJvivvn/+f+/0/pu5eaJOaYKqpMzso88YvEU2O'
            .
            'uIa7svEAAyN3XtygAfvKgubuLJMyEy5WLXqmNmJNeh6Es7oOfqtNxVuMgh9uZYcp/+M4xOcvBGae/spQ'
            .
            'kdJ0sKpjP4UzdbdJ4M0d+9VrK9gw39pnDsLhSI3OmaGxN1J/qNBrmaNSsa+vvErdI9pamtM7niJq8OP/'
            .
            'ndM5fv555Hjac6PiQTnZF2X9Rx0+rlQyFwJwkw1aiAQccTdTEKchUZiHoLsgkLB1B4dEBYgdGAZxIBnF'
            .
            'VMNFWKHWmLRzGeropVFWvVDf+///9SnZSo5UNlIVnKUxjJUropNDHKphoqoAOxx762/AAfMQv43zKJwu'
            .
            'z3Isx2RRx4mlTVhT65Zkqq25sYW9XK1v/+M4xPYzXGqeXsvKnLqd9Rxev6UiIezsSGXiOEZ1HbKRt5i3'
            .
            'ns20OlTrp4nFAN89DDjI/beo3zitqx+wo9Vu04aBzqQlBzDgFvJsIQPkIywfRKkJK2rocMnaEqIRYHy6'
            .
            'E6flxMVTiMlvOWutaTKoDleuOrt1EM4UwYWmwckNc1YhjUvvXK+ZpOp7uyK5H6d49EhBBPm75Xp559cr'
            .
            '3ln3I/8//8/5vC/yroXC5EdBkheqB/jLke34AEynShKnHh5oqlpdAfuNm1DItvLH1b0S8uHhZ988NEjZ'
            .
            '/+M4xPMy9FKiXnsHRfl2h4hHJOOx+HtOoYvUtlc+fYibTGBgYKVPDumA4TDExMhzD8ln4FB0Ccj5bkgJ'
            .
            'IIwc7I8Vj5TvD9Q9cqgShoGUWgIgZp6NM8oK4W4LGaj6uUu1NOLt7TcF/soTZLxQYcnrpqauusQ0+jg2'
            .
            'gSX1tzUEWaon//OpVlvezooYQokUIztiT4KzMuuv9z+thL+5wfC9/2HldeG7XqFefSXg3CeI0lu9hrkp'
            .
            'GgPoBmVsq+0lzNtY5ioKzcAD5O2CXkawNRdg4juJMCnbDfU6/+M4xPI21F6lvmPTITGJmRh3HW3ne2Lb'
            .
            'cjFfnSvgPHTWdTgyO7sziqVQtK83np7OKIJwgj3HYqiaEJOMn4WScI9VHKq4I821mGklHBoZx5E5HgnC'
            .
            'Xn7CiC1nqyHQqlLV9a717IO40tSO0dvevOf/zvnaunnS/Tbb/Xd6TURtSk376/uDqnLePA4lHstkX1n+'
            .
            'ObimSTNGds1qqu+bKMDupt5NFs+f1f/LM5wV0YzMv3Wo4EcPKs4Z1VGC53OPNVyqBT3AA/468jFzHQyI'
            .
            '6vwIIKAKEgEMhXAq/+M4xOEyFDaQHnmHTZS4ibykmGJ1wdSUTOnhm2UR5nDjxVSp2mHNPhly2YSp5n6c'
            .
            'hkyd6OLJ4ODAy5Y6gDPUxUA40MEXDuBUBjAJXBYQxsAHaKMsDGAARoq1LWIAAKoNai+pWVWAkgOMMjRw'
            .
            'BoGKNGL5P2rlyYjLIzVis9KpVL4zGXlxne6prXdY6u9vf3X7dHW9P27w9VM5szZXet1WvD7D1HrHz9ii'
            .
            'ajKHUhmPTTDUqkv3jXf/GSby8tSVo/O/RZ61WqfP9Zn8M25+//2jMfPhv2LSXsE3/+M4xOM7pDZ0HtYM'
            .
            'mZqXJLNzlLu1ixrRGgGW7/wAPmpfTlLuXU1BXhHWRqjqyVzwtHRRkxMoHlpcEEwPo4rH1z1K7+nLEacs'
            .
            'lc9WIzmhOXBKTiCYkodiSHo8EoFhLCk8JxXAiNAAQ4AOFIHS8Wo3TsJdGKeWze7daAMVm7DV0m3I3MaZ'
            .
            'mH18lujjIs3/uXuPvv2bKvSTxZrNbFODE8rQU1JvBFCNWcffqqnJgttrc2V5ggBwSqrP0o1Q22WtUvu7'
            .
            'iv8zRa0VeldR1qTVXV9cATcUtybcAD4t/+M4xL8tgx51XnsMPZH5Il73SLRnD2HEZkuwr64WErF7q792'
            .
            '1SpOCzpE/ocOrXra7jYXFXzG3w4DyBdgiRlY3rtaYXN7HCBpuPSemwVqkYaRxpnm4iJc4EJxiUEHYnb8'
            .
            '1taXa3ZTIZ/rJu7S/r4hsR3ZkP4fd2L8XbbTPrigiBQUDFk0SaBLqIkRonQxYIVmZMS8I4+9ej/Thva6'
            .
            'Mvv6RutZceYpFuA0HTY0mszFukGqkpUaRvcAD9WloDo5VCr247BGGc+2hVs8UaEYoJVlyAngkmqoJlSO'
            .
            '/+M4xNQrIup2PnmTEQ5rEyINaRH2olViF0ipKhOxLD8WUrdixpV4qNLSQpynV0SOGQvRiuLWMOLAwikF'
            .
            '3lxiTKB6cdpSjmuBFhUanRLOPtbOGvcxdc001PHXHxUTFuymlCyzDjrFR0V3MrVV9cf/1bVEPVSM14x/'
            .
            'LunqqWOedjWoWQTueYbHcSkyTa0+6tUX1QluTbgAfHZx5LSrviIxqmVxXEZEMRsGGooqjRgYFbKM86VZ'
            .
            'HTJ1SRC0gWYRsgwJzpQ4gCT884x9MLtitSLppqbSyakWiFJD/+MoxPIqC05xnsJQPRhad6rWz0erFpKp'
            .
            'UyR31Kt3dP/ciFjQCcEpAamCUONdIuSHPMi9YSSm8YhUc7hn1UgCSubCmaItsCCwlRkpb0jI9s3M+vlc'
            .
            'z6V907jdhvntG3eZK/TJAree5XYtikg6tpU=';


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

    /** @return non-empty-string */
    private static function fixtureMp3Binary(): string
    {
        $bin = base64_decode(self::FIXTURE_MP3_B64, true);
        if ($bin === false || $bin === '') {
            self::fail('FIXTURE_MP3_B64 decode failed');
        }

        return $bin;
    }

    /** @return array{model: string, file: array{contents: string, filename: string, mime: string}} */
    private function transcriptionPayload(string $model = 'gpt-4o-mini-transcribe'): array
    {
        return [
            'model' => $model,
            'file' => [
                'contents' => self::fixtureMp3Binary(),
                'filename' => 'nihao.mp3',
                'mime' => 'audio/mpeg',
            ],
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

    public function testTranscriptionsMockNonStreamSync(): void
    {
        $audio = $this->mockAudio();
        $result = $audio->transcriptions($this->transcriptionPayload(), [
            'headers' => ['X-Test-Scenario' => 'transcription-json'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('你好', $result['text'] ?? null);
    }

    public function testTranscriptionsMockNonStreamSyncWithResponse(): void
    {
        $audio = $this->mockAudio();
        [$result, $response] = $audio->transcriptions($this->transcriptionPayload(), [
            'headers' => ['X-Test-Scenario' => 'transcription-json'],
            'with_response' => true,
        ]);
        $this->assertIsArray($result);
        $this->assertSame('你好', $result['text'] ?? null);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('req_mock_transcription_json', $response->getHeaderLine('x-mock-request-id'));
    }

    public function testTranscriptionsMockStreamSyncGenerator(): void
    {
        $audio = $this->mockAudio();
        $data = $this->transcriptionPayload();
        $data['stream'] = true;
        $gen = $audio->transcriptions($data, [
            'headers' => ['X-Test-Scenario' => 'transcription-sse'],
        ]);
        $events = [];
        foreach ($gen as $ev) {
            $events[] = $ev;
        }
        $this->assertNotEmpty($events);
        $types = array_column($events, 'type');
        $this->assertContains('transcript.text.delta', $types);
        $this->assertContains('transcript.text.done', $types);
    }

    public function testTranscriptionsMockAsyncNonStreamComplete(): void
    {
        $audio = $this->mockAudio();
        $resultHolder = null;
        $audio->transcriptions($this->transcriptionPayload(), [
            'headers' => ['X-Test-Scenario' => 'transcription-json'],
            'complete' => function ($result, ?OpenAIException $e, ?Response $response) use (&$resultHolder): void {
                $resultHolder = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$resultHolder): bool {
            return $resultHolder !== null;
        }, 'async transcription-json complete');
        $this->assertNotNull($resultHolder);
        [$result, $e, $response] = $resultHolder;
        $this->assertNull($e);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertIsArray($result);
        $this->assertSame('你好', $result['text'] ?? null);
    }

    public function testTranscriptionsMockAsyncStreamWithStreamCallback(): void
    {
        $audio = $this->mockAudio();
        $deltas = [];
        $holder = null;
        $data = $this->transcriptionPayload();
        $data['stream'] = true;
        $audio->transcriptions($data, [
            'headers' => ['X-Test-Scenario' => 'transcription-sse'],
            'stream' => function (array $chunk) use (&$deltas): void {
                if (($chunk['type'] ?? '') === 'transcript.text.delta') {
                    $deltas[] = (string) ($chunk['delta'] ?? '');
                }
            },
            'complete' => function ($result, ?OpenAIException $e, ?Response $response) use (&$holder): void {
                $holder = [$result, $e];
            },
        ]);
        $this->awaitAsync(function () use (&$holder): bool {
            return $holder !== null;
        }, 'async transcription-sse complete');
        [$result, $e] = $holder;
        $this->assertNull($e);
        $this->assertNull($result);
        $this->assertSame(['你', '好'], $deltas);
    }

    public function testTranscriptionsMockAsyncStreamCompleteOnlyAggregatesText(): void
    {
        $audio = $this->mockAudio();
        $resultHolder = null;
        $data = $this->transcriptionPayload();
        $data['stream'] = true;
        $audio->transcriptions($data, [
            'headers' => ['X-Test-Scenario' => 'transcription-sse'],
            'complete' => function ($result, ?OpenAIException $e, ?Response $response) use (&$resultHolder): void {
                $resultHolder = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$resultHolder): bool {
            return $resultHolder !== null;
        }, 'async transcription-sse aggregate complete');
        [$result, $e] = $resultHolder;
        $this->assertNull($e);
        $this->assertIsArray($result);
        $this->assertSame('你好', $result['text'] ?? null);
        $this->assertSame(['type' => 'seconds', 'seconds' => 0.42], $result['usage'] ?? null);
    }

    public function testTranscriptionsMockAsync401(): void
    {
        $audio = $this->mockAudio();
        $holder = null;
        $audio->transcriptions($this->transcriptionPayload(), [
            'headers' => ['X-Test-Scenario' => 'transcription-401'],
            'complete' => function ($result, ?OpenAIException $e, ?Response $response) use (&$holder): void {
                $holder = [$result, $e, $response];
            },
        ]);
        $this->awaitAsync(function () use (&$holder): bool {
            return $holder !== null;
        }, 'async 401 complete');
        [$result, $e, $response] = $holder;
        $this->assertNull($result);
        $this->assertInstanceOf(OpenAIException::class, $e);
        $this->assertSame(401, $e->statusCode);
    }

    public function testTranslationsMockNonStreamSync(): void
    {
        $audio = $this->mockAudio();
        $result = $audio->translations($this->transcriptionPayload('whisper-large'), [
            'headers' => ['X-Test-Scenario' => 'translation-json'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('Hello', $result['text'] ?? null);
    }

    public function testTranslationsMockStreamSyncGenerator(): void
    {
        $audio = $this->mockAudio();
        $data = $this->transcriptionPayload('whisper-large');
        $data['stream'] = true;
        $gen = $audio->translations($data, [
            'headers' => ['X-Test-Scenario' => 'translation-sse'],
        ]);
        $text = '';
        foreach ($gen as $ev) {
            if (($ev['type'] ?? '') === 'transcript.text.delta') {
                $text .= (string) ($ev['delta'] ?? '');
            }
        }
        $this->assertSame('Hello', $text);
    }

    public function testTranslationsMockAsyncCompleteOnlyAggregates(): void
    {
        $audio = $this->mockAudio();
        $holder = null;
        $data = $this->transcriptionPayload('whisper-large');
        $data['stream'] = true;
        $audio->translations($data, [
            'headers' => ['X-Test-Scenario' => 'translation-sse'],
            'complete' => function ($result, ?OpenAIException $e, ?Response $response) use (&$holder): void {
                $holder = [$result, $e];
            },
        ]);
        $this->awaitAsync(function () use (&$holder): bool {
            return $holder !== null;
        }, 'translation aggregate');
        [$result, $e] = $holder;
        $this->assertNull($e);
        $this->assertIsArray($result);
        $this->assertSame('Hello', $result['text'] ?? null);
    }
}
