<?php

namespace Socks;

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
        return new Client($this->loop, $connector, $this->resolver, $socksHost, $socksPort);
    }

    public function createServer($socket)
    {
        $connector = $this->createConnector();
        return new Server($socket, $this->loop, $connector);
    }

    protected function createConnector()
    {
        return new Connector($this->loop, $this->resolver);
    }
}
