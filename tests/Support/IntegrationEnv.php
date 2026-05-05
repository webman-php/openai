<?php

declare(strict_types=1);

namespace Webman\Openai\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Environment-variable entry point for live OpenAI (or OpenAI-compatible gateway) integration tests.
 *
 * Conventions:
 * - If `OPENAI_API_KEY` is unset: all Live tests skip at `IntegrationEnv::skipUnlessLive()`; Mock / DNS / connection-refused cases still hit the local mock.
 * - If `OPENAI_API_KEY` is set: Live tests call `apiBase()`; models and other settings use the variables below.
 *
 * Variables:
 * - OPENAI_API_KEY (required to run Live)
 * - OPENAI_API_BASE (optional, default https://api.openai.com)
 * - OPENAI_CHAT_MODEL (optional, default gpt-4o-mini)
 * - OPENAI_EMBEDDING_MODEL (optional, default text-embedding-3-small)
 * - OPENAI_TTS_MODEL (optional, default tts-1; see OpenAI Speech docs—if your aggregator lacks it, use a model your gateway supports)
 * - OPENAI_TTS_VOICE (optional, default alloy)
 * - OPENAI_IMAGE_MODEL (optional, default dall-e-3; must match vendor/docs together with OPENAI_IMAGE_SIZE)
 * - OPENAI_IMAGE_SIZE (optional, default 1024x1024, suitable for dall-e-3)
 */
final class IntegrationEnv
{
    public const LIVE_SKIP_MESSAGE = 'Live API skipped: set OPENAI_API_KEY (optional: OPENAI_API_BASE, OPENAI_CHAT_MODEL, OPENAI_EMBEDDING_MODEL, OPENAI_TTS_MODEL, OPENAI_TTS_VOICE, OPENAI_IMAGE_MODEL, OPENAI_IMAGE_SIZE).';

    public static function isLiveConfigured(): bool
    {
        $k = getenv('OPENAI_API_KEY');

        return $k !== false && $k !== '';
    }

    public static function skipUnlessLive(TestCase $case): void
    {
        if (!self::isLiveConfigured()) {
            $case->markTestSkipped(self::LIVE_SKIP_MESSAGE);
        }
    }

    public static function apiKey(): ?string
    {
        $k = getenv('OPENAI_API_KEY');

        return ($k !== false && $k !== '') ? $k : null;
    }

    public static function apiBase(): string
    {
        $b = getenv('OPENAI_API_BASE');
        if ($b !== false && $b !== '') {
            return rtrim($b, '/');
        }

        return 'https://api.openai.com';
    }

    /**
     * @return array{api: string, apikey: string}
     */
    public static function liveHttpClientConfig(): array
    {
        $key = self::apiKey();
        if ($key === null) {
            throw new \LogicException('liveHttpClientConfig() requires OPENAI_API_KEY (call only after skipUnlessLive).');
        }

        return [
            'api' => self::apiBase(),
            'apikey' => $key,
        ];
    }

    public static function chatModel(): string
    {
        $m = getenv('OPENAI_CHAT_MODEL');
        if ($m !== false && $m !== '') {
            return $m;
        }

        return 'gpt-4o-mini';
    }

    public static function embeddingModel(): string
    {
        $m = getenv('OPENAI_EMBEDDING_MODEL');
        if ($m !== false && $m !== '') {
            return $m;
        }

        return 'text-embedding-3-small';
    }

    public static function ttsModel(): string
    {
        $m = getenv('OPENAI_TTS_MODEL');
        if ($m !== false && $m !== '') {
            return $m;
        }

        return 'tts-1';
    }

    public static function ttsVoice(): string
    {
        $v = getenv('OPENAI_TTS_VOICE');
        if ($v !== false && $v !== '') {
            return $v;
        }

        return 'alloy';
    }

    public static function imageModel(): string
    {
        $m = getenv('OPENAI_IMAGE_MODEL');
        if ($m !== false && $m !== '') {
            return $m;
        }

        return 'dall-e-3';
    }

    public static function imageSize(): string
    {
        $s = getenv('OPENAI_IMAGE_SIZE');
        if ($s !== false && $s !== '') {
            return $s;
        }

        return '1024x1024';
    }

    /** @return array{model: string, input: string} */
    public static function embeddingCreatePayload(): array
    {
        return [
            'model' => self::embeddingModel(),
            'input' => 'live integration embedding input',
        ];
    }

    /** @return array{model: string, prompt: string, n: int, size: string} */
    public static function imageGenerationsPayload(): array
    {
        return [
            'model' => self::imageModel(),
            'prompt' => 'solid blue square, flat, no text',
            'n' => 1,
            'size' => self::imageSize(),
        ];
    }

    /** @return array{model: string, input: string, voice: string} */
    public static function speechPayloadNonStream(): array
    {
        return [
            'model' => self::ttsModel(),
            'input' => 'ok',
            'voice' => self::ttsVoice(),
        ];
    }

    /** @return array{model: string, input: string, voice: string, stream: true} */
    public static function speechPayloadStream(): array
    {
        $p = self::speechPayloadNonStream();
        $p['stream'] = true;

        return $p;
    }
}
