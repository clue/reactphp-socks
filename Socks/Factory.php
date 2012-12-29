<?php

namespace Socks;

use ConnectionManager\ConnectionManager;
use React\Dns\Resolver\Resolver;
use React\EventLoop\LoopInterface;

class Factory
{
    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function createClient($socksHost, $socksPort)
    {
        $connector = $this->createConnectionManager();
        return new Client($this->loop, $connector, $this->resolver, $socksHost, $socksPort);
    }

    public function createServer($socket)
    {
        $connector = $this->createConnectionManager();
        return new Server($socket, $this->loop, $connector);
    }

    protected function createConnectionManager()
    {
        return new ConnectionManager($this->loop, $this->resolver);
    }
}
