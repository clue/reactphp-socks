<?php

namespace Clue\React\Socks;

use React\SocketClient\ConnectorInterface;
use Clue\React\Socks\Client;

/**
 * The Connector instance can be used to establish TCP connections to remote hosts.
 *
 * Each instance can be used to establish any number of TCP connections.
 *
 * It implements React's `ConnectorInterface` which only provides a single
 * `create()` method.
 *
 * You can use this method directly in order to establish a TCP connection to
 * the given target host and port.
 *
 * It functions as an adapter:
 * Many higher-level networking protocols build on top of TCP. It you're dealing
 * with one such client implementation,  it probably uses/accepts an instance
 * implementing React's `ConnectorInterface` (and usually its default `Connector`
 * instance). In this case you can also pass this `Connector` instance instead
 * to make this client implementation SOCKS-aware. That's it.
 */
class Connector implements ConnectorInterface
{
    private $client;

    public function __construct(Client $socksClient)
    {
        $this->client = $socksClient;
    }

    public function create($host, $port)
    {
        return $this->client->createConnection($host, $port);
    }
}
