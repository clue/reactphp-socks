<?php

use React\Promise\FulfilledPromise;

use React\Promise\RejectedPromise;

use React\Promise\Deferred;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\HttpClient\ConnectionManagerInterface;

class ConnectionManagerFsockopen implements ConnectionManagerInterface
{
    private $timeout = 5.0;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function setTimeout($timeoutSeconds)
    {
        $this->timeout = $timeoutSeconds;
    }

    public function getConnection($host, $port)
    {
        $socket = fsockopen($host, $port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            return new RejectedPromise(new Exception('Unable to open connection: "'.$errstr.'" ('.$errno.')'));
        } else {
            return new FulfilledPromise(new Stream($socket, $this->loop));
        }
    }
}
