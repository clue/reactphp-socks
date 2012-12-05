<?php

namespace Socks;

use React\Promise\ResolverInterface;
use React\Promise\Deferred;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use \UnexpectedValueException;

class StreamEncryption
{
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function enable(Stream $stream)
    {
        // pause actual stream instance to continue operation on raw stream socket
        $stream->pause();

        // TODO: add write() event to make sure we're not sending any excessive data

        $deferred = new Deferred();

        // get actual stream socket from stream instance
        $socket = $stream->stream;

        $that = $this;
        $enableCrypto = function () use ($that, $socket, $deferred) {
            $that->enableCrypto($socket, $deferred);
        };

        $this->loop->addWriteStream($socket, $enableCrypto);
        $this->loop->addReadStream($socket, $enableCrypto);
        $enableCrypto();

        return $deferred->then(function () use ($stream) {
            $stream->resume();
            return $stream;
        }, function($error) use ($stream) {
            $stream->resume();
            throw $error;
        });
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

            $resolver->resolve();
        } else if (false === $result) {
            $this->loop->removeWriteStream($socket);
            $this->loop->removeReadStream($socket);

            $resolver->reject(new UnexpectedValueException('Unable to initiate SSL/TLS handshake: "'.$error.'"'));
        } else {
            // need more data, will retry
        }
    }
}
