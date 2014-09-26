<?php

namespace Clue\React\Socks;

use React\Dns\Resolver\Resolver;
use React\EventLoop\LoopInterface;
use React\SocketClient\Connector;

class Factory
{
    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function createClient($socksHost, $socksPort)
    {
        $connector = $this->createConnector();
        return new Client($this->loop, $socksHost, $socksPort, $connector, $this->resolver);
    }

    public function createServer($socket)
    {
        $connector = $this->createConnector();
        return new Server($this->loop, $socket, $connector);
    }

    protected function createConnector()
    {
        return new Connector($this->loop, $this->resolver);
    }
}
