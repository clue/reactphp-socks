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

    public function __construct(ConnectionManagerInterface $connectionManager, LoopInterface $loop)
    {
        $this->connectionManager = $connectionManager;
        $this->loop = $loop;
    }

    public function getConnection($host, $port)
    {
        $that = $this;
        return $this->connectionManager->getConnection($host, $port)->then(function (Stream $stream) use ($that) {
            // get actual stream socket from stream instance
            $socket = $stream->stream;

            // pause and close actual stream instance to continue operation on raw stream socket
            $stream->pause();
            $stream->stream = false;
            $stream->close();

            return $that->handleConnectedSocket($socket);
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
        $error = 'unknown error';
        set_error_handler(function ($errno, $errstr) use (&$error) {
            $error = str_replace(array("\r","\n"),' ',$errstr);
        });

        $result = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        restore_error_handler();

        if (true === $result) {
            $this->loop->removeWriteStream($socket);
            $this->loop->removeReadStream($socket);

            $resolver->resolve(new Stream($socket, $this->loop));
        } else if (false === $result) {
            $this->loop->removeWriteStream($socket);
            $this->loop->removeReadStream($socket);

            $resolver->reject(new UnexpectedValueException('Unable to initiate SSL/TLS handshake: "'.$error.'"'));
        } else {
            // need more data, will retry
        }
    }
}
