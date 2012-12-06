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
            echo '#' . (int)$connection->stream . ' ' . $msg . PHP_EOL;
        };

        $line('connect');

        $this->handshakeSocks4($connection)->then(function($remote) use ($line, $connection){
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
            $connection->close();
            throw $error;
        }, function ($progress) use ($line) {
            //$s = new StreamReader();
            $line('progress: './*$s->s*/($progress));
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

    private function handshakeSocks4($stream)
    {
        $reader = new StreamReader($stream);
        $connectionManager = $this->connectionManager;
        return $reader->readAssert("\x04\x01")->then(function () use ($reader) {
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
        })->then(function ($target) use ($stream, $connectionManager) {
            $stream->emit('target',$target);
            return $connectionManager->getConnection($target[0], $target[1])->then(function (Stream $remote) use ($stream){
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
            throw new UnexpectedValueException('Protocol error',0,$error);
        });
    }
}
