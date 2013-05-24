<?php

use Socks\Factory;

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
    
    public function testClientHttpRequest()
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
        
        $http = $client->createHttpClient();
        
        $request = $http->request('GET', 'https://www.google.com/', array('user-agent'=>'none'));
        $request->on('response', function (React\HttpClient\Response $response) {
            // response received, do not care for the rest of the response body
            $response->close();
        });
        $request->end();

//         $loop = $this->loop;
//         $that = $this;
//         $this->loop->addTimer(1.0, function() use ($that, $loop) {
//             $that->fail('timeout timer');
//             $loop->stop();
//         });
        
        $this->loop->run();
    }
    
    private function createSocketServer()
    {
        $socket = new React\Socket\Server($this->loop);
        $socket->listen(0);
        
        return $socket;
    }
}
