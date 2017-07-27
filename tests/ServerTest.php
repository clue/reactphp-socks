<?php

use Clue\React\Socks\Server;
use React\Promise\Promise;

class ServerTest extends TestCase
{
    /** @var Server */
    private $server;
    private $connector;

    public function setUp()
    {
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')
            ->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')
            ->getMock();

        $this->connector = $this->getMockBuilder('React\Socket\ConnectorInterface')
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
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectWillCreateConnectionWithSourceUri()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80?source=socks5%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80, 'socks5://10.20.30.40:5060'));

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectWillRejectIfConnectionFails()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function ($_, $reject) { $reject(new \RuntimeException()); });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillCancelConnectionIfStreamCloses()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('close'))->getMock();

        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });


        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $stream->emit('close');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillAbortIfPromiseIsCancelled()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testHandleSocksConnectionWillEndOnInvalidData()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();
        $connection->expects($this->once())->method('pause');
        $connection->expects($this->once())->method('end');

        $this->server->onConnection($connection);

        $connection->emit('data', array('asdasdasdasdasd'));
    }

    public function testHandleSocks4ConnectionWithIpv4WillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . pack('N', ip2long('127.0.0.1')) . "\x00"));
    }

    public function testHandleSocks4aConnectionWithHostnameWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "example.com" . "\x00"));
    }

    public function testHandleSocks4aConnectionWithHostnameAndSourceAddressWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://10.20.30.40:5060');

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80?source=socks4%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "example.com" . "\x00"));
    }

    public function testHandleSocks4aConnectionWithInvalidHostnameWillNotEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $this->connector->expects($this->never())->method('connect');

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "tls://example.com:80?" . "\x00"));
    }

    public function testHandleSocks5ConnectionWithIpv4WillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x01" . pack('N', ip2long('127.0.0.1')) . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithIpv4AndSourceAddressWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://10.20.30.40:5060');

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80?source=socks5%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x01" . pack('N', ip2long('127.0.0.1')) . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithIpv6WillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('[::1]:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x04" . inet_pton('::1') . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithHostnameWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x03\x0B" . "example.com" . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithInvalidHostnameWillNotEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $this->connector->expects($this->never())->method('connect');

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x03\x15" . "tls://example.com:80?" . "\x00\x50"));
    }

    public function testHandleSocksConnectionWillCancelOutputConnectionIfIncomingCloses()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

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
