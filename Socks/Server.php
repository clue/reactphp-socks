<?php

namespace Socks;

use React\Promise\When;
use React\Promise\PromiseInterface;
use React\Stream\Stream;
use ConnectionManager\ConnectionManagerInterface;
use React\Socket\Connection;
use React\EventLoop\LoopInterface;
use React\Socket\Server as SocketServer;
use \UnexpectedValueException;
use \InvalidArgumentException;
use \Exception;

class Server extends SocketServer
{
    protected $loop;

    private $connectionManager;

    private $auth = null;

    private $protocolVersion = null;

    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager)
    {
        parent::__construct($loop);

        $this->loop = $loop;
        $this->connectionManager = $connectionManager;

        $this->on('connection', array($this, 'onConnection'));
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
        $this->auth = function($username, $password) use ($auth) {
            $ret = call_user_func($auth, $username, $password);
            if ($ret instanceof PromiseInterface) {
                return $ret;
            }
            return $ret ? When::resolve() : When::reject();
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

    public function onConnection(Connection $connection)
    {

        $line = function($msg) use ($connection) {
            echo date('Y-m-d H:i:s') . ' #' . (int)$connection->stream . ' ' . $msg . PHP_EOL;
        };

        $line('connect');

        $loop = $this->loop;
        $this->handleSocks($connection)->then(function($remote) use ($line, $connection){
            $line('tunnel successfully estabslished');
            $connection->emit('ready',array($remote));
        }, function ($error) use ($connection, $line, $loop) {
            if ($error instanceof \Exception) {
                $msg = $error->getMessage();
                while ($error->getPrevious() !== null) {
                    $error = $error->getPrevious();
                    $msg .= ' - ' . $error->getMessage();
                }

                $line('error: ' . $msg);
            } else {
                $line('error');
                var_dump($error);
            }

            // shut down connection by pausing input data, flushing outgoing buffer and then exit
            $connection->pause();
            $connection->end();
            // fall back to forcefully close connection in 3 seconds if buffer can not be flushed
            $loop->addTimer(3.0, array($connection,'close'));

            throw $error;
//         }, function ($progress) use ($line) {
//             //$s = new StreamReader();
//             $line('progress: './*$s->s*/($progress));
        });

        $that = $this;
        $connectionManager = $this->connectionManager;
        $connection->on('target', function ($host, $port) use ( $line) {
            $line('target: ' . $host . ':' . $port);
        });
        $connection->on('close', function () use ($line) {
            $line('disconnect');
        });
    }

    private function handleSocks(Stream $stream)
    {
        $reader = new StreamReader($stream);
        $that = $this;

        $auth = $this->auth;
        $protocolVersion = $this->protocolVersion;

        // authentication requires SOCKS5
        if ($auth !== null) {
        	$protocolVersion = '5';
        }

        return $reader->readByte()->then(function ($version) use ($stream, $that, $protocolVersion, $auth){
            if ($version === 0x04) {
                if ($protocolVersion === '5') {
                    throw new UnexpectedValueException('SOCKS4 not allowed due to configuration');
                }
                return $that->handleSocks4($stream, $protocolVersion);
            } else if ($version === 0x05) {
                if ($protocolVersion !== null && $protocolVersion !== '5') {
                    throw new UnexpectedValueException('SOCKS5 not allowed due to configuration');
                }
                return $that->handleSocks5($stream, $auth);
            }
            throw new UnexpectedValueException('Unexpected/unknown version number');
        });
    }

    public function handleSocks4(Stream $stream, $protocolVersion)
    {
        // suppliying hostnames is only allowed for SOCKS4a (or automatically detected version)
        $supportsHostname = ($protocolVersion === null || $protocolVersion === '4a');

        $reader = new StreamReader($stream);
        $that = $this;
        return $reader->readByteAssert(0x01)->then(function () use ($reader) {
            return $reader->readBinary(array(
                'port'   => 'n',
                'ipLong' => 'N',
                'null'   => 'C'
            ));
        })->then(function ($data) use ($reader, $supportsHostname) {
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
                return $reader->readStringNull()->then(function ($string) use ($data){
                    return array($string, $data['port']);
                });
            } else {
                $ip = long2ip($data['ipLong']);
                return array($ip, $data['port']);
            }
        })->then(function ($target) use ($stream, $that) {
            return $that->connectTarget($stream, $target)->then(function (Stream $remote) use ($stream){
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

    public function handleSocks5(Stream $stream, $auth=null)
    {
        $reader = new StreamReader($stream);
        $that = $this;
        return $reader->readByte()->then(function ($num) use ($reader) {
            // $num different authentication mechanisms offered
            return $reader->readLength($num);
        })->then(function ($methods) use ($reader, $stream, $auth) {
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
                })->then(function ($username) use ($reader, $auth, $stream) {
                    return $reader->readByte()->then(function ($length) use ($reader) {
                        return $reader->readLength($length);
                    })->then(function ($password) use ($username, $auth, $stream) {
                        // username and password known => authenticate
                        // echo 'auth: ' . $username.' : ' . $password . PHP_EOL;
                        return $auth($username, $password)->then(function () use ($stream) {
                            // accept
                            $stream->write(pack('C2', 0x01, 0x00));
                        }, function() use ($stream) {
                            // reject => send any code but 0x00
                            $stream->write(pack('C2', 0x01, 0xFF));
                        });
                    });
                });
            } else {
                // reject all offered authentication methods
                $stream->end(pack('C2', 0x05, 0xFF));
                throw new UnexpectedValueException('No acceptable authentication mechanism found');
            }
        })->then(function ($method) use ($reader, $stream) {
            $stream->emit('authenticate',array($method));
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
                throw new UnexpectedValueException('Only CONNECT requests supported');
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
                throw new UnexpectedValueException('Invalid target type');
            }
        })->then(function ($host) use ($reader) {
            return $reader->readBinary(array('port'=>'n'))->then(function ($data) use ($host) {
                return array($host, $data['port']);
            });
        })->then(function ($target) use ($that, $stream) {
            return $that->connectTarget($stream, $target);
        }, function($error) use ($stream) {
            throw new UnexpectedValueException('SOCKS5 protocol error',0,$error);
        })->then(function (Stream $remote) use ($stream) {
            $stream->write(pack('C4Nn', 0x05, 0x00, 0x00, 0x01, 0, 0));

            return $remote;
        }, function(Exception $error) use ($stream){
            $code = 0x01;
            $stream->end(pack('C4Nn', 0x05, $code, 0x00, 0x01, 0, 0));

            throw $error;
        });
    }

    public function connectTarget(Stream $stream, $target)
    {
        $stream->emit('target',$target);
        return $this->connectionManager->getConnection($target[0], $target[1])->then(function (Stream $remote) use ($stream) {
            if (!$stream->isWritable()) {
                $remote->close();
                throw new UnexpectedValueException('Remote connection successfully established after client connection closed');
            }

            $stream->pipe($remote, array('end'=>false));
            $remote->pipe($stream, array('end'=>false));

            // remote end closes connection => stop reading from local end, try to flush buffer to local and disconnect local
            $remote->on('end', function() use ($stream) {
                $stream->pause();
                $stream->end();
            });

            // local end closes connection => stop reading from remote end, try to flush buffer to remote and disconnect remote
            $stream->on('end', function() use ($remote) {
                $remote->pause();
                $remote->end();
            });


            // set bigger buffer size of 100k to improve performance
            $stream->bufferSize = $remote->bufferSize = 100 * 1024 * 1024;

            return $remote;
        }, function(Exception $error) {
            throw new UnexpectedValueException('Unable to connect to remote target', 0, $error);
        });
    }
}
