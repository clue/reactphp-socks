<?php

namespace Clue\Tests\React\Socks;

use Clue\React\Block;
use Clue\React\Socks\Client;
use Clue\React\Socks\Server;
use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Socket\SecureConnector;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use React\Socket\TimeoutConnector;
use React\Socket\UnixServer;

class FunctionalTest extends TestCase
{
    private $connector;
    private $client;

    private $port;
    private $server;

    /**
     * @before
     */
    public function setUpClientServer()
    {
        $socket = new SocketServer('127.0.0.1:0');
        $address = $socket->getAddress();
        if (strpos($address, '://') === false) {
            $address = 'tcp://' . $address;
        }
        $this->port = parse_url($address, PHP_URL_PORT);
        $this->assertNotEquals(0, $this->port);

        $this->server = new Server();
        $this->server->listen($socket);
        $this->connector = new TcpConnector();
        $this->client = new Client('127.0.0.1:' . $this->port, $this->connector);
    }

    /** @group internet */
    public function testConnection()
    {
        // max_nesting_level was set to 100 for PHP Versions < 5.4 which resulted in failing test for legacy PHP
        ini_set('xdebug.max_nesting_level', 256);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionInvalid()
    {
        $this->assertRejectPromise($this->client->connect('www.google.com.invalid:80'));
    }

    public function testConnectionWithIpViaSocks4()
    {
        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('127.0.0.1:' . $this->port));
    }

    /** @group internet */
    public function testConnectionWithHostnameViaSocks4a()
    {
        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
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
    public function testConnectionSocks5()
    {
        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionSocksOverTls()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on HHVM');
        }

        $socket = new SocketServer('tls://127.0.0.1:0', array(
            'tls' => array(
                'local_cert' => __DIR__ . '/../examples/localhost.pem',
            )
        ));
        $this->server = new Server();
        $this->server->listen($socket);

        $this->connector = new Connector(array(
            'tls' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            )
        ));
        $this->client = new Client(str_replace('tls:', 'sockss:', $socket->getAddress()), $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /**
     * @group internet
     * @requires PHP 5.6
     */
    public function testConnectionSocksOverTlsUsesPeerNameFromSocksUri()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on HHVM');
        }

        $socket = new SocketServer('tls://127.0.0.1:0',array(
            'tls' => array(
                'local_cert' => __DIR__ . '/../examples/localhost.pem',
            )
        ));
        $this->server = new Server();
        $this->server->listen($socket);

        $this->connector = new Connector(array(
            'tls' => array(
                'verify_peer' => false,
                'verify_peer_name' => true
            )
        ));
        $this->client = new Client(str_replace('tls:', 'sockss:', $socket->getAddress()), $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionSocksOverUnix()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('System does not support unix:// scheme');
        }

        $path = sys_get_temp_dir() . '/test' . mt_rand(1000, 9999) . '.sock';
        $socket = new UnixServer($path);
        $this->server = new Server();
        $this->server->listen($socket);

