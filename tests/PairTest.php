<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;
use Clue\React\Socks\Server;

class PairTest extends TestCase
{
    private $loop;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
    }

    public function testClientConnection()
    {
        $socket = $this->createSocketServer();
        $port = $socket->getPort();
        $this->assertNotEquals(0, $port);

        $server = new Server($this->loop, $socket);

        $server->on('connection', function () use ($socket) {
            // close server socket once first connection has been established
            $socket->shutdown();
        });

        $client = new Client($this->loop, '127.0.0.1', $port);

        $that = $this;
        $client->getConnection('www.google.com', 80)->then(
            function (Stream $stream) {
                $stream->close();
            },
            function ($error) use ($that) {
                $that->fail('Unable to connect');
            }
        );

        $this->loop->run();
    }

    private function createSocketServer()
    {
        $socket = new React\Socket\Server($this->loop);
        $socket->listen(0);

        return $socket;
    }
}
