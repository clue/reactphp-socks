<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;
use Clue\React\Socks\Server\Server;
use Clue\React\Block;
use React\SocketClient\TimeoutConnector;

class FunctionalTest extends TestCase
{
    private $loop;
    private $client;
    private $server;
    private $port;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();

        $socket = $this->createSocketServer();
        $this->port = $socket->getPort();
        $this->assertNotEquals(0, $this->port);

        $this->server = new Server($this->loop, $socket);
        $this->client = new Client('127.0.0.1:' . $this->port, $this->loop);
    }

    public function testConnection()
    {
        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionWithIpViaSocks4()
    {
        $this->server->setProtocolVersion(4);
        $this->client->setProtocolVersion(4);

        $this->assertResolveStream($this->client->createConnection('127.0.0.1', $this->port));
    }

    public function testConnectionWithHostnameViaSocks4Fails()
    {
        $this->client->setProtocolVersion(4);

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionWithIpv6ViaSocks4Fails()
    {
        $this->client->setProtocolVersion(4);

        $this->assertRejectPromise($this->client->createConnection('::1', 80));
    }

    public function testConnectionSocks5()
    {
        $this->server->setProtocolVersion(5);
        $this->client->setProtocolVersion(5);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client->setAuth('name', 'pass');

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthenticationFromUri()
    {
        $this->server->setAuthArray(array('name' => 'pass'));

        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->loop);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthenticationFromUriEncoded()
    {
        $this->server->setAuthArray(array('name' => 'p@ss:w0rd'));

        $this->client = new Client(rawurlencode('name') . ':' . rawurlencode('p@ss:w0rd') . '@127.0.0.1:' . $this->port, $this->loop);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthenticationFromUriWithOnlyUserAndNoPassword()
    {
        $this->server->setAuthArray(array('empty' => ''));

        $this->client = new Client('empty@127.0.0.1:' . $this->port, $this->loop);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthenticationUnused()
    {
        $this->client->setAuth('name', 'pass');

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidProtocolMismatch()
    {
        $this->server->setProtocolVersion(4);
        $this->client->setProtocolVersion(5);

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidNoAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client->setProtocolVersion(5);

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidAuthenticationMismatch()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client->setAuth('user', 'other');

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectorOkay()
    {
        $tcp = $this->client->createConnector();

        $this->assertResolveStream($tcp->create('www.google.com', 80));
    }

    public function testConnectorInvalidDomain()
    {
        $tcp = $this->client->createConnector();

        $this->assertRejectPromise($tcp->create('www.google.commm', 80));
    }

    public function testConnectorCancelConnection()
    {
        $tcp = $this->client->createConnector();

        $promise = $tcp->create('www.google.com', 80);
        $promise->cancel();

        $this->assertRejectPromise($promise);
    }

    public function testConnectorInvalidUnboundPortTimeout()
    {
        $tcp = $this->client->createConnector();

        // time out the connection attempt in 0.1s (as expected)
        $tcp = new TimeoutConnector($tcp, 0.1, $this->loop);

        $this->assertRejectPromise($tcp->create('www.google.com', 8080));
    }

    public function testSecureConnectorOkay()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector();

        $this->assertResolveStream($ssl->create('www.google.com', 443));
    }

    public function testSecureConnectorToBadSslWithVerifyFails()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector(array('verify_peer' => true));

        $this->assertRejectPromise($ssl->create('self-signed.badssl.com', 443));
    }

    public function testSecureConnectorToBadSslWithoutVerifyWorks()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector(array('verify_peer' => false));

        $this->assertResolveStream($ssl->create('self-signed.badssl.com', 443));
    }

    public function testSecureConnectorInvalidPlaintextIsNotSsl()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector();

        $this->assertRejectPromise($ssl->create('www.google.com', 80));
    }

    public function testSecureConnectorInvalidUnboundPortTimeout()
    {
        $ssl = $this->client->createSecureConnector();

        // time out the connection attempt in 0.1s (as expected)
        $ssl = new TimeoutConnector($ssl, 0.1, $this->loop);

        $this->assertRejectPromise($ssl->create('www.google.com', 8080));
    }

    private function createSocketServer()
    {
        $socket = new React\Socket\Server($this->loop);
        $socket->listen(0);

        return $socket;
    }

    private function assertResolveStream($promise)
    {
        $this->expectPromiseResolve($promise);

        $promise->then(function ($stream) {
            $stream->close();
        });

        Block\await($promise, $this->loop, 2.0);
    }

    private function assertRejectPromise($promise)
    {
        $this->expectPromiseReject($promise);

        $this->setExpectedException('Exception');

        Block\await($promise, $this->loop, 2.0);
    }
}
