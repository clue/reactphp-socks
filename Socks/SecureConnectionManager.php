<?php

namespace Socks;

use React\Promise\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Stream\Stream;
use React\HttpClient\ConnectionManagerInterface;
use \UnexpectedValueException;

class SecureConnectionManager implements ConnectionManagerInterface
{
    private $connectionManager;

    private $loop;

    private $streamEncryption;

    public function __construct(ConnectionManagerInterface $connectionManager, LoopInterface $loop)
    {
        $this->connectionManager = $connectionManager;
        $this->loop = $loop;
        $this->streamEncryption = new StreamEncryption($loop);
    }

    public function getConnection($host, $port)
    {
        return $this->connectionManager->getConnection($host, $port)->then(array($this->streamEncryption,'enable'));
    }
}
