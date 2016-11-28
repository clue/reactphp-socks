<?php

use Clue\React\Socks\Client;
use React\Promise\Promise;

class ClientTest extends TestCase
{
    private $loop;

    /** @var  Client */
    private $client;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
        $this->client = new Client('127.0.0.1:9050', $this->loop);
    }

    public function testCtorAcceptsUriWithHostAndPort()
    {
        $client = new Client('127.0.0.1:9050', $this->loop);
    }

    public function testCtorAcceptsUriWithScheme()
    {
        $client = new Client('socks://127.0.0.1:9050', $this->loop);
    }

    public function testCtorAcceptsUriWithHostOnlyAssumesDefaultPort()
    {
        $client = new Client('127.0.0.1', $this->loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsForInvalidUri()
    {
        new Client('////', $this->loop);
    }

    public function testValidAuthFromUri()
    {
        $this->client = new Client('username:password@127.0.0.1', $this->loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidAuthInformation()
    {
        new Client(str_repeat('a', 256) . ':test@127.0.0.1', $this->loop);
    }

    public function testValidAuthAndVersionFromUri()
    {
        $this->client = new Client('socks5://username:password@127.0.0.1:9050', $this->loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidCanNotSetAuthenticationForSocks4Uri()
    {
        $this->client = new Client('socks4://username:password@127.0.0.1:9050', $this->loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidProtocolVersion()
    {
        $this->client = new Client('socks3://127.0.0.1:9050', $this->loop);
    }

    public function testCancelConnectionDuringConnectionWillCancelConnection()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);
        $this->client = new Client('127.0.0.1', $this->loop, $connector);

        $promise = $this->client->create('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringConnectionWillCancelConnectionAndCloseStreamIfItResolvesDespite()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function () { }, function ($resolve) use ($stream) { $resolve($stream); });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);
        $this->client = new Client('127.0.0.1', $this->loop, $connector);

        $promise = $this->client->create('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringSessionWillCloseStream()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function ($resolve) use ($stream) { $resolve($stream); });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);
        $this->client = new Client('127.0.0.1', $this->loop, $connector);

        $promise = $this->client->create('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    /**
     * @dataProvider providerAddress
     */
    public function testCreateConnection($host, $port)
    {
        $this->assertInstanceOf('\React\Promise\PromiseInterface', $this->client->create($host, $port));
    }

    public function providerAddress()
    {
        return array(
            array('localhost','80'),
            array('invalid domain','non-numeric')
        );
    }
}
