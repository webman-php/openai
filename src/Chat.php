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
        if (isset($options['stream'])) {
            $data['stream'] = true;
        }
        $stream = !empty($data['stream']) && isset($options['stream']);
        $options = $this->formatOptions($options);
        $requestOptions = [
            'method' => 'POST',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'headers' => $headers,
            'progress' => function ($buffer) use ($options) {
                static $tmp = '';
                $tmp .= $buffer;
                if ($tmp === '' || $tmp[strlen($tmp) - 1] !== "\n") {
                    return null;
                }
                preg_match_all('/data: *?(\{.+?\})[ \r]*?\n/', $tmp, $matches);
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
        $model = $data['model'] ?? '';
        $url = $this->api;
        if (!$path = parse_url($this->api, PHP_URL_PATH)) {
            $url = $this->api . ($this->isAzure ? "/openai/deployments/$model/chat/completions?api-version=$this->azureApiVersion" : "/v1/chat/completions");
        } else if ($path[strlen($path) - 1] === '/') {
            $url = $this->api . 'chat/completions';
        }
        $http = new Client(['timeout' => 600]);
        $http->request($url, $requestOptions);
    }

    /**
     * Format chat response.
     * @param $buffer
     * @return array|array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        if (!$buffer || $buffer[0] === '') {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'message' => 'Empty response from api',
                    'detail' => $buffer
                ]
            ];
        }
        $json = json_decode($buffer, true);
        if ($json) {
            return $json;
        }
        if ($buffer[0] === '<') {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'message' => 'Invalid response from api',
                    'detail' => $buffer
                ]
            ];
        }
        $chunks = explode("\n", $buffer);
        $content = '';
        $finishReason = null;
        $model = '';
        $promptFilterResults = null;
        $contentFilterResults = null;
        $contentFilterOffsets = null;
        $toolCalls = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === "") {
                continue;
            }
            if (strpos($chunk, 'data:{') === 0) {
                $chunk = substr($chunk, 5);
            } else {
                $chunk = substr($chunk, 6);
            }
            if ($chunk === "" || $chunk === "[DONE]") {
                continue;
            }
            try {
                $data = json_decode($chunk, true);
                if (isset($data['model'])) {
                    $model = $data['model'];
                }
                if (isset($data['prompt_filter_results'])) {
                    $promptFilterResults = $data['prompt_filter_results'];
                }
                if (isset($data['error'])) {
                    $content .= $data['error']['message'] ?? "";
                } else {
                    foreach ($data['choices'] ?? [] as $item) {
                        $content .= $item['delta']['content'] ?? "";
                        foreach ($item['delta']['tool_calls'] ?? [] as $function) {
                            if (isset($function['function']['name'])) {
                                $toolCalls[$function['index']] = $function;
                            } elseif (isset($function['function']['arguments'])) {
                                $toolCalls[$function['index']]['function']['arguments'] .= $function['function']['arguments'];
                            }
                        }
                        if (isset($item['finish_reason'])) {
                            $finishReason = $item['finish_reason'];
                        }
                        if (isset($item['content_filter_results'])) {
                            $contentFilterResults = $item['content_filter_results'];
                        }
                        if (isset($item['content_filter_offsets'])) {
                            $contentFilterOffsets = $item['content_filter_offsets'];
                        }
                    }
                }
            } catch (Throwable $e) {
                echo $e;
            }
        }
        $result = [
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
        if ($promptFilterResults) {
            $result['prompt_filter_results'] = $promptFilterResults;
        }
        if ($contentFilterResults) {
            $result['choices'][0]['content_filter_results'] = $contentFilterResults;
        }
        if ($contentFilterOffsets) {
            $result['choices'][0]['content_filter_offsets'] = $contentFilterOffsets;
        }
        if ($toolCalls) {
            $result['choices'][0]['message']['tool_calls'] = array_values($toolCalls);
        }
        return $result;
    }

}
