<?php

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

    public function getConnection($callback, $host, $port)
    {
        $socket = fsockopen($host, $port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            $stream = null;
            $error = new Exception('Unable to open connection: "'.$errstr.'" ('.$errno.')');
        } else {
            $stream = new Stream($socket, $this->loop);
            $error = null;
        }
        call_user_func($callback, $stream, $error);
    }
}
