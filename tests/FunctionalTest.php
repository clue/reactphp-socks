<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;
use Clue\React\Socks\Server\Server;
use React\Promise\PromiseInterface;
use React\Socket\TimeoutConnector;
use React\Socket\SecureConnector;
use React\Socket\TcpConnector;

class FunctionalTest extends TestCase
{
    private $loop;
    private $port;
    private $server;
    private $connector;
    private $client;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();

        $socket = new React\Socket\Server(0, $this->loop);
        $this->port = parse_url('tcp://' . $socket->getAddress(), PHP_URL_PORT);
        $this->assertNotEquals(0, $this->port);

        $this->server = new Server($this->loop, $socket);
        $this->connector = new TcpConnector($this->loop);
        $this->client = new Client('127.0.0.1:' . $this->port, $this->connector);
    }

    public function testConnection()
    {
        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalid()
    {
        $this->assertRejectPromise($this->client->connect('www.google.com.invalid:80'));
    }

    public function testConnectionSocks4()
    {
        $this->server->setProtocolVersion('4');
        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('127.0.0.1:' . $this->port));
    }

    public function testConnectionSocks4a()
    {
        $this->server->setProtocolVersion('4a');
        $this->client = new Client('socks4a://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionSocks5()
    {
        $this->server->setProtocolVersion(5);
        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionAuthenticationEmptyPassword()
    {
        $this->server->setAuthArray(array('user' => ''));
        $this->client = new Client('user@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionAuthenticationUnused()
    {
        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidProtocolDoesNotMatchDefault()
    {
        $this->server->setProtocolVersion(5);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidProtocolDoesNotMatchSocks5()
    {
        $this->server->setProtocolVersion(5);
        $this->client = new Client('socks4a://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidProtocolDoesNotMatchSocks4()
    {
        $this->server->setProtocolVersion(4);
        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidNoAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidAuthenticationMismatch()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client = new Client('user:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    public function testConnectorInvalidUnboundPortTimeout()
    {
        $tcp = new TimeoutConnector($this->client, 0.1, $this->loop);

        $this->assertRejectPromise($tcp->connect('www.google.com:8080'));
    }

    public function testSecureConnectorOkay()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop);

        $this->assertResolveStream($ssl->connect('www.google.com:443'));
    }

    public function testSecureConnectorToBadSslWithVerifyFails()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop, array('verify_peer' => true));

        $this->assertRejectPromise($ssl->connect('self-signed.badssl.com:443'));
    }

    public function testSecureConnectorToBadSslWithoutVerifyWorks()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop, array('verify_peer' => false));

        $this->assertResolveStream($ssl->connect('self-signed.badssl.com:443'));
    }

    public function testSecureConnectorInvalidPlaintextIsNotSsl()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, $this->loop);

        $this->assertRejectPromise($ssl->connect('www.google.com:80'));
    }

    public function testSecureConnectorInvalidUnboundPortTimeout()
    {
        $tcp = new TimeoutConnector($this->client, 0.1, $this->loop);
        $ssl = new SecureConnector($tcp, $this->loop);

        $this->assertRejectPromise($ssl->connect('www.google.com:8080'));
    }

    private function assertResolveStream($promise)
    {
        $this->expectPromiseResolve($promise);

        $promise->then(function ($stream) {
            $stream->close();
        });

        $this->waitFor($promise);
    }

    private function assertRejectPromise($promise)
    {
        $this->expectPromiseReject($promise);

        $this->setExpectedException('Exception');
        $this->waitFor($promise);
    }

    private function waitFor(PromiseInterface $promise)
    {
        $resolved = null;
        $exception = null;

        $promise->then(function ($c) use (&$resolved) {
            $resolved = $c;
        }, function($error) use (&$exception) {
            $exception = $error;
        });

        while ($resolved === null && $exception === null) {
            $this->loop->tick();
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $resolved;
    }
}
