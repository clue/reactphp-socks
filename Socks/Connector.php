<?php

namespace Socks;

use React\SocketClient\ConnectorInterface;
use Socks\Client;

class Connector implements ConnectorInterface
{
    private $client;
    
    public function __construct(Client $socksClient)
    {
        $this->client = $socksClient;
    }
    
    public function create($host, $port)
    {
        return $this->client->getConnection($host, $port);
    }
}
