<?php

use Clue\React\Socks\Server\Server;
use React\Promise\Promise;
use React\Stream\Stream;

class ServerTest extends TestCase
{
    /** @var Server */
    private $server;
    private $connector;

    public function setUp()
    {
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')
            ->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\StreamSelectLoop')
            ->disableOriginalConstructor()
            ->getMock();

        $this->connector = $this->getMockBuilder('React\SocketClient\Connector')
            ->disableOriginalConstructor()
            ->getMock();

        $this->server = new Server($loop, $socket, $this->connector);
    }

    public function testSetProtocolVersion()
    {
        $this->server->setProtocolVersion(4);
        $this->server->setProtocolVersion('4a');
        $this->server->setProtocolVersion(5);
        $this->server->setProtocolVersion(null);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetInvalidProtocolVersion()
    {
        $this->server->setProtocolVersion(6);
    }

    public function testSetAuthArray()
    {
        $this->server->setAuthArray(array());

        $this->server->setAuthArray(array(
            'name1' => 'password1',
            'name2' => 'password2'
        ));
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetAuthInvalid()
    {
        $this->server->setAuth(true);
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testUnableToSetAuthIfProtocolDoesNotSupportAuth()
    {
        $this->server->setProtocolVersion(4);

        $this->server->setAuthArray(array());
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testUnableToSetProtocolWhichDoesNotSupportAuth()
    {
        $this->server->setAuthArray(array());

        // this is okay
        $this->server->setProtocolVersion(5);

        $this->server->setProtocolVersion(4);
    }

    public function testConnectWillCreateConnection()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('create')->with('google.com', 80)->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectWillRejectIfConnectionFails()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();

        $promise = new Promise(function ($_, $reject) { $reject(new \RuntimeException()); });

        $this->connector->expects($this->once())->method('create')->with('google.com', 80)->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillCancelConnectionIfStreamCloses()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->setMethods(array('close'))->getMock();

        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $this->connector->expects($this->once())->method('create')->with('google.com', 80)->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $stream->emit('close');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillAbortIfPromiseIsCancelled()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();

        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $this->connector->expects($this->once())->method('create')->with('google.com', 80)->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillCloseStreamIfConnectorResolvesDespiteCancellation()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function () { }, function ($resolve) use ($stream) { $resolve($stream); });

        $this->connector->expects($this->once())->method('create')->with('google.com', 80)->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->cancel();
    }

    public function testHandleSocksConnectionWillEndOnInvalidData()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();
        $connection->expects($this->once())->method('pause');
        $connection->expects($this->once())->method('end');

        $this->server->onConnection($connection);

        $connection->emit('data', array('asdasdasdasdasd'));
    }

    public function testHandleSocksConnectionWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 80)->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . pack('N', ip2long('127.0.0.1')) . "\x00"));
    }

    public function testHandleSocksConnectionWillCancelOutputConnectionIfIncomingCloses()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 80)->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . pack('N', ip2long('127.0.0.1')) . "\x00"));
        $connection->emit('close');
    }

    public function testUnsetAuth()
    {
        $this->server->unsetAuth();
        $this->server->unsetAuth();
    }
}
