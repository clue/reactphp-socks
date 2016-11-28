<?php

use Clue\React\Socks\Client;
use React\Promise\Promise;

class ClientTest extends TestCase
{
    private $loop;

    private $connector;

    /** @var  Client */
    private $client;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
        $this->connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $this->client = new Client('127.0.0.1:1080', $this->connector);
    }

    public function testCtorAcceptsUriWithHostAndPort()
    {
        $client = new Client('127.0.0.1:9050', $this->connector);
    }

    public function testCtorAcceptsUriWithScheme()
    {
        $client = new Client('socks://127.0.0.1:9050', $this->connector);
    }

    public function testCtorAcceptsUriWithHostOnlyAssumesDefaultPort()
    {
        $client = new Client('127.0.0.1', $this->connector);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsForInvalidUri()
    {
        new Client('////', $this->connector);
    }

    public function testValidAuthFromUri()
    {
        $this->client = new Client('username:password@127.0.0.1', $this->connector);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidAuthInformation()
    {
        new Client(str_repeat('a', 256) . ':test@127.0.0.1', $this->connector);
    }

    public function testValidAuthAndVersionFromUri()
    {
        $this->client = new Client('socks5://username:password@127.0.0.1:9050', $this->connector);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidCanNotSetAuthenticationForSocks4Uri()
    {
        $this->client = new Client('socks4://username:password@127.0.0.1:9050', $this->connector);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidProtocolVersion()
    {
        $this->client = new Client('socks3://127.0.0.1:9050', $this->connector);
    }

    public function testCreateWillConnectToProxy()
    {
        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);

        $promise = $this->client->create('localhost', 80);

        $this->assertInstanceOf('\React\Promise\PromiseInterface', $promise);
    }

    public function testCreateWithInvalidPortDoesNotConnect()
    {
        $promise = new Promise(function () { });

        $this->connector->expects($this->never())->method('create');

        $promise = $this->client->create('some-random-site', 'some-random-port');

        $this->assertInstanceOf('\React\Promise\PromiseInterface', $promise);
    }

    public function testCancelConnectionDuringConnectionWillCancelConnection()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);

        $promise = $this->client->create('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringConnectionWillCancelConnectionAndCloseStreamIfItResolvesDespite()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function () { }, function ($resolve) use ($stream) { $resolve($stream); });

        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);

        $promise = $this->client->create('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringSessionWillCloseStream()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function ($resolve) use ($stream) { $resolve($stream); });

        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);

        $promise = $this->client->create('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }
}
