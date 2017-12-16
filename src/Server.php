<?php

namespace Clue\React\Socks;

use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;
use \UnexpectedValueException;
use \InvalidArgumentException;
use \Exception;
use React\Promise\Timer\TimeoutException;

class Server extends EventEmitter
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

    protected $loop;

    private $connector;

    private $auth = null;

    private $protocolVersion = null;

    public function __construct(LoopInterface $loop, ServerInterface $serverInterface, ConnectorInterface $connector = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
        }

        $this->loop = $loop;
        $this->connector = $connector;

        $that = $this;
        $serverInterface->on('connection', function ($connection) use ($that) {
            $that->emit('connection', array($connection));
            $that->onConnection($connection);
        });
    }

    public function setProtocolVersion($version)
    {
        if ($version !== null) {
            $version = (string)$version;
            if (!in_array($version, array('4', '4a', '5'), true)) {
                throw new InvalidArgumentException('Invalid protocol version given');
            }
            if ($version !== '5' && $this->auth !== null){
                throw new UnexpectedValueException('Unable to change protocol version to anything but SOCKS5 while authentication is used. Consider removing authentication info or sticking to SOCKS5');
            }
        }
        $this->protocolVersion = $version;
    }

    public function setAuth($auth)
    {
        if (!is_callable($auth)) {
            throw new InvalidArgumentException('Given authenticator is not a valid callable');
        }
        if ($this->protocolVersion !== null && $this->protocolVersion !== '5') {
            throw new UnexpectedValueException('Authentication requires SOCKS5. Consider using protocol version 5 or waive authentication');
        }
        // wrap authentication callback in order to cast its return value to a promise
        $this->auth = function($username, $password, $remote) use ($auth) {
            $ret = call_user_func($auth, $username, $password, $remote);
            if ($ret instanceof PromiseInterface) {
                return $ret;
            }
            $deferred = new Deferred();
            $ret ? $deferred->resolve() : $deferred->reject();
            return $deferred->promise();
        };
    }

    public function setAuthArray(array $login)
    {
        $this->setAuth(function ($username, $password) use ($login) {
            return (isset($login[$username]) && (string)$login[$username] === $password);
        });
    }

    public function unsetAuth()
    {
        $this->auth = null;
    }

    public function onConnection(ConnectionInterface $connection)
    {
        $that = $this;
        $handling = $this->handleSocks($connection)->then(function($remote) use ($connection){
            $connection->emit('ready',array($remote));
        }, function ($error) use ($connection, $that) {
            if (!($error instanceof \Exception)) {
                $error = new \Exception($error);
            }
            $connection->emit('error', array($error));
            $that->endConnection($connection);
        });

        $connection->on('close', function () use ($handling) {
            $handling->cancel();
        });
    }

    /**
     * gracefully shutdown connection by flushing all remaining data and closing stream
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
        $that = $this;

        $auth = $this->auth;
        $protocolVersion = $this->protocolVersion;

        // authentication requires SOCKS5
        if ($auth !== null) {
        	$protocolVersion = '5';
        }

        return $reader->readByte()->then(function ($version) use ($stream, $that, $protocolVersion, $auth, $reader){
            if ($version === 0x04) {
                if ($protocolVersion === '5') {
                    throw new UnexpectedValueException('SOCKS4 not allowed due to configuration');
                }
                return $that->handleSocks4($stream, $protocolVersion, $reader);
            } else if ($version === 0x05) {
                if ($protocolVersion !== null && $protocolVersion !== '5') {
                    throw new UnexpectedValueException('SOCKS5 not allowed due to configuration');
                }
                return $that->handleSocks5($stream, $auth, $reader);
            }
            throw new UnexpectedValueException('Unexpected/unknown version number');
        });
    }

    public function handleSocks4(ConnectionInterface $stream, $protocolVersion, StreamReader $reader)
    {
        // suppliying hostnames is only allowed for SOCKS4a (or automatically detected version)
        $supportsHostname = ($protocolVersion === null || $protocolVersion === '4a');

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
        })->then(function ($data) use ($reader, $supportsHostname, $remote) {
            if ($data['null'] !== 0x00) {
                throw new Exception('Not a null byte');
            }
            if ($data['ipLong'] === 0) {
                throw new Exception('Invalid IP');
            }
            if ($data['port'] === 0) {
                throw new Exception('Invalid port');
            }
            if ($data['ipLong'] < 256 && $supportsHostname) {
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

    public function handleSocks5(ConnectionInterface $stream, $auth=null, StreamReader $reader)
    {
        $remote = $stream->getRemoteAddress();
        if ($remote !== null) {
            // remove transport scheme and prefix socks5:// instead
            $secure = strpos($remote, 'tls://') === 0;
            if (($pos = strpos($remote, '://')) !== false) {
                $remote = substr($remote, $pos + 3);
            }
            $remote = 'socks5' . ($secure ? 's' : '') . '://' . $remote;
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

                        return $auth($username, $password, $remote)->then(function () use ($stream, $username) {
                            // accept
                            $stream->emit('auth', array($username));
                            $stream->write(pack('C2', 0x01, 0x00));
                        }, function() use ($stream) {
                            // reject => send any code but 0x00
                            $stream->end(pack('C2', 0x01, 0xFF));
                            throw new UnexpectedValueException('Unable to authenticate');
                        });
                    });
                });
            } else {
                // reject all offered authentication methods
                $stream->write(pack('C2', 0x05, 0xFF));
                throw new UnexpectedValueException('No acceptable authentication mechanism found');
            }
        })->then(function ($method) use ($reader, $stream) {
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
            return Promise\reject(new InvalidArgumentException('Invalid target URI given'));
        }

        if (isset($target[2])) {
            $uri .= '?source=' . rawurlencode($target[2]);
        }

        $stream->emit('target', $target);
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
                $stream->emit('shutdown', array('remote', null));
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