        $this->connector = new Connector();
        $this->client = new Client('socks+unix://' . $path, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));

        unlink($path);
    }

    /** @group internet */
    public function testConnectionSocks5OverUnix()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('System does not support unix:// scheme');
        }

        $path = sys_get_temp_dir() . '/test' . mt_rand(1000, 9999) . '.sock';
        $socket = new UnixServer($path);
        $this->server = new Server();
        $this->server->listen($socket);

        $this->connector = new Connector();
        $this->client = new Client('socks5+unix://' . $path, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));

        unlink($path);
    }

    /** @group internet */
    public function testConnectionSocksWithAuthenticationOverUnix()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('System does not support unix:// scheme');
        }

        $path = sys_get_temp_dir() . '/test' . mt_rand(1000, 9999) . '.sock';
        $socket = new UnixServer($path);
        $this->server = new Server(null, null, array('name' => 'pass'));
        $this->server->listen($socket);

        $this->connector = new Connector();
        $this->client = new Client('socks+unix://name:pass@' . $path, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));

        unlink($path);
    }

    /** @group internet */
    public function testConnectionAuthenticationFromUri()
    {
        $this->server = new Server(null, null, array('name' => 'pass'));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationCallback()
    {
        $called = 0;
        $that = $this;
        $this->server = new Server(null, null, function ($name, $pass, $remote) use ($that, &$called) {
            ++$called;
            $that->assertEquals('name', $name);
            $that->assertEquals('pass', $pass);
            $that->assertStringStartsWith('socks://name:pass@127.0.0.1:', $remote);

            return true;
        });

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
        $this->assertEquals(1, $called);
    }

    /** @group internet */
    public function testConnectionAuthenticationCallbackWillNotBeInvokedIfClientsSendsNoAuth()
    {
        $called = 0;
        $this->server = new Server(null, null, function () use (&$called) {
            ++$called;

            return true;
        });

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
        $this->assertEquals(0, $called);
    }

    /** @group internet */
    public function testConnectionAuthenticationFromUriEncoded()
    {
        $this->server = new Server(null, null, array('name' => 'p@ss:w0rd'));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client(rawurlencode('name') . ':' . rawurlencode('p@ss:w0rd') . '@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationFromUriWithOnlyUserAndNoPassword()
    {
        $this->server = new Server(null, null, array('empty' => ''));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('empty@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationEmptyPassword()
    {
        $this->server = new Server(null, null, array('user' => ''));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('user@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testConnectionAuthenticationUnused()
    {
        $this->client = new Client('name:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertResolveStream($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidNoAuthenticationOverLegacySocks4()
    {
        $this->server = new Server(null, null, array('name' => 'pass'));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('socks4://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'));
    }

    public function testConnectionInvalidNoAuthentication()
    {
        $this->server = new Server(null, null, array('name' => 'pass'));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('socks5://127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), null, defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
    }

    public function testConnectionInvalidAuthenticationMismatch()
    {
        $this->server = new Server(null, null, array('name' => 'pass'));

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('user:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), null, defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
    }

    public function testConnectionInvalidAuthenticatorReturnsFalse()
    {
        $this->server = new Server(null, null, function () {
            return false;
        });

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('user:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), null, defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
    }

    public function testConnectionInvalidAuthenticatorReturnsPromiseFulfilledWithFalse()
    {
        $this->server = new Server(null, null, function () {
            return \React\Promise\resolve(false);
        });

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('user:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), null, defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
    }

    public function testConnectionInvalidAuthenticatorReturnsPromiseRejected()
    {
        $this->server = new Server(null, null, function () {
            return \React\Promise\reject();
        });

        $socket = new SocketServer('127.0.0.1:0');
        $this->server->listen($socket);
        $this->port = parse_url($socket->getAddress(), PHP_URL_PORT);

        $this->client = new Client('user:pass@127.0.0.1:' . $this->port, $this->connector);

        $this->assertRejectPromise($this->client->connect('www.google.com:80'), null, defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
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
        $tcp = new TimeoutConnector($this->client, 0.1);

        $this->assertRejectPromise($tcp->connect('www.google.com:8080'));
    }

    /** @group internet */
    public function testSecureConnectorOkay()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on HHVM');
        }

        $ssl = new SecureConnector($this->client);

        $this->assertResolveStream($ssl->connect('www.google.com:443'));
    }

    /** @group internet */
    public function testSecureConnectorToBadSslWithVerifyFails()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client, null, array('verify_peer' => true));

        $this->assertRejectPromise($ssl->connect('self-signed.badssl.com:443'));
    }

    /** @group internet */
    public function testSecureConnectorToBadSslWithoutVerifyWorks()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on HHVM');
        }

        $ssl = new SecureConnector($this->client, null, array('verify_peer' => false));

        $this->assertResolveStream($ssl->connect('self-signed.badssl.com:443'));
    }

    /** @group internet */
    public function testSecureConnectorInvalidPlaintextIsNotSsl()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = new SecureConnector($this->client);

        $this->assertRejectPromise($ssl->connect('www.google.com:80'));
    }

    /** @group internet */
    public function testSecureConnectorInvalidUnboundPortTimeout()
    {
        $ssl = new SecureConnector($this->client);

        // time out the connection attempt in 0.1s (as expected)
        $ssl = new TimeoutConnector($ssl, 0.1);

        $this->assertRejectPromise($ssl->connect('www.google.com:8080'));
    }

    private function assertResolveStream($promise)
    {
        $this->expectPromiseResolve($promise);

        $promise->then(function ($stream) {
            $stream->close();
        });

        Block\await($promise, Loop::get(), 2.0);
    }

    private function assertRejectPromise($promise, $message = null, $code = null)
    {
        $this->expectPromiseReject($promise);

        if (method_exists($this, 'expectException')) {
            $this->expectException('Exception');
            if ($message !== null) {
                $this->expectExceptionMessage($message);
            }
            if ($code !== null) {
                $this->expectExceptionCode($code);
            }
        } else {
            $this->setExpectedException('Exception', $message, $code);
        }

        Block\await($promise, Loop::get(), 2.0);
    }
}
