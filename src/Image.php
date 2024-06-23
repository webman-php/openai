<?php

namespace Webman\Openai;

use Workerman\Http\Client;
use Workerman\Http\Response;

class Image extends Base
{

    /**
     * @var string $azureApiVersion
     */
    protected $azureApiVersion = '2023-12-01-preview';

    /**
     * Image api
     * @param array $data
     * @param array $options
     * @return void
     */
    public function generations(array $data, array $options)
    {
        $headers = $this->getHeaders($options);
        $options = $this->formatOptions($options);
        $requestOptions = [
            'method' => 'POST',
            'data' => json_encode($data),
            'headers' => $headers,
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
        $model = $data['model'] ?? '';
        $url = $this->api;
        if (!$path = parse_url($this->api, PHP_URL_PATH)) {
            $url = $this->api . ($this->isAzure ? "/openai/deployments/$model/images/generations?api-version=$this->azureApiVersion" : "/v1/images/generations");
        } else if ($path[strlen($path) - 1] === '/') {
            $url = $this->api . 'images/generations';
        }
        $http = new Client(['timeout' => 600]);
        $http->request($url, $requestOptions);
    }

    /**
     * Format image response.
     * @param $buffer
     * @return array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        $json = json_decode($buffer, true);
        if ($json && (!empty($json['error']) || isset($json['data'][0]['url']))) {
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
