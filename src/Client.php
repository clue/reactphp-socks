<?php

namespace Clue\React\Socks;

use React\Promise;
use React\Promise\CancellablePromiseInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use React\Stream\Stream;
use React\SocketClient\ConnectorInterface;
use \Exception;
use \InvalidArgumentException;
use RuntimeException;

class Client implements ConnectorInterface
{
    /**
     *
     * @var ConnectorInterface
     */
    private $connector;

    private $socksHost;

    private $socksPort;

    private $protocolVersion = null;

    private $auth = null;

    public function __construct($socksUri, ConnectorInterface $connector)
    {
        // assume default scheme if none is given
        if (strpos($socksUri, '://') === false) {
            $socksUri = 'socks://' . $socksUri;
        }

        // parse URI into individual parts
        $parts = parse_url($socksUri);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid SOCKS server URI "' . $socksUri . '"');
        }

        // assume default port
        if (!isset($parts['port'])) {
            $parts['port'] = 1080;
        }

        // user or password in URI => SOCKS5 authentication
        if (isset($parts['user']) || isset($parts['pass'])) {
            if ($parts['scheme'] === 'socks') {
                // default to using SOCKS5 if not given explicitly
                $parts['scheme'] = 'socks5';
            } elseif ($parts['scheme'] !== 'socks5') {
                // fail if any other protocol version given explicitly
                throw new InvalidArgumentException('Authentication requires SOCKS5. Consider using protocol version 5 or waive authentication');
            }
            $parts += array('user' => '', 'pass' => '');
            $this->setAuth(rawurldecode($parts['user']), rawurldecode($parts['pass']));
        }

        // check for valid protocol version from URI scheme
        $this->setProtocolVersionFromScheme($parts['scheme']);

