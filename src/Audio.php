<?php

namespace Webman\Openai;

use Workerman\Http\Client;
use Workerman\Http\Response;

class Audio extends Base
{
    
    /**
     * Speech api
     * @param array $data
     * @param array $options
     * @return void
     */
    public function speech(array $data, array $options)
    {
        $headers = $this->getHeaders($options);
        $stream = isset($options['stream']);
        $requestOptions = [
            'method' => 'POST',
            'data' => json_encode($data),
            'headers' => $headers,
            'progress' => function ($buffer) use ($options) {
                $options['stream']($buffer);
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
        $http = new Client();
        $http->request("$this->api/v1/audio/speech", $requestOptions);
    }

    /**
     * Format audio response.
     * @param $buffer
     * @return array[]|mixed
     */
    public static function formatResponse($buffer)
    {
        $json = json_decode($buffer, true);
        if ($json && !empty($json['error'])) {
            return $json;
        }
        if (strpos(ltrim($buffer),'<html>') === 0) {
            return [
                'error' => [
                    'code' => 'parse_error',
                    'error' => 'Unable to parse response',
                    'detail' => $buffer
                ]
            ];
        }
        return $buffer;
    }

}