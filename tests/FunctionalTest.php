<?php

use Clue\React\Socks\Client;
use Clue\React\Socks\Server;
use Clue\React\Block;
use React\Socket\TimeoutConnector;
use React\Socket\SecureConnector;
use React\Socket\TcpConnector;

class FunctionalTest extends TestCase
{
    private $loop;
    private $connector;
    private $client;

    private $port;
    private $server;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();

        $socket = new React\Socket\Server(0, $this->loop);
        $address = $socket->getAddress();
        if (strpos($address, '://') === false) {
            $address = 'tcp://' . $address;
        }
        $this->port = parse_url($address, PHP_URL_PORT);
        $this->assertNotEquals(0, $this->port);

        $this->server = new Server($this->loop, $socket);
        $this->connector = new TcpConnector($this->loop);
        $this->client = new Client('127.0.0.1:' . $this->port, $this->connector);
    }

    /** @group internet */
    public function testConnection()
    {
        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionInvalid()
    {
        $this->assertRejectPromise($this->client->connect('www.google.com.invalid:80'));
    }

    public function testConnectionWithIpViaSocks4()
    {
        $this->server->setProtocolVersion('4');

        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('127.0.0.1:' . $this->port));
    }

    /** @group internet */
    public function testConnectionWithHostnameViaSocks4Fails()
    {
        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionWithInvalidPortFails()
    {
        $this->assertRejectPromise($this->client->connect('www.google.com:100000'));
    }

    public function testConnectionWithIpv6ViaSocks4Fails()
    {
        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('[::1]:80'));
    }

    /** @group internet */
    public function testConnectionSocks4a()
    {
        $this->server->setProtocolVersion('4a');
        $this->client = new Client('socks4a://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionSocks5()
    {
        $this->server->setProtocolVersion(5);
        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationFromUri()
    {
        $this->server->setAuthArray(array('name' => 'pass'));

        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationCallback()
    {
        $called = 0;
        $that = $this;
        $this->server->setAuth(function ($name, $pass, $remote) use ($that, &$called) {
            ++$called;
            $that->assertEquals('name', $name);
            $that->assertEquals('pass', $pass);
            $that->assertStringStartsWith('socks5://name:pass@127.0.0.1:', $remote);

            return true;
        });

        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
        $this->assertEquals(1, $called);
    }

    /** @group internet */
    public function testConnectionAuthenticationCallbackWillNotBeInvokedIfClientsSendsNoAuth()
    {
        $called = 0;
        $this->server->setAuth(function () use (&$called) {
            ++$called;

            return true;
        });

        $this->client = new Client('127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
        $this->assertEquals(0, $called);
    }

    /** @group internet */
    public function testConnectionAuthenticationFromUriEncoded()
    {
        $this->server->setAuthArray(array('name' => 'p@ss:w0rd'));

        $this->client = new Client(rawurlencode('name') . ':' . rawurlencode('p@ss:w0rd') . '@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationFromUriWithOnlyUserAndNoPassword()
    {
        $this->server->setAuthArray(array('empty' => ''));

        $this->client = new Client('empty@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationEmptyPassword()
    {
        $this->server->setAuthArray(array('user' => ''));
        $this->client = new Client('user@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationUnused()
    {
        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidProtocolDoesNotMatchSocks5()
    {
        $this->server->setProtocolVersion(5);
        $this->client = new Client('socks4a://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), '', SOCKET_ECONNRESET);
    }

    public function testConnectionInvalidProtocolDoesNotMatchSocks4()
    {
        $this->server->setProtocolVersion(4);
        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), '', SOCKET_ECONNRESET);
    }

    public function testConnectionInvalidNoAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));

        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), '', SOCKET_EACCES);
    }

    public function testConnectionInvalidAuthenticationMismatch()
    {
        $this->server->setAuthArray(array('name' => 'pass'));

        $this->client = new Client('user:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), '', SOCKET_EACCES);
    }

    /** @group internet */
    public function testConnectorOkay()
    {
        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectorInvalidDomain()
    {
        $this->assertRejectPromise($this->client->connect('www.google.commm:80'));
    }

    /** @group internet */
    public function testConnectorCancelConnection()
    {
        $promise = $this->client->connect('www.google.com:80');
        $promise->cancel();

        $this->assertRejectPromise($promise);
    }

    /** @group internet */
    public function testConnectorInvalidUnboundPortTimeout()
    {
        // time out the connection attempt in 0.1s (as expected)
        $tcp = new TimeoutConnector($this->client, 0.1, $this->loop);

        $this->assertRejectPromise($tcp->connect('www.google.com:8080'));
    }

    /** @group internet */
    public function testSecureConnectorOkay()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop);

        $this->assertResolveStream($ssl->connect('www.google.com:443'));
    }

    /** @group internet */
    public function testSecureConnectorToBadSslWithVerifyFails()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop, array('verify_peer' => true));

        $this->assertRejectPromise($ssl->connect('self-signed.badssl.com:443'));
    }

    /** @group internet */
    public function testSecureConnectorToBadSslWithoutVerifyWorks()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop, array('verify_peer' => false));

        $this->assertResolveStream($ssl->connect('self-signed.badssl.com:443'));
    }

    /** @group internet */
    public function testSecureConnectorInvalidPlaintextIsNotSsl()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop);

        $this->assertRejectPromise($ssl->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testSecureConnectorInvalidUnboundPortTimeout()
    {
        $ssl = new SecureConnector($this->client, $this->loop);

        // time out the connection attempt in 0.1s (as expected)
        $ssl = new TimeoutConnector($ssl, 0.1, $this->loop);

        $this->assertRejectPromise($ssl->connect('www.google.com:8080'));
    }

    private function assertResolveStream($promise)
    {
        $this->expectPromiseResolve($promise);

        $promise->then(function ($stream) {
            $stream->close();
        });

        Block\await($promise, $this->loop, 2.0);
    }

    private function assertRejectPromise($promise, $message = '', $code = null)
    {
        $this->expectPromiseReject($promise);

        $this->setExpectedException('Exception', $message, $code);

        Block\await($promise, $this->loop, 2.0);
    }
}
