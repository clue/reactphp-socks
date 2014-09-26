<?php

use Clue\React\Socks\Factory;
use React\Stream\Stream;

class PairTest extends TestCase
{
    private $loop;
    private $factory;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();

        $dnsResolverFactory = new React\Dns\Resolver\Factory();
        $dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);

        $this->factory = new Factory($this->loop, $dns);
    }

    public function testClientConnection()
    {
        $socket = $this->createSocketServer();
        $port = $socket->getPort();
        $this->assertNotEquals(0, $port);

        $server = $this->factory->createServer($socket);

        $server->on('connection', function () use ($socket) {
            // close server socket once first connection has been established
            $socket->shutdown();
        });

        $client = $this->factory->createClient('127.0.0.1', $port);

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
