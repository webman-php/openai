<?php

namespace Webman\Openai;

use Workerman\Http\Response;

class Image extends Base
{

    /**
     * @var string $azureApiVersion
     */
    protected $azureApiVersion = '2024-10-21';

    /**
     * Image generations API.
     *
     * Behaviour:
     *  - Async callback mode (returns void): set $options['complete'] to receive the result.
     *      complete: callable(?array $result, ?OpenAIException $exception, ?Response $response): void
     *                Older 1- or 2-parameter callbacks remain compatible (PHP drops extras).
     *  - Coroutine sync mode (must run inside a coroutine): returns the decoded result array,
     *    or [$result, ?Response] when $options['with_response'] === true.
     *    HTTP/API errors raise an OpenAIException.
     *
     * @param array $data
     * @param array{
     *     timeout?: int,
     *     headers?: array<string,string>,
     *     with_response?: bool,
     *     complete?: callable(?array, ?OpenAIException, ?Response): void,
     * } $options
     * @return array|void
     */
    public function generations(array $data, array $options = [])
    {
        $headers = $this->getHeaders($options);
        $ret = $this->syncOrFormatAndSend($options, function (array $opt) use ($data, $headers): void {
            $requestOptions = [
                'method' => 'POST',
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'headers' => $headers,
                'success' => function (Response $response) use ($opt) {
                    $result = static::formatResponse((string)$response->getBody());
                    $opt['complete']($result, $response);
                },
                'error' => function ($exception) use ($opt) {
                    $opt['complete']([
                        'error' => [
                            'code' => 'exception',
                            'message' => $exception->getMessage(),
                            'detail' => (string) $exception
                        ],
                    ], new Response(0));
                }
            ];
            $model = $data['model'] ?? '';
            $url = $this->api;
            if (!$path = parse_url($this->api, PHP_URL_PATH)) {
                $url = $this->api . ($this->isAzure ? "/openai/deployments/$model/images/generations?api-version=$this->azureApiVersion" : "/v1/images/generations");
            } else if ($path[strlen($path) - 1] === '/') {
                $url = $this->api . 'images/generations';
            }
            $http = $this->createHttpClient((int) ($opt['timeout'] ?? 600));
            $http->request($url, $requestOptions);
        });
        if ($ret !== null) {
            return $ret;
        }
    }

    /**
     * Format image response.
     * @param $buffer
     * @return array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        $json = json_decode($buffer, true);
        if (is_array($json)) {
            return $json;
        }
        return [
            'error' => [
                'code' => 'parse_error',
                'message' => 'Unable to parse response',
                'detail' => $buffer
            ]
        ];
    }

}
