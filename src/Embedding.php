<?php

namespace Webman\Openai;

use Workerman\Http\Client;
use Workerman\Http\Response;

class Embedding extends Base
{

    /**
     * @var string $azureApiVersion
     */
    protected $azureApiVersion = '2023-05-15';

    /**
     * Embedding api
     * @param array $data
     * @param array $options
     * @return void
     */
    public function create(array $data, array $options)
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
        $path = $this->isAzure ? "/openai/deployments/$model/embeddings?api-version=$this->azureApiVersion" : "/v1/embeddings";
        $http = new Client(['timeout' => 60]);
        $http->request($this->api . $path, $requestOptions);
    }

    /**
     * Format embedding response.
     * @param $buffer
     * @return array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        $json = json_decode($buffer, true);
        if ($json && (!empty($json['error']) || isset($json['data'][0]['embedding']))) {
            return $json;
        }
        return [
            'error' => [
                'code' => 'parse_error',
                'error' => 'Unable to parse response',
                'detail' => $buffer
            ]
        ];
    }
}
