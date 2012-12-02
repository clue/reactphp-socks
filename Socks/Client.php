<?php

namespace Socks;

use React\Promise\When;
use React\Promise\Deferred;
use React\HttpClient\Client as HttpClient;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use React\HttpClient\ConnectionManagerInterface;
use \Exception;
use \InvalidArgumentException;

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

    private $timeout;

    /**
     * @var LoopInterface
     */
    protected $loop;

    private $resolveLocal = true;

    private $protocolVersion = '4a';

    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager, Resolver $resolver, $socksHost, $socksPort)
    {
        $this->loop = $loop;
        $this->connectionManager = $connectionManager;
        $this->socksHost = $socksHost;
        $this->socksPort = $socksPort;
        $this->resolver = $resolver;
        $this->timeout = ini_get("default_socket_timeout");
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    public function setResolveLocal($resolveLocal)
    {
        $this->resolveLocal = $resolveLocal;
    }

    public function setProtocolVersion($version)
    {
        $version = (string)$version;
        if (!in_array($version, array('4','4a','5'), true)) {
            throw new InvalidArgumentException('Invalid protocol version given');
        }
        $this->protocolVersion = $version;
    }

    public function createHttpClient()
    {
        return new HttpClient($this->loop, $this, new SecureConnectionManager($this, $this->loop));
    }

    public function getConnection($host, $port)
    {
        $deferred = new Deferred();

        $timestampTimeout = microtime(true) + $this->timeout;
        $timerTimeout = $this->loop->addTimer($this->timeout, function () use ($deferred) {
            $deferred->reject(new Exception('Timeout while connecting to socks server'));
            // TODO: stop initiating connection and DNS query
        });

        $protocolVersion = $this->protocolVersion;
        $loop = $this->loop;
        $that = $this;
        When::all(
            array(
                $this->connectionManager->getConnection($this->socksHost, $this->socksPort)->then(
                    null,
                    function ($error) {
                        return new Exception('Unable to connect to socks server', 0, $error);
                    }
                ),
                $this->resolve($host)->then(
                    null,
                    function ($error) {
                        return new Exception('Unable to resolve remote hostname', 0, $error);
                    }
                )
            ),
            function ($fulfilled) use ($deferred, $port, $timestampTimeout, $that, $loop, $timerTimeout, $protocolVersion) {
                $loop->cancelTimer($timerTimeout);

                $timeout = max($timestampTimeout - microtime(true), 0.1);
                $deferred->resolve($that->handleConnectedSocks($fulfilled[0], $fulfilled[1], $port, $timeout, $protocolVersion));
            },
            function ($error) use ($deferred, $loop, $timerTimeout) {
                $loop->cancelTimer($timerTimeout);
                $deferred->reject(new Exception('Unable to connect to socks server', 0, $error));
            }
        );
        return $deferred->promise();
    }

    private function resolve($host)
    {
        // return if it's already an IP or we want to resolve remotely (socks 4 only supports resolving locally)
        if (false !== filter_var($host, FILTER_VALIDATE_IP) || ($this->protocolVersion !== '4' && !$this->resolveLocal)) {
            return When::resolve($host);
        }

        return $this->resolver->resolve($host);
    }

    public function handleConnectedSocks(Stream $stream, $host, $port, $timeout, $protocolVersion)
    {
        $deferred = new Deferred();
        $resolver = $deferred->resolver();

        $timerTimeout = $this->loop->addTimer($timeout, function () use ($resolver) {
            $resolver->reject(new Exception('Timeout while establishing socks session'));
        });

        if ($protocolVersion === '5') {
            $promise = $this->handleSocks5($stream, $host, $port);
        } else {
            $promise = $this->handleSocks4($stream, $host, $port);
        }
        $promise->then(function () use ($resolver, $stream) {
            $resolver->resolve($stream);
        }, function($error) use ($resolver) {
            $resolver->reject(new Exception('Unable to communicate...', 0, $error));
        });

        $loop = $this->loop;
        $deferred->then(
            function (Stream $stream) use ($timerTimeout, $loop) {
                $loop->cancelTimer($timerTimeout);
                $stream->removeAllListeners('end');
                return $stream;
            },
            function ($error) use ($stream, $timerTimeout, $loop) {
                $loop->cancelTimer($timerTimeout);
                $stream->close();
                return $error;
            }
        );

        $stream->on('end',function (Stream $stream) use ($resolver) {
            $resolver->reject(new Exception('Premature end while establishing socks session'));
        });

        return $deferred->promise();
    }

    protected function handleSocks4($stream, $host, $port)
    {
        $ip = ip2long($host);                                                   // do not resolve hostname. only try to convert to IP
        $data = pack('C2nNC', 0x04, 0x01, $port, $ip === false ? 1 : $ip, 0x00); // send IP or (0.0.0.1) if invalid
        if ($ip === false) {                                                   // host is not a valid IP => send along hostname
            $data .= $host.pack('C',0x00);
        }
        $stream->write($data);

        return $this->readBinary($stream, array(
            'null'   => 'C',
            'status' => 'C',
            'port'   => 'n',
            'ip'     => 'N'
        ))->then(function ($data) {
            if ($data['null'] !== 0x00 || $data['status'] !== 0x5a) {
                throw new Exception('Invalid SOCKS response');
            }
        });
    }

    protected function handleSocks5(Stream $stream, $host, $port)
    {
        // protocol version 5, one method, no authentication
        $data = pack('C3',0x05,0x01,0x00);
        $stream->write($data);

        $that = $this;
        return $this->readBinary($stream, array(
                'version' => 'C',
                'method'  => 'C'
        ))->then(function ($data) {
            if ($data['version'] !== 0x05) {
                throw new Exception('Version/Protocol mismatch');
            } else if ($data['method'] !== 0x00) {                            // any other method than "no authentication"
                throw new Exception('Unacceptable authentication method requested');
            }
        })->then(function () use ($stream, $that, $host, $port) {
            $ip = @inet_pton($host);                                            // do not resolve hostname. only try to convert to (binary/packed) IP

            $data = pack('C3',0x05,0x01,0x00);
            if($ip === false){                                                     // not an IP, send as hostname
                $data .= pack('C2',0x03,strlen($host)).$host;
            }else{                                                                 // send as IPv4 / IPv6
                $data .= pack('C',(strpos($host,':') === false) ? 0x01 : 0x04).$ip;
            }
            $data .= pack('n',$port);

            $stream->write($data);

            return $that->readBinary($stream, array(
                'version' => 'C',
                'status'  => 'C',
                'null'    => 'C',
                'type'    => 'C'
            ));
        })->then(function ($data) use ($stream, $that) {
            if($data['version'] !== 0x05 || $data['status'] !== 0x00 || $data['null'] !== 0x00){
                throw new Exception('Invalid SOCKS response');
            }
            if($data['type'] === 0x01){                                             // ipv4 address
                // $this->streamRead($stream,6);                                       // skip IP and port
                return $that->readLength($stream, 6);
            }else if($data['type'] === 0x03){                                      // domain name
                // read domain name length
                return $that->readBinary($stream, array(
                    'length' => 'C'
                ))->then(function ($data) use ($stream, $that) {
                    // skip domain name and port
                    return $that->readLength($stream, $data['length'] + 2);
                });
            }else if($data['type'] === 0x04){                                      // IPv6 address
                // $this->streamRead($stream,18);                                      // skip IP and port
                return $that->readLength($stream, 18);
            }else{
                throw new Exception('Invalid SOCKS reponse: Invalid address type');
            }
        });
    }

    public function readBinary(Stream $stream, $structure)
    {
        $deferred = new Deferred();

        $length = 0;
        $unpack = '';
        foreach ($structure as $name=>$format) {
            if ($length !== 0) {
                $unpack .= '/';
            }
            $unpack .= $format . $name;

            if ($format === 'C') {
                ++$length;
            } else if ($format === 'n') {
                $length += 2;
            } else if ($format === 'N') {
                $length += 4;
            } else {
                throw new Exception('Invalid format given');
            }
        }

        $this->readLength($stream, $length)->then(function ($response) use ($unpack, $deferred) {
            $data = unpack($unpack, $response);
            $deferred->resolve($data);
        });

        return $deferred->promise();
    }

    public function readLength(Stream $stream, $bytes)
    {
        $deferred = new Deferred();
        $oldsize = $stream->bufferSize;
        $stream->bufferSize = $bytes;

        $buffer = '';

        $fn = function ($data, Stream $stream) use (&$buffer, &$bytes, $deferred, $oldsize, &$fn) {
            $bytes -= strlen($data);
            $buffer .= $data;

            if ($bytes === 0) {
                $stream->bufferSize = $oldsize;
                $stream->removeListener('data', $fn);

                $deferred->resolve($buffer);
            } else {
                $stream->bufferSize = $bytes;
            }
        };
        $stream->on('data', $fn);
        return $deferred->promise();
    }
}
