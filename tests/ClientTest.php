<?php

use Clue\React\Socks\Client;
use React\Promise\Promise;
use Clue\React\Socks\Server;

class ClientTest extends TestCase
{
    private $loop;

    private $connector;

    /** @var  Client */
    private $client;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
        $this->connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->client = new Client('127.0.0.1:1080', $this->connector);
    }

    public function testCtorAcceptsUriWithHostAndPort()
    {
        $client = new Client('127.0.0.1:9050', $this->connector);

        $this->assertTrue(true);
    }

    public function testCtorAcceptsUriWithScheme()
    {
        $client = new Client('socks://127.0.0.1:9050', $this->connector);

        $this->assertTrue(true);
    }

    public function testCtorAcceptsUriWithHostOnlyAssumesDefaultPort()
    {
        $client = new Client('127.0.0.1', $this->connector);

        $this->assertTrue(true);
    }

    public function testCtorAcceptsUriWithSecureScheme()
    {
        $client = new Client('sockss://127.0.0.1:9050', $this->connector);

        $this->assertTrue(true);
    }

    public function testCtorAcceptsUriWithSecureVersionScheme()
    {
        $client = new Client('socks5s://127.0.0.1:9050', $this->connector);

        $this->assertTrue(true);
    }

    public function testCtorAcceptsUriWithSocksUnixScheme()
    {
        $client = new Client('socks+unix:///tmp/socks.socket', $this->connector);

        $this->assertTrue(true);
    }

    public function testCtorAcceptsUriWithSocks5UnixScheme()
    {
        $client = new Client('socks5+unix:///tmp/socks.socket', $this->connector);

        $this->assertTrue(true);
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

        $this->assertTrue(true);
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

        $this->assertTrue(true);
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

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=localhost')->willReturn($promise);

        $promise = $this->client->connect('localhost:80');

        $this->assertInstanceOf('\React\Promise\PromiseInterface', $promise);
    }

    public function testCreateWillConnectToProxyWithFullUri()
    {
        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080/?hostname=test#fragment')->willReturn($promise);

        $promise = $this->client->connect('localhost:80/?hostname=test#fragment');

        $this->assertInstanceOf('\React\Promise\PromiseInterface', $promise);
    }

    public function testCreateWithInvalidHostDoesNotConnect()
    {
        $promise = new Promise(function () { });

        $this->connector->expects($this->never())->method('connect');

        $promise = $this->client->connect(str_repeat('a', '256') . ':80');

        $this->assertInstanceOf('\React\Promise\PromiseInterface', $promise);
    }

    public function testCreateWithInvalidPortDoesNotConnect()
    {
        $promise = new Promise(function () { });

        $this->connector->expects($this->never())->method('connect');

        $promise = $this->client->connect('some-random-site:some-random-port');

        $this->assertInstanceOf('\React\Promise\PromiseInterface', $promise);
    }

    public function testConnectorRejectsWillRejectConnection()
    {
        $promise = \React\Promise\reject(new RuntimeException());

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $promise = $this->client->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_ECONNREFUSED));
    }

    public function testCancelConnectionDuringConnectionWillCancelConnection()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $promise = $this->client->connect('google.com:80');
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringSessionWillCloseStream()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function ($resolve) use ($stream) { $resolve($stream); });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $promise = $this->client->connect('google.com:80');
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_ECONNABORTED));
    }

    public function testEmitConnectionCloseDuringSessionWillRejectConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('close');

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_ECONNRESET));
    }

    public function testEmitConnectionErrorDuringSessionWillRejectConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('error', array(new RuntimeException()));

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_EIO));
    }

    public function testEmitInvalidSocks4DataDuringSessionWillRejectConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('data', array("HTTP/1.1 400 Bad Request\r\n\r\n"));

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_EBADMSG));
    }

    public function testEmitInvalidSocks5DataDuringSessionWillRejectConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $this->client = new Client('socks5://127.0.0.1:1080', $this->connector);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('data', array("HTTP/1.1 400 Bad Request\r\n\r\n"));

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_EBADMSG));
    }

    public function testEmitSocks5DataErrorDuringSessionWillRejectConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $this->client = new Client('socks5://127.0.0.1:1080', $this->connector);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('data', array("\x05\x00" . "\x05\x01\x00\x00"));

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_ECONNREFUSED));
    }

    public function testEmitSocks5DataInvalidAddressTypeWillRejectConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $this->client = new Client('socks5://127.0.0.1:1080', $this->connector);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('data', array("\x05\x00" . "\x05\x00\x00\x00"));

        $promise->then(null, $this->expectCallableOnceWithExceptionCode(SOCKET_EBADMSG));
    }

    public function testEmitSocks5DataIpv6AddressWillResolveConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->never())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=%3A%3A1')->willReturn($promise);

        $this->client = new Client('socks5://127.0.0.1:1080', $this->connector);

        $promise = $this->client->connect('[::1]:80');

        $stream->emit('data', array("\x05\x00" . "\x05\x00\x00\x04" . inet_pton('::1') . "\x00\x50"));

        $promise->then($this->expectCallableOnce());
    }

    public function testEmitSocks5DataHostnameAddressWillResolveConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->never())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $this->client = new Client('socks5://127.0.0.1:1080', $this->connector);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('data', array("\x05\x00" . "\x05\x00\x00\x03\x0Agoogle.com\x00\x50"));

        $promise->then($this->expectCallableOnce());
    }

    public function provideConnectionErrors()
    {
        return array(
            array(
                Server::ERROR_GENERAL,
                SOCKET_ECONNREFUSED
            ),
            array(
                Server::ERROR_NOT_ALLOWED_BY_RULESET,
                SOCKET_EACCES
            ),
            array(
                Server::ERROR_NETWORK_UNREACHABLE,
                SOCKET_ENETUNREACH
            ),
            array(
                Server::ERROR_HOST_UNREACHABLE,
                SOCKET_EHOSTUNREACH
            ),
            array(
                Server::ERROR_CONNECTION_REFUSED,
                SOCKET_ECONNREFUSED
            ),
            array(
                Server::ERROR_TTL,
                SOCKET_ETIMEDOUT
            ),
            array(
                Server::ERROR_COMMAND_UNSUPPORTED,
                SOCKET_EPROTO
            ),
            array(
                Server::ERROR_ADDRESS_UNSUPPORTED,
                SOCKET_EPROTO
            ),
            array(
                200,
                SOCKET_ECONNREFUSED
            )
        );
    }

    /**
     * @dataProvider provideConnectionErrors
     * @param int $error
     * @param int $expectedCode
     */
    public function testEmitSocks5DataErrorMapsToExceptionCode($error, $expectedCode)
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write', 'close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:1080?hostname=google.com')->willReturn($promise);

        $this->client = new Client('socks5://127.0.0.1:1080', $this->connector);

        $promise = $this->client->connect('google.com:80');

        $stream->emit('data', array("\x05\x00" . "\x05" . chr($error) . "\x00\x00"));

        $promise->then(null, $this->expectCallableOnceWithExceptionCode($expectedCode));
    }
}
