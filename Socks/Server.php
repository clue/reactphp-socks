<?php

namespace Socks;

use React\Stream\Stream;
use React\HttpClient\ConnectionManagerInterface;
use React\Socket\Connection;
use React\EventLoop\LoopInterface;
use React\Socket\Server as SocketServer;
use \UnexpectedValueException;

class Server extends SocketServer
{
    private $connectionManager;

    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager)
    {
        parent::__construct($loop);

        $this->connectionManager = $connectionManager;

        $this->on('connection', array($this, 'onConnection'));
    }

    public function onConnection(Connection $connection)
    {

        $line = function($msg) use ($connection) {
            echo date('Y-m-d H:i:s') . ' #' . (int)$connection->stream . ' ' . $msg . PHP_EOL;
        };

        $line('connect');

        $this->handleSocks($connection)->then(function($remote) use ($line, $connection){
            $line('tunnel successfully estabslished');
            $connection->emit('ready',array($remote));
        }, function ($error) use ($connection, $line) {
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
            // $connection->end();
            // TODO: start timeout to call $connection->close()

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
        return $reader->readByte()->then(function ($version) use ($stream, $that){
            if ($version === 0x04) {
                return $that->handleSocks4($stream);
            } else if ($version === 0x05) {
                return $that->handleSocks5($stream);
            }
            throw new UnexpectedValueException('Unexpected version number');
        });
    }

    public function handleSocks4(Stream $stream)
    {
        $reader = new StreamReader($stream);
        $that = $this;
        return $reader->readByteAssert(0x01)->then(function () use ($reader) {
            return $reader->readBinary(array(
                'port'   => 'n',
                'ipLong' => 'N',
                'null'   => 'C'
            ));
        })->then(function ($data) use ($reader) {
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

                $stream->pipe($remote);
                $remote->pipe($stream);

                return $remote;
            }, function($error) use ($stream){
                $stream->end(pack('C8', 0x00, 0x5b, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00));

                //$stream->emit('error',array($error));
                throw $error;
            });
        }, function($error) {
            throw new UnexpectedValueException('SOCKS4 protocol error',0,$error);
        });
    }

    public function handleSocks5(Stream $stream)
    {
        $reader = new StreamReader($stream);
        $that = $this;
        return $reader->readByte()->then(function ($num) use ($reader) {
            // $num different authentication mechanisms offered
            return $reader->readLength($num);
        })->then(function ($methods) use ($reader, $stream) {
            if (strpos($methods,"\x00") !== false) {
                // accept "no authentication"
                $stream->write(pack('C2', 0x05, 0x00));
                return 0x00;
            } else if (false) {
                // TODO: support username/password authentication (0x01)
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
        })->then(function ($remote) use ($stream) {
            $stream->write(pack('C4Nn', 0x05, 0x00, 0x00, 0x01, 0, 0));

            $stream->pipe($remote);
            $remote->pipe($stream);
        }, function($error) use ($stream){
            $code = 0x01;
            $stream->write(pack('C4Nn', 0x05, $code, 0x00, 0x01, 0, 0));
            $stream->end();
            throw new UnexpectedValueException('Unable to connect to remote target', 0, $error);
        });
    }

    public function connectTarget(Stream $stream, $target)
    {
        $stream->emit('target',$target);
        return $this->connectionManager->getConnection($target[0], $target[1])->then(function ($remote) use ($stream) {
            if (!$stream->isWritable()) {
                $remote->close();
                throw new UnexpectedValueException('Remote connection successfully established after client connection closed');
            }
            return $remote;
        });
    }
}
