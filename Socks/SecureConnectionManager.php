<?php

namespace Socks;

use React\Promise\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Stream\Stream;
use React\HttpClient\ConnectionManagerInterface;

class SecureConnectionManager implements ConnectionManagerInterface
{
    private $connectionManager;

    public function __construct(ConnectionManagerInterface $connectionManager, LoopInterface $loop)
    {
        $this->connectionManager = $connectionManager;
        $this->loop = $loop;
    }

    public function getConnection($host, $port)
    {
        $that = $this;
        return $this->connectionManager->getConnection($host, $port)->then(function (Stream $stream) use ($that) {
            return $that->handleConnectedSocket($stream->stream);
        });
    }

    public function handleConnectedSocket($socket)
    {
        $that = $this;

        $deferred = new Deferred();

        $enableCrypto = function () use ($that, $socket, $deferred) {
            $that->enableCrypto($socket, $deferred);
        };

        $this->loop->addWriteStream($socket, $enableCrypto);
        $this->loop->addReadStream($socket, $enableCrypto);
        $enableCrypto();

        return $deferred->promise();
    }

    public function enableCrypto($socket, ResolverInterface $resolver)
    {
        $result = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if (true === $result) {
            $this->loop->removeWriteStream($socket);
            $this->loop->removeReadStream($socket);

            $resolver->resolve(new Stream($socket, $this->loop));
        } else if (false === $result) {
            $this->loop->removeWriteStream($socket);
            $this->loop->removeReadStream($socket);

            $resolver->reject();
        } else {
            //echo 'again';
            // need more data, will retry
        }
    }
}
