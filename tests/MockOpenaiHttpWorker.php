<?php

declare(strict_types=1);

namespace Webman\Openai\Tests;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * Minimal HTTP server mimicking OpenAI chat, audio, images, and embeddings endpoints.
 *
 * Scenario is selected with request header {@code X-Test-Scenario}.
 * Images: JSON body {@code model} starting with {@code gpt-image-} returns GPT Image–style {@code data[].b64_json};
 * otherwise DALL·E–style {@code url} + {@code revised_prompt}.
 */
final class MockOpenaiHttpWorker
{
    /**
     * Base64 for a 1×1 PNG, matching GPT Image (e.g. {@code gpt-image-2}) {@code data[].b64_json} shape (no {@code url}).
     *
     * @see https://developers.openai.com/api/docs/guides/image-generation
     */
    private const MOCK_GPT_IMAGE_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public static function handle(TcpConnection $connection, Request $request): void
    {
        $path = $request->path();
        if ($path === '/v1/audio/speech') {
            self::handleAudioSpeech($connection, $request);

            return;
        }
        if ($path === '/v1/images/generations') {
            self::handleImagesGenerations($connection, $request);

            return;
        }
        if ($path === '/v1/embeddings') {
            self::handleEmbeddings($connection, $request);

            return;
        }
        if ($path !== '/v1/chat/completions') {
            $connection->send(new Response(404, [], 'not found'));
            return;
        }

        $scenario = (string)$request->header('x-test-scenario', '');
        $raw = $request->rawBody();
        $payload = json_decode($raw, true) ?: [];

        if ($scenario === 'http-401') {
            $body = json_encode([
                'error' => [
                    'message' => 'Incorrect API key provided: sk-fake. You can find your API key at https://platform.openai.com/account/api-keys.',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'invalid_api_key',
                ],
            ], JSON_UNESCAPED_UNICODE);
            $connection->send(new Response(401, [
                'Content-Type' => 'application/json; charset=utf-8',
            ], $body));
            return;
        }

        if (!empty($payload['stream'])) {
            self::handleStream($connection, $scenario);
            return;
        }

        if ($scenario === 'http-400-model') {
            $body = json_encode([
                'error' => [
                    'message' => 'The model `not-a-real-model` does not exist or you do not have access to it.',
                    'type' => 'invalid_request_error',
                    'param' => 'model',
                    'code' => 'model_not_found',
                ],
            ], JSON_UNESCAPED_UNICODE);
            $connection->send(new Response(404, [
                'Content-Type' => 'application/json; charset=utf-8',
            ], $body));
            return;
        }

        /** Send headers plus a chunked body fragment, then close—simulates RST/FIN mid read on non-streaming bodies. */
        if ($scenario === 'disconnect-mid-nonstream') {
            $connection->send(new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Connection' => 'close',
                'Transfer-Encoding' => 'chunked',
                'X-Mock-Request-Id' => 'req_mock_disconnect_nonstream',
            ]));
            $connection->send(new Chunk('{"id":"chatcmpl-partial","object":"chat.completion","choices":['));
            $connection->close();
            return;
        }

        $body = json_encode([
            'id' => 'chatcmpl-mock-nonstream',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $payload['model'] ?? 'gpt-4o-mini',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'mock-non-stream-reply',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 9,
                'completion_tokens' => 6,
                'total_tokens' => 15,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $connection->send(new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Mock-Request-Id' => 'req_mock_nonstream',
        ], $body));
    }

    private static function handleStream(TcpConnection $connection, string $scenario): void
    {
        if ($scenario === 'reflect-hang') {
            $connection->send(new Response(200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
            ]));
            return;
        }

        if ($scenario === 'disconnect-mid-stream') {
            $connection->send(new Response(200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'close',
                'Transfer-Encoding' => 'chunked',
            ]));
            $chunk = self::sseDataLine([
                'id' => 'chatcmpl-mock-chunk-0',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['role' => 'assistant', 'content' => 'partial'],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $connection->send(new Chunk($chunk));
            $connection->close();
            return;
        }

        /** Single SSE line only: {@code data: {"error":{...}}} (no preceding delta). */
        if ($scenario === 'stream-open-error') {
            $connection->send(new Response(200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                'X-Mock-Request-Id' => 'req_mock_stream_open_error',
            ]));
            $connection->send(new Chunk(self::sseDataLine([
                'error' => [
                    'message' => 'Stream open mock error',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'mock_stream_open_error',
                ],
            ])));
            $connection->send(new Chunk(''));
            return;
        }

        /** Send a normal delta first, then an OpenAI-style SSE {@code data: {"error":{...}}} line. */
        if ($scenario === 'stream-mid-error') {
            $connection->send(new Response(200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                'X-Mock-Request-Id' => 'req_mock_stream_mid_error',
            ]));
            $connection->send(new Chunk(self::sseDataLine([
                'id' => 'chatcmpl-mock-before-err',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['content' => 'before-error'],
                        'finish_reason' => null,
                    ],
                ],
            ])));
            $connection->send(new Chunk(self::sseDataLine([
                'error' => [
                    'message' => 'Streaming mid mock error',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'mock_stream_mid_error',
                ],
            ])));
            $connection->send(new Chunk(''));
            return;
        }

        $connection->send(new Response(200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
            'X-Mock-Request-Id' => 'req_mock_stream',
        ]));

        $lines = [
            self::sseDataLine([
                'id' => 'chatcmpl-mock-chunk-0',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['role' => 'assistant', 'content' => ''],
                        'finish_reason' => null,
                    ],
                ],
            ]),
            self::sseDataLine([
                'id' => 'chatcmpl-mock-chunk-1',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['content' => 'mock-stream-token'],
                        'finish_reason' => null,
                    ],
                ],
            ]),
            self::sseDataLine([
                'id' => 'chatcmpl-mock-chunk-2',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => [],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
            "data: [DONE]\n\n",
        ];

        foreach ($lines as $line) {
            $connection->send(new Chunk($line));
        }
        $connection->send(new Chunk(''));
    }

    private static function handleAudioSpeech(TcpConnection $connection, Request $request): void
    {
        $scenario = (string)$request->header('x-test-scenario', '');

        if ($scenario === 'http-401') {
            $body = json_encode([
                'error' => [
                    'message' => 'Incorrect API key provided: sk-fake. You can find your API key at https://platform.openai.com/account/api-keys.',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'invalid_api_key',
                ],
            ], JSON_UNESCAPED_UNICODE);
            $connection->send(new Response(401, [
                'Content-Type' => 'application/json; charset=utf-8',
            ], $body));

            return;
        }

        if ($scenario === 'speech-chunked') {
            $connection->send(new Response(200, [
                'Content-Type' => 'audio/mpeg',
                'Transfer-Encoding' => 'chunked',
                'X-Mock-Request-Id' => 'req_mock_speech_chunked',
            ]));
            foreach (['MOCK', '_TTS', '_CHUNK'] as $part) {
                $connection->send(new Chunk($part));
            }
            $connection->send(new Chunk(''));

            return;
        }

        $connection->send(new Response(200, [
            'Content-Type' => 'audio/mpeg',
            'X-Mock-Request-Id' => 'req_mock_speech',
        ], 'MOCK_TTS_SINGLE'));
    }

    private static function handleImagesGenerations(TcpConnection $connection, Request $request): void
    {
        $scenario = (string)$request->header('x-test-scenario', '');

        if ($scenario === 'http-401') {
            $body = json_encode([
                'error' => [
                    'message' => 'Incorrect API key provided: sk-fake. You can find your API key at https://platform.openai.com/account/api-keys.',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'invalid_api_key',
                ],
            ], JSON_UNESCAPED_UNICODE);
            $connection->send(new Response(401, [
                'Content-Type' => 'application/json; charset=utf-8',
            ], $body));

            return;
        }

        $payload = json_decode($request->rawBody(), true) ?: [];
        $model = (string)($payload['model'] ?? '');
        // GPT Image (gpt-image-2, etc.): API defaults to data[].b64_json without url; DALL·E typically uses url + revised_prompt.
        if (str_starts_with($model, 'gpt-image-')) {
            $body = json_encode([
                'created' => time(),
                'data' => [
                    [
                        'b64_json' => self::MOCK_GPT_IMAGE_PNG_B64,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);
            $connection->send(new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Mock-Request-Id' => 'req_mock_image_gpt_image',
            ], $body));

            return;
        }

        $body = json_encode([
            'created' => time(),
            'data' => [
                [
                    'url' => 'https://mock.openai.test/generated.png',
                    'revised_prompt' => 'mock-revised-prompt',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $connection->send(new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Mock-Request-Id' => 'req_mock_image_generations',
        ], $body));
    }

    private static function handleEmbeddings(TcpConnection $connection, Request $request): void
    {
        $scenario = (string)$request->header('x-test-scenario', '');

        if ($scenario === 'http-401') {
            $body = json_encode([
                'error' => [
                    'message' => 'Incorrect API key provided: sk-fake. You can find your API key at https://platform.openai.com/account/api-keys.',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'invalid_api_key',
                ],
            ], JSON_UNESCAPED_UNICODE);
            $connection->send(new Response(401, [
                'Content-Type' => 'application/json; charset=utf-8',
            ], $body));

            return;
        }

        $body = json_encode([
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => [0.1, 0.2, 0.3],
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 4,
                'total_tokens' => 4,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $connection->send(new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Mock-Request-Id' => 'req_mock_embeddings',
        ], $body));
    }

    private static function sseDataLine(array $payload): string
    {
        return 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
