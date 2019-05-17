<?php

namespace hiapi\isp;

use GuzzleHttp\Client;

class IspClient
{
    /** @var Client  */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function request(string $url, array $data)
    {
        $res = $this->client->request('POST', $url, [
            'body' => $data
        ]);

        return 42;
    }
}
