<?php

namespace Webman\Openai;

use Throwable;
use Workerman\Http\Client;
use Workerman\Http\Response;

class Chat extends Base
{

    /**
     * @var mixed|string Azure api version
     */
    protected $azureApiVersion = '2023-05-15';

    /**
     * Chat api
     * @param array $data
     * @param array $options
     * @return void
     */
    public function completions(array $data, array $options)
    {
        $headers = $this->getHeaders($options);
        $model = $data['model'] ?? '';
        $http = new Client();
        if (isset($options['stream'])) {
            $data['stream'] = true;
        }
        $stream = !empty($data['stream']) && isset($options['stream']);
        $options = $this->formatOptions($options);
        $requestOptions = [
            'method' => 'POST',
            'data' => json_encode($data),
            'headers' => $headers,
            'progress' => function ($buffer) use ($options) {
                static $tmp = '';
                $tmp .= $buffer;
                if ($tmp[strlen($tmp) - 1] !== "\n") {
                    return null;
                }
                preg_match_all('/data: (\{.+?\})\n/', $tmp, $matches);
                $tmp = '';
                foreach ($matches[1]?:[] as $match) {
                    if ($json = json_decode($match, true)) {
                        $options['stream']($json);
                    }
                }
            },
            'success' => function (Response $response) use ($options) {
                $result = static::formatResponse((string)$response->getBody());
                $options['complete']($result, $response);
            },
            'error' => function ($exception) use ($options) {
                $options['complete']([
                    'error' => [
                        'code' => 'exception',
                        'message' => $exception->getMessage(),
                        'detail' => (string) $exception
                    ],
                ], new Response(0));
            }
        ];
        if (!$stream) {
            unset($requestOptions['progress']);
        }
        $path = $this->isAzure ? "/openai/deployments/$model/chat/completions?api-version=$this->azureApiVersion" : "/v1/chat/completions";
        $http->request($this->api . $path, $requestOptions);
    }

    /**
     * Format chat response.
     * @param $buffer
     * @return array|array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        $json = json_decode($buffer, true);
        if ($json) {
            return $json;
        }
        $chunks = explode("\n", $buffer);
        $content = '';
        $finishReason = null;
        $model = '';
        foreach ($chunks as $chunk) {
            if ($chunk === "") {
                continue;
            }
            $chunk = trim(substr($chunk, 6));
            if ($chunk === "" || $chunk === "[DONE]") {
                continue;
            }
            try {
                $data = json_decode($chunk, true);
                if (isset($data['error'])) {
                    $content .= $data['error']['message'] ?? "";
                } else {
                    foreach ($data['choices'] ?? [] as $item) {
                        $content .= $item['delta']['content'] ?? "";
                        if (isset($item['finish_reason'])) {
                            $finishReason = $item['finish_reason'];
                        }
                    }
                }
                if (isset($data['model'])) {
                    $model = $data['model'];
                }
            } catch (Throwable $e) {
                echo $e;
            }
        }
        if ($content === '') {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'error' => 'Unable to parse response',
                    'detail' => $buffer
                ]
            ];
        }
        return [
            'choices' => [
                [
                    'finish_reason' => $finishReason,
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                ]
            ],
            'model' => $model,
        ];
    }

}