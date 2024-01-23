<?php

namespace Webman\Openai;

use Throwable;

class Base
{

    /**
     * @var string api
     */
    protected $api = 'https://api.openai.com';

    /**
     * @var mixed|string apikey
     */
    protected $apikey = '';

    /**
     * @var bool|mixed Azure
     */
    protected $isAzure = false;

    /**
     * @var mixed|string Azure api version
     */
    protected $azureApiVersion = '';

    /**
     * @var mixed|string[] default headers
     */
    protected $defaultHeaders = [
        'Content-Type' => 'application/json'
    ];

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->api = $options['api'] ?? $this->api;
        $this->apikey = $options['apikey'] ?? '';
        $this->isAzure = $options['isAzure'] ?? false;
        $this->azureApiVersion = $options['azureApiVersion'] ?? $this->azureApiVersion;
        $this->defaultHeaders = array_merge($this->defaultHeaders, $options['headers'] ?? []);
    }

    /**
     * Get headers.
     * @param $options
     * @return string[]
     */
    protected function getHeaders($options): array
    {
        $defaultHeaders = array_merge($this->defaultHeaders, $options['headers'] ?? []);
        if ($this->isAzure) {
            $defaultHeaders['api-key'] = $this->apikey;
        } else {
            $defaultHeaders['Authorization'] = 'Bearer ' . $this->apikey;
        }
        return array_merge($defaultHeaders, $options['headers'] ?? []);
    }

    /**
     * Format options.
     * @param array $options
     * @return array
     */
    public function formatOptions(array $options)
    {
        foreach (['complete', 'stream'] as $key) {
            $options[$key] = function (...$args) use ($options, $key) {
                try {
                    if ($options[$key]) {
                        $options[$key](...$args);
                    }
                } catch (Throwable $e) {
                    echo $e;
                }
            };
        }
        return $options;
    }

}