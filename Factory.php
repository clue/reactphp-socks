<?php

use React\HttpClient\ConnectionManager;
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
        $connector = new ConnectionManager($this->loop, $this->resolver);
//         $connector = new ConnectionManagerFsockopen($this->loop);
        return new Client($this->loop, $connector, $this->resolver, $socksHost, $socksPort);
    }
}
