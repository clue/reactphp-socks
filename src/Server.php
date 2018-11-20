<?php

namespace Clue\React\Socks;

use React\Socket\ServerInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;
use \UnexpectedValueException;
use \InvalidArgumentException;
use \Exception;
use React\Promise\Timer\TimeoutException;

final class Server
{
    // the following error codes are only used for SOCKS5 only
    /** @internal */
    const ERROR_GENERAL = 0x01;
    /** @internal */
    const ERROR_NOT_ALLOWED_BY_RULESET = 0x02;
    /** @internal */
    const ERROR_NETWORK_UNREACHABLE = 0x03;
    /** @internal */
    const ERROR_HOST_UNREACHABLE = 0x04;
    /** @internal */
    const ERROR_CONNECTION_REFUSED = 0x05;
    /** @internal */
    const ERROR_TTL = 0x06;
    /** @internal */
    const ERROR_COMMAND_UNSUPPORTED = 0x07;
    /** @internal */
    const ERROR_ADDRESS_UNSUPPORTED = 0x08;

    private $loop;

    private $connector;

    /**
     * @var null|callable
     */
    private $auth;

    /**
     * @param LoopInterface           $loop
     * @param null|ConnectorInterface $connector
     * @param null|array|callable     $auth
     */
    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null, $auth = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
        }

        if (\is_array($auth)) {
            // wrap authentication array in authentication callback
            $this->auth = function ($username, $password) use ($auth) {
                return \React\Promise\resolve(
                    isset($auth[$username]) && (string)$auth[$username] === $password
                );
            };
        } elseif (\is_callable($auth)) {
            // wrap authentication callback in order to cast its return value to a promise
            $this->auth = function($username, $password, $remote) use ($auth) {
                return  \React\Promise\resolve(
                    \call_user_func($auth, $username, $password, $remote)
                );
            };
        } elseif ($auth !== null) {
            throw new \InvalidArgumentException('Invalid authenticator given');
        }

        $this->loop = $loop;
        $this->connector = $connector;
    }

    /**
     * @param ServerInterface $socket
     * @return void
     */
    public function listen(ServerInterface $socket)
    {
        $that = $this;
        $socket->on('connection', function ($connection) use ($that) {
            $that->onConnection($connection);
        });
    }

    /** @internal */
    public function onConnection(ConnectionInterface $connection)
    {
        $that = $this;
        $handling = $this->handleSocks($connection)->then(null, function () use ($connection, $that) {
            // SOCKS failed => close connection
            $that->endConnection($connection);
        });

        $connection->on('close', function () use ($handling) {
            $handling->cancel();
        });
    }

    /**
     * [internal] gracefully shutdown connection by flushing all remaining data and closing stream
     *
     * @internal
     */
    public function endConnection(ConnectionInterface $stream)
    {
        $tid = true;
        $loop = $this->loop;

        // cancel below timer in case connection is closed in time
        $stream->once('close', function () use (&$tid, $loop) {
            // close event called before the timer was set up, so everything is okay
            if ($tid === true) {
                // make sure to not start a useless timer
                $tid = false;
            } else {
                $loop->cancelTimer($tid);
            }
        });

        // shut down connection by pausing input data, flushing outgoing buffer and then exit
        $stream->pause();
        $stream->end();

        // check if connection is not already closed
        if ($tid === true) {
            // fall back to forcefully close connection in 3 seconds if buffer can not be flushed
            $tid = $loop->addTimer(3.0, array($stream,'close'));
        }
    }

    private function handleSocks(ConnectionInterface $stream)
    {
        $reader = new StreamReader();
        $stream->on('data', array($reader, 'write'));

        $that = $this;
        $auth = $this->auth;

        return $reader->readByte()->then(function ($version) use ($stream, $that, $auth, $reader){
            if ($version === 0x04) {
                if ($auth !== null) {
                    throw new UnexpectedValueException('SOCKS4 not allowed because authentication is required');
                }
                return $that->handleSocks4($stream, $reader);
            } else if ($version === 0x05) {
                return $that->handleSocks5($stream, $auth, $reader);
            }
            throw new UnexpectedValueException('Unexpected/unknown version number');
        });
    }

    /** @internal */
    public function handleSocks4(ConnectionInterface $stream, StreamReader $reader)
    {
        $remote = $stream->getRemoteAddress();
        if ($remote !== null) {
            // remove transport scheme and prefix socks4:// instead
            $secure = strpos($remote, 'tls://') === 0;
            if (($pos = strpos($remote, '://')) !== false) {
                $remote = substr($remote, $pos + 3);
            }
            $remote = 'socks4' . ($secure ? 's' : '') . '://' . $remote;
        }

        $that = $this;
        return $reader->readByteAssert(0x01)->then(function () use ($reader) {
            return $reader->readBinary(array(
                'port'   => 'n',
                'ipLong' => 'N',
                'null'   => 'C'
            ));
        })->then(function ($data) use ($reader, $remote) {
            if ($data['null'] !== 0x00) {
                throw new Exception('Not a null byte');
            }
            if ($data['ipLong'] === 0) {
                throw new Exception('Invalid IP');
            }
            if ($data['port'] === 0) {
                throw new Exception('Invalid port');
            }
            if ($data['ipLong'] < 256) {
                // invalid IP => probably a SOCKS4a request which appends the hostname
                return $reader->readStringNull()->then(function ($string) use ($data, $remote){
                    return array($string, $data['port'], $remote);
                });
            } else {
                $ip = long2ip($data['ipLong']);
                return array($ip, $data['port'], $remote);
            }
        })->then(function ($target) use ($stream, $that) {
            return $that->connectTarget($stream, $target)->then(function (ConnectionInterface $remote) use ($stream){
                $stream->write(pack('C8', 0x00, 0x5a, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00));

                return $remote;
            }, function($error) use ($stream){
                $stream->end(pack('C8', 0x00, 0x5b, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00));

                throw $error;
            });
        }, function($error) {
            throw new UnexpectedValueException('SOCKS4 protocol error',0,$error);
        });
    }

    /** @internal */
    public function handleSocks5(ConnectionInterface $stream, $auth, StreamReader $reader)
    {
        $remote = $stream->getRemoteAddress();
        if ($remote !== null) {
            // remove transport scheme and prefix socks5:// instead
            $secure = strpos($remote, 'tls://') === 0;
            if (($pos = strpos($remote, '://')) !== false) {
                $remote = substr($remote, $pos + 3);
            }
            $remote = 'socks' . ($secure ? 's' : '') . '://' . $remote;
        }

        $that = $this;
        return $reader->readByte()->then(function ($num) use ($reader) {
            // $num different authentication mechanisms offered
            return $reader->readLength($num);
        })->then(function ($methods) use ($reader, $stream, $auth, &$remote) {
            if ($auth === null && strpos($methods,"\x00") !== false) {
                // accept "no authentication"
                $stream->write(pack('C2', 0x05, 0x00));

                return 0x00;
            } else if ($auth !== null && strpos($methods,"\x02") !== false) {
                // username/password authentication (RFC 1929) sub negotiation
                $stream->write(pack('C2', 0x05, 0x02));
                return $reader->readByteAssert(0x01)->then(function () use ($reader) {
                    return $reader->readByte();
                })->then(function ($length) use ($reader) {
                    return $reader->readLength($length);
                })->then(function ($username) use ($reader, $auth, $stream, &$remote) {
                    return $reader->readByte()->then(function ($length) use ($reader) {
                        return $reader->readLength($length);
                    })->then(function ($password) use ($username, $auth, $stream, &$remote) {
                        // username and password given => authenticate

                        // prefix username/password to remote URI
                        if ($remote !== null) {
                            $remote = str_replace('://', '://' . rawurlencode($username) . ':' . rawurlencode($password) . '@', $remote);
                        }

                        return $auth($username, $password, $remote)->then(function ($authenticated) use ($stream) {
                            if ($authenticated) {
                                // accept auth
                                $stream->write(pack('C2', 0x01, 0x00));
                            } else {
                                // reject auth => send any code but 0x00
                                $stream->end(pack('C2', 0x01, 0xFF));
                                throw new UnexpectedValueException('Authentication denied');
                            }
                        }, function ($e) use ($stream) {
                            // reject failed authentication => send any code but 0x00
                            $stream->end(pack('C2', 0x01, 0xFF));
                            throw new UnexpectedValueException('Authentication error', 0, $e);
                        });
                    });
                });
            } else {
                // reject all offered authentication methods
                $stream->write(pack('C2', 0x05, 0xFF));
                throw new UnexpectedValueException('No acceptable authentication mechanism found');
            }
        })->then(function ($method) use ($reader) {
            return $reader->readBinary(array(
                'version' => 'C',
                'command' => 'C',
                'null'    => 'C',
                'type'    => 'C'
            ));
        })->then(function ($data) use ($reader) {
            if ($data['version'] !== 0x05) {
                throw new UnexpectedValueException('Invalid SOCKS version');
            }
            if ($data['command'] !== 0x01) {
                throw new UnexpectedValueException('Only CONNECT requests supported', Server::ERROR_COMMAND_UNSUPPORTED);
            }
//             if ($data['null'] !== 0x00) {
//                 throw new UnexpectedValueException('Reserved byte has to be NULL');
//             }
            if ($data['type'] === 0x03) {
                // target hostname string
                return $reader->readByte()->then(function ($len) use ($reader) {
                    return $reader->readLength($len);
                });
            } else if ($data['type'] === 0x01) {
                // target IPv4
                return $reader->readLength(4)->then(function ($addr) {
                    return inet_ntop($addr);
                });
            } else if ($data['type'] === 0x04) {
                // target IPv6
                return $reader->readLength(16)->then(function ($addr) {
                    return inet_ntop($addr);
                });
            } else {
                throw new UnexpectedValueException('Invalid address type', Server::ERROR_ADDRESS_UNSUPPORTED);
            }
        })->then(function ($host) use ($reader, &$remote) {
            return $reader->readBinary(array('port'=>'n'))->then(function ($data) use ($host, &$remote) {
                return array($host, $data['port'], $remote);
            });
        })->then(function ($target) use ($that, $stream) {
            return $that->connectTarget($stream, $target);
        }, function($error) use ($stream) {
            throw new UnexpectedValueException('SOCKS5 protocol error', $error->getCode(), $error);
        })->then(function (ConnectionInterface $remote) use ($stream) {
            $stream->write(pack('C4Nn', 0x05, 0x00, 0x00, 0x01, 0, 0));

            return $remote;
        }, function(Exception $error) use ($stream){
            $stream->write(pack('C4Nn', 0x05, $error->getCode() === 0 ? Server::ERROR_GENERAL : $error->getCode(), 0x00, 0x01, 0, 0));

            throw $error;
        });
    }

    /** @internal */
    public function connectTarget(ConnectionInterface $stream, array $target)
    {
        $uri = $target[0];
        if (strpos($uri, ':') !== false) {
            $uri = '[' . $uri . ']';
        }
        $uri .= ':' . $target[1];

        // validate URI so a string hostname can not pass excessive URI parts
        $parts = parse_url('tcp://' . $uri);
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || count($parts) !== 3) {
            return \React\Promise\reject(new InvalidArgumentException('Invalid target URI given'));
        }

        if (isset($target[2])) {
            $uri .= '?source=' . rawurlencode($target[2]);
        }

        $that = $this;
        $connecting = $this->connector->connect($uri);

        $stream->on('close', function () use ($connecting) {
            $connecting->cancel();
        });

        return $connecting->then(function (ConnectionInterface $remote) use ($stream, $that) {
            $stream->pipe($remote, array('end'=>false));
            $remote->pipe($stream, array('end'=>false));

            // remote end closes connection => stop reading from local end, try to flush buffer to local and disconnect local
            $remote->on('end', function() use ($stream, $that) {
                $that->endConnection($stream);
            });

            // local end closes connection => stop reading from remote end, try to flush buffer to remote and disconnect remote
            $stream->on('end', function() use ($remote, $that) {
                $that->endConnection($remote);
            });

            // set bigger buffer size of 100k to improve performance
            $stream->bufferSize = $remote->bufferSize = 100 * 1024 * 1024;

            return $remote;
        }, function(Exception $error) {
            // default to general/unknown error
            $code = Server::ERROR_GENERAL;

            // map common socket error conditions to limited list of SOCKS error codes
            if ((defined('SOCKET_EACCES') && $error->getCode() === SOCKET_EACCES) || $error->getCode() === 13) {
                $code = Server::ERROR_NOT_ALLOWED_BY_RULESET;
            } elseif ((defined('SOCKET_EHOSTUNREACH') && $error->getCode() === SOCKET_EHOSTUNREACH) || $error->getCode() === 113) {
                $code = Server::ERROR_HOST_UNREACHABLE;
            } elseif ((defined('SOCKET_ENETUNREACH') && $error->getCode() === SOCKET_ENETUNREACH) || $error->getCode() === 101) {
                $code = Server::ERROR_NETWORK_UNREACHABLE;
            } elseif ((defined('SOCKET_ECONNREFUSED') && $error->getCode() === SOCKET_ECONNREFUSED) || $error->getCode() === 111 || $error->getMessage() === 'Connection refused') {
                // Socket component does not currently assign an error code for this, so we have to resort to checking the exception message
                $code = Server::ERROR_CONNECTION_REFUSED;
            } elseif ((defined('SOCKET_ETIMEDOUT') && $error->getCode() === SOCKET_ETIMEDOUT) || $error->getCode() === 110 || $error instanceof TimeoutException) {
                // Socket component does not currently assign an error code for this, but we can rely on the TimeoutException
                $code = Server::ERROR_TTL;
            }

            throw new UnexpectedValueException('Unable to connect to remote target', $code, $error);
        });
    }
}
