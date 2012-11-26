<?php

use React\Promise\Deferred;

use React\HttpClient\Client as HttpClient;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use React\HttpClient\ConnectionManagerInterface;
use \Exception;

class Client implements ConnectionManagerInterface
{
    /**
     *
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     *
     * @var Resolver
     */
    private $resolver;

    private $socksHost;

    private $socksPort;

    private $timeout = 5.0;

    /**
     * @var LoopInterface
     */
    protected $loop;

    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager, Resolver $resolver, $socksHost, $socksPort)
    {
        $this->loop = $loop;
        $this->connectionManager = $connectionManager;
        $this->socksHost = $socksHost;
        $this->socksPort = $socksPort;
    }

    public function createHttpClient()
    {
        // TODO: fix secure connection manager in last parameter
        return new HttpClient($this->loop, $this, $this);
    }

    public function getConnection($host, $port)
    {
        $deferred = new Deferred();

        $timeout = microtime(true) + $this->timeout;
        $timerTimeout = $this->loop->addTimer($this->timeout, function () use ($deferred) {
            $deferred->reject(new Exception('Timeout while connecting to socks server'));
            // TODO: stop initiating connection
        });

        $loop = $this->loop;
        $that = $this;
        return $this->connectionManager->getConnection($this->socksHost, $this->socksPort)->then(
            function ($stream) use ($deferred, $host, $port, $timeout, $timerTimeout, $loop, $that) {
                $loop->cancelTimer($timerTimeout);
                return $deferred->resolve($that->handleConnectedSocks($stream, $host, $port, $timeout));
            },
            function ($error) use ($deferred) {
                $deferred->reject(new Exception('Unable to connect to socks server', 0, $error));
            }
        );
        return $deferred->promise();
    }

    public function handleConnectedSocks(Stream $stream, $host, $port, $timeout)
    {
        $deferred = new Deferred();
        $resolver = $deferred->resolver();

        $timerTimeout = $this->loop->addTimer(max($timeout - microtime(true), 0.1), function () use ($resolver) {
            $resolver->reject(new Exception('Timeout while establishing socks session'));
        });

        $ip = ip2long($host);                                                   // do not resolve hostname. only try to convert to IP
        $data = pack('C2nNC', 0x04, 0x01, $port, $ip === false ? 1 : $ip, 0x00); // send IP or (0.0.0.1) if invalid
        if ($ip === false) {                                                   // host is not a valid IP => send along hostname
            $data .= $host.pack('C',0x00);
        }
        $stream->write($data);

        $loop = $this->loop;
        $result = function ($error=null) use (&$fnEndPremature, $stream, $timerTimeout, $loop) {
            $loop->cancelTimer($timerTimeout);
            $stream->removeListener('end', $fnEndPremature);
            $stream->removeAllListeners('timeout');
            $stream->removeAllListeners('data');
        };

        $fnEndPremature = function (Stream $stream) use ($resolver) {
            $resolver->reject(new Exception('Premature end while establishing socks session'));
        };
        $stream->on('end',$fnEndPremature);


        $this->readLength(function ($response, Stream $stream) use ($resolver) {
            $data = unpack('Cnull/Cstatus/nport/Nip', $response);

            if ($data['null'] !== 0x00 || $data['status'] !== 0x5a) {
                $resolver->reject(new Exception('Invalid SOCKS response'));
            }

            $stream->bufferSize = 1024;
            $resolver->resolve($stream);
        }, $stream, 8);

        return $deferred->promise();
    }

    protected function readLength($callback, Stream $stream, $bytes)
    {
        $stream->bufferSize = $bytes;

        $buffer = '';
        $stream->on('data', function ($data, Stream $stream) use (&$buffer, &$bytes, $callback) {
            $bytes -= strlen($data);
            $buffer .= $data;

            if ($bytes === 0) {
                call_user_func($callback, $buffer, $stream);
            } else {
                $stream->bufferSize = $bytes;
            }
        });
    }
}
