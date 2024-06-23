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
        $url = $this->api;
        if (!$path = parse_url($this->api, PHP_URL_PATH)) {
            $url = "$this->api/v1/audio/speech";
        } else if ($path[strlen($path) - 1] === '/') {
            $url = $this->api . 'audio/speech';
        }
        $http = new Client(['timeout' => 600]);
        $http->request($url, $requestOptions);
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
                    'message' => 'Unable to parse response',
                    'detail' => $buffer
                ]
            ];
        }
        return $buffer;
    }

}
