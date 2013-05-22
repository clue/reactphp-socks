<?php

use Socks\Factory;

class FactoryTest extends TestCase
{
    /** @var Factory */
    private $factory;
    
    public function setUp()
    {
        $this->factory = new Factory($this->createLoop(), $this->createResolver());
    }
    
    public function testCreateClient()
    {
        $client = $this->factory->createClient('localhost', 9050);

        $this->assertInstanceOf('Socks\Client', $client);
    }
    
    public function testCreateServer()
    {
        $server = $this->factory->createServer($this->createSocket());
        
        $this->assertInstanceOf('Socks\Server', $server);
    }
    
    private function createLoop()
    {
        return React\EventLoop\Factory::create();
    }
    
    private function createResolver()
    {
        return $this->getMockBuilder('React\Dns\Resolver\Resolver')
            ->disableOriginalConstructor()
            ->getMock();
    }
    
    private function createSocket()
    {
        return $this->getMockBuilder('React\Socket\Server')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
