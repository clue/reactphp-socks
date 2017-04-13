# clue/socks-server [![Build Status](https://travis-ci.org/clue/php-socks-server.svg?branch=master)](https://travis-ci.org/clue/php-socks-server)

Async SOCKS proxy server (SOCKS4, SOCKS4a and SOCKS5), built on top of React PHP.

The SOCKS protocol family can be used to easily tunnel TCP connections independent
of the actual application level protocol, such as HTTP, SMTP, IMAP, Telnet etc.

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Server](#server)
    * [Protocol version](#protocol-version)
    * [Authentication](#authentication)
    * [Proxy chaining](#proxy-chaining)
* [Install](#install)
* [Tests](#tests)
* [License](#license)
* [More](#more)

## Quickstart example

Once [installed](#install), you can use the following code to create a SOCKS
proxy server listening for connections on `localhost:1080`:

```php
$loop = React\EventLoop\Factory::create();

// listen on localhost:1080
$socket = new Socket($loop);
$socket->listen(1080,'localhost');

// start a new server listening for incoming connection on the given socket
$server = new Server($loop, $socket);

$loop->run();
```

See also the [examples](examples).

## Usage

### Server

The `Server` is responsible for accepting incoming communication from SOCKS clients
and forwarding the requested connection to the target host.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage)
and an underlying TCP/IP socket server like this:

```php
$loop = \React\EventLoop\Factory::create();

// listen on localhost:$port
$socket = new Socket($loop);
$socket->listen($port,'localhost');

$server = new Server($loop, $socket);
```

If you need custom connector settings (DNS resolution, timeouts etc.), you can explicitly pass a
custom instance of the [`ConnectorInterface`](https://github.com/reactphp/socket-client#connectorinterface):

```php
// use local DNS server
$dnsResolverFactory = new DnsFactory();
$resolver = $dnsResolverFactory->createCached('127.0.0.1', $loop);

// outgoing connections to target host via interface 192.168.10.1
$connector = new DnsConnector(
    new TcpConnector($loop, array('bindto' => '192.168.10.1:0')),
    $resolver
);

$server = new Server($loop, $socket, $connector);
```

#### Protocol version

The `Server` supports all protocol versions (SOCKS4, SOCKS4a and SOCKS5) by default.

While SOCKS4 already had (a somewhat limited) support for `SOCKS BIND` requests
and SOCKS5 added generic UDP support (`SOCKS UDPASSOCIATE`), this library
focuses on the most commonly used core feature of `SOCKS CONNECT`.
In this mode, a SOCKS server acts as a generic proxy allowing higher level
application protocols to work through it.

<table>
  <tr>
    <th></th>
    <th>SOCKS4</th>
    <th>SOCKS4a</th>
    <th>SOCKS5</th>
  </tr>
  <tr>
    <th>Protocol specification</th>
    <td><a href="http://ftp.icm.edu.pl/packages/socks/socks4/SOCKS4.protocol">SOCKS4.protocol</a></td>
    <td><a href="http://ftp.icm.edu.pl/packages/socks/socks4/SOCKS4A.protocol">SOCKS4A.protocol</a></td>
    <td><a href="http://tools.ietf.org/html/rfc1928">RFC 1928</a></td>
  </tr>
  <tr>
    <th>Tunnel outgoing TCP connections</th>
    <td>✓</td>
    <td>✓</td>
    <td>✓</td>
  </tr>
  <tr>
    <th>Remote DNS resolving</th>
    <td>✗</td>
    <td>✓</td>
    <td>✓</td>
  </tr>
  <tr>
    <th>IPv6 addresses</th>
    <td>✗</td>
    <td>✗</td>
    <td>✓</td>
  </tr>
  <tr>
    <th><a href="#authentication">Username/Password authentication</a></th>
    <td>✗</td>
    <td>✗</td>
    <td>✓ (as per <a href="http://tools.ietf.org/html/rfc1929">RFC 1929</a>)</td>
  </tr>
  <tr>
    <th>Handshake # roundtrips</th>
    <td>1</td>
    <td>1</td>
    <td>2 (3 with authentication)</td>
  </tr>
  <tr>
    <th>Handshake traffic<br />+ remote DNS</th>
    <td>17 bytes<br />✗</td>
    <td>17 bytes<br />+ hostname + 1</td>
    <td><em>variable</em> (+ auth + IPv6)<br />+ hostname - 3</td>
  </tr>
</table>

Note, this is __not__ a full SOCKS5 implementation due to missing GSSAPI
authentication (but it's unlikely you're going to miss it anyway).

If want to explicitly set the protocol version, use the supported values `4`, `4a` or `5`:

```PHP
$server->setProtocolVersion(5);
```

In order to reset the protocol version to its default (i.e. automatic detection),
use `null` as protocol version.

```PHP
$server->setProtocolVersion(null);
```

#### Authentication

By default, the `Server` does not require any authentication from the clients.
You can enable authentication support so that clients need to pass a valid
username and password before forwarding any connections.

Setting authentication on the `Server` enforces each further connected client
to use protocol version 5 (SOCKS5).
If a client tries to use any other protocol version, does not send along
authentication details or if authentication details can not be verified,
the connection will be rejected.

Because your authentication mechanism might take some time to actually check
the provided authentication credentials (like querying a remote database or webservice),
the server side uses a [Promise](https://github.com/reactphp/promise) based interface.
While this might seem complex at first, it actually provides a very simple way
to handle simultanous connections in a non-blocking fashion and increases overall performance.

```PHP
$server->setAuth(function ($username, $password) {
    // either return a boolean success value right away
    // or use promises for delayed authentication
});
```

Or if you only accept static authentication details, you can use the simple
array-based authentication method as a shortcut:

```PHP
$server->setAuthArray(array(
    'tom' => 'password',
    'admin' => 'root'
));
```

See also the [second example](examples).

If you do not want to use authentication anymore:

```PHP
$server->unsetAuth();
```

#### Proxy chaining

The `Server` is responsible for creating connections to the target host.

```
Client -> SocksServer -> TargetHost
```

Sometimes it may be required to establish outgoing connections via another SOCKS
server.
For example, this can be useful if your target SOCKS server requires
authentication, but your client does not support sending authentication
information (e.g. like most webbrowser).

```
Client -> MiddlemanSocksServer -> TargetSocksServer -> TargetHost
```

The `Server` uses any instance of the `ConnectorInterface` to establish outgoing
connections.
In order to connect through another SOCKS server, you can simply use a SOCKS
connector from the following SOCKS client package:

```bash
$ composer require clue/socks-react:^0.6
```

You can now create a SOCKS `Client` instance like this: 

```php
// set next SOCKS server localhost:$targetPort as target
$connector = new React\SocketClient\TcpConnector($loop);
$client = new Clue\React\Socks\Client('user:pass@127.0.0.1:' . $targetPort, $connector);

// listen on localhost:$middlemanPort
$socket = new Socket($loop);
$socket->listen($middlemanPort, 'localhost');

// start a new server which forwards all connections to the other SOCKS server
$server = new Server($loop, $socket, $client);
```

See also the [example #11](examples).

Proxy chaining can happen on the server side and/or the client side:

* If you ask your client to chain through multiple proxies, then each proxy
  server does not really know anything about chaining at all.
  This means that this is a client-only property and not part of this project.
  For example, you can find this in the companion SOCKS client side project
  [clue/socks-react](https://github.com/clue/php-socks-react#proxy-chaining).

* If you ask your server to chain through another proxy, then your client does
  not really know anything about chaining at all.
  This means that this is a server-only property and can be implemented as above.

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/socks-server:^0.5.1
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](http://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT, see LICENSE

## More

* If you're looking for an end-user SOCKS server daemon, you may want to
  use [clue/psocksd](https://github.com/clue/psocksd).
* If you're looking for a SOCKS client implementation, consider using
  [clue/socks-react](https://github.com/clue/php-socks-react).