        $this->socksHost = $parts['host'];
        $this->socksPort = $parts['port'];
        $this->connector = $connector;
    }

    private function setProtocolVersionFromScheme($scheme)
    {
        if ($scheme === 'socks' || $scheme === 'socks4a') {
            $this->protocolVersion = '4a';
        } elseif ($scheme === 'socks5') {
            $this->protocolVersion = '5';
        } elseif ($scheme === 'socks4') {
            $this->protocolVersion = '4';
        } else {
            throw new InvalidArgumentException('Invalid protocol version given');
        }
    }

    /**
     * set login data for username/password authentication method (RFC1929)
     *
     * @param string $username
     * @param string $password
     * @link http://tools.ietf.org/html/rfc1929
     */
    private function setAuth($username, $password)
    {
        if (strlen($username) > 255 || strlen($password) > 255) {
            throw new InvalidArgumentException('Both username and password MUST NOT exceed a length of 255 bytes each');
        }
        $this->auth = pack('C2', 0x01, strlen($username)) . $username . pack('C', strlen($password)) . $password;
    }

    /**
     * Establish a TCP/IP connection to the given target host and port through the SOCKS server
     *
     * Many higher-level networking protocols build on top of TCP. It you're dealing
     * with one such client implementation,  it probably uses/accepts an instance
     * implementing React's `ConnectorInterface` (and usually its default `Connector`
     * instance). In this case you can also pass this `Connector` instance instead
     * to make this client implementation SOCKS-aware. That's it.
     *
     * @param string $host
     * @param int    $port
     * @return PromiseInterface Promise<Stream,Exception>
     */
    public function create($host, $port)
    {
        if ($this->protocolVersion === '4' && false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return Promise\reject(new InvalidArgumentException('Requires an IPv4 address for SOCKS4'));
        }

        if (strlen($host) > 255 || $port > 65535 || $port < 0 || (string)$port !== (string)(int)$port) {
            return Promise\reject(new InvalidArgumentException('Invalid target specified'));
        }

        $that = $this;

        // start TCP/IP connection to SOCKS server and then
        // handle SOCKS protocol once connection is ready
        // resolve plain connection once SOCKS protocol is completed
        return $this->connect($this->socksHost, $this->socksPort)->then(
            function (Stream $stream) use ($that, $host, $port) {
                return $that->handleConnectedSocks($stream, $host, $port);
            }
        );
    }

    private function connect($host, $port)
    {
        $promise = $this->connector->create($host, $port);

        return new Promise\Promise(
            function ($resolve, $reject) use ($promise) {
                // resolve/reject with result of TCP/IP connection
                return $promise->then($resolve, function (Exception $reason) use ($reject) {
                    $reject(new \RuntimeException('Unable to connect to SOCKS server', 0, $reason));
                });
            },
            function ($_, $reject) use ($promise) {
                // cancellation should reject connection attempt
                $reject(new RuntimeException('Connection attempt cancelled while connecting to SOCKS server'));

                // forefully close TCP/IP connection if it completes despite cancellation
                $promise->then(function (Stream $stream) {
                    $stream->close();
                });

                // (try to) cancel pending TCP/IP connection
                if ($promise instanceof CancellablePromiseInterface) {
                    $promise->cancel();
                }
            }
        );
    }

    /**
     * Internal helper used to handle the communication with the SOCKS server
     *
     * @param Stream      $stream
     * @param string      $host
     * @param int         $port
     * @return Promise Promise<stream, Exception>
     * @internal
     */
    public function handleConnectedSocks(Stream $stream, $host, $port)
    {
        $deferred = new Deferred(function ($_, $reject) {
            $reject(new RuntimeException('Connection attempt cancelled while establishing socks session'));
        });

        $reader = new StreamReader($stream);
        $stream->on('data', array($reader, 'write'));

        if ($this->protocolVersion === '5') {
            $promise = $this->handleSocks5($stream, $host, $port, $reader);
        } else {
            $promise = $this->handleSocks4($stream, $host, $port, $reader);
        }
        $promise->then(function () use ($deferred, $stream) {
            $deferred->resolve($stream);
        }, function($error) use ($deferred) {
            $deferred->reject(new Exception('Unable to communicate...', 0, $error));
        });

        $deferred->promise()->then(
            function (Stream $stream) use ($reader) {
                $stream->removeAllListeners('end');

                $stream->removeListener('data', array($reader, 'write'));

                return $stream;
            },
            function ($error) use ($stream) {
                $stream->close();

                return $error;
            }
        );

        $stream->on('end', function (Stream $stream) use ($deferred) {
            $deferred->reject(new Exception('Premature end while establishing socks session'));
        });

        return $deferred->promise();
    }

    private function handleSocks4(Stream $stream, $host, $port, StreamReader $reader)
    {
        // do not resolve hostname. only try to convert to IP
        $ip = ip2long($host);

        // send IP or (0.0.0.1) if invalid
        $data = pack('C2nNC', 0x04, 0x01, $port, $ip === false ? 1 : $ip, 0x00);

        if ($ip === false) {
            // host is not a valid IP => send along hostname (SOCKS4a)
            $data .= $host . pack('C', 0x00);
        }

        $stream->write($data);

        return $reader->readBinary(array(
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

    private function handleSocks5(Stream $stream, $host, $port, StreamReader $reader)
    {
        // protocol version 5
        $data = pack('C', 0x05);

        $auth = $this->auth;
        if ($auth === null) {
            // one method, no authentication
            $data .= pack('C2', 0x01, 0x00);
        } else {
            // two methods, username/password and no authentication
            $data .= pack('C3', 0x02, 0x02, 0x00);
        }
        $stream->write($data);

        $that = $this;

        return $reader->readBinary(array(
            'version' => 'C',
            'method'  => 'C'
        ))->then(function ($data) use ($auth, $stream, $reader) {
            if ($data['version'] !== 0x05) {
                throw new Exception('Version/Protocol mismatch');
            }

            if ($data['method'] === 0x02 && $auth !== null) {
                // username/password authentication requested and provided
                $stream->write($auth);

                return $reader->readBinary(array(
                    'version' => 'C',
                    'status'  => 'C'
                ))->then(function ($data) {
                    if ($data['version'] !== 0x01 || $data['status'] !== 0x00) {
                        throw new Exception('Username/Password authentication failed');
                    }
                });
            } else if ($data['method'] !== 0x00) {
                // any other method than "no authentication"
                throw new Exception('Unacceptable authentication method requested');
            }
        })->then(function () use ($stream, $reader, $host, $port) {
            // do not resolve hostname. only try to convert to (binary/packed) IP
            $ip = @inet_pton($host);

            $data = pack('C3', 0x05, 0x01, 0x00);
            if ($ip === false) {
                // not an IP, send as hostname
                $data .= pack('C2', 0x03, strlen($host)) . $host;
            } else {
                // send as IPv4 / IPv6
                $data .= pack('C', (strpos($host, ':') === false) ? 0x01 : 0x04) . $ip;
            }
            $data .= pack('n', $port);

            $stream->write($data);

            return $reader->readBinary(array(
                'version' => 'C',
                'status'  => 'C',
                'null'    => 'C',
                'type'    => 'C'
            ));
        })->then(function ($data) use ($reader) {
            if ($data['version'] !== 0x05 || $data['status'] !== 0x00 || $data['null'] !== 0x00) {
                throw new Exception('Invalid SOCKS response');
            }
            if ($data['type'] === 0x01) {
                // IPv4 address => skip IP and port
                return $reader->readLength(6);
            } else if ($data['type'] === 0x03) {
                // domain name => read domain name length
                return $reader->readBinary(array(
                    'length' => 'C'
                ))->then(function ($data) use ($that) {
                    // skip domain name and port
                    return $that->readLength($data['length'] + 2);
                });
            } else if ($data['type'] === 0x04) {
                // IPv6 address => skip IP and port
                return $reader->readLength(18);
            } else {
                throw new Exception('Invalid SOCKS reponse: Invalid address type');
            }
        });
    }
}
