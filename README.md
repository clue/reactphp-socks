# clue/socks-react [![Build Status](https://travis-ci.org/clue/php-socks-react.svg?branch=master)](https://travis-ci.org/clue/php-socks-react)

Async SOCKS client library to connect to SOCKS4, SOCKS4a and SOCKS5 proxy servers, built on top of React PHP.

The SOCKS protocol family can be used to easily tunnel TCP connections independent
of the actual application level protocol, such as HTTP, SMTP, IMAP, Telnet etc.

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [ConnectorInterface](#connectorinterface)
    * [create()](#create)
  * [Client](#client)
    * [Plain TCP connections](#plain-tcp-connections)
    * [Secure TLS connections](#secure-tls-connections)
    * [HTTP requests](#http-requests)
    * [Protocol version](#protocol-version)
    * [DNS resolution](#dns-resolution)
    * [Authentication](#authentication)
    * [Proxy chaining](#proxy-chaining)
    * [Connection timeout](#connection-timeout)
* [Servers](#servers)
  * [Using a PHP SOCKS server](#using-a-php-socks-server)
  * [Using SSH as a SOCKS server](#using-ssh-as-a-socks-server)
  * [Using the Tor (anonymity network) to tunnel SOCKS connections](#using-the-tor-anonymity-network-to-tunnel-socks-connections)
* [Install](#install)
* [License](#license)
* [More](#more)

## Quickstart example

Once [installed](#install), you can use the following code to create a connection
to google.com via a local SOCKS proxy server:

```php
$loop = React\EventLoop\Factory::create();
$client = new Client('127.0.0.1:9050', new TcpConnector($loop));

$client->create('www.google.com', 80)->then(function (Stream $stream) {
    $stream->write("GET / HTTP/1.0\r\n\r\n");
});

$loop->run();
```

See also the [examples](examples).

## Usage

### ConnectorInterface

The `ConnectorInterface` is responsible for providing an interface for
establishing streaming connections, such as a normal TCP/IP connection.

In order to use this library, you should understand how this integrates with its
ecosystem.
This base interface is actually defined in React's
[SocketClient component](https://github.com/reactphp/socket-client) and used
throughout React's ecosystem.

Most higher-level components (such as HTTP, database or other networking
service clients) accept an instance implementing this interface to create their
TCP/IP connection to the underlying networking service.
This is usually done via dependency injection, so it's fairly simple to actually
swap this implementation against this library in order to connect through a
SOCKS proxy server.

The interface only offers a single method:

#### create()

The `create(string $host, int $port): PromiseInterface<Stream, Exception>` method
can be used to establish a streaming connection.
It returns a [Promise](https://github.com/reactphp/promise) which either
fulfills with a [Stream](https://github.com/reactphp/stream) or
rejects with an `Exception`:

```php
$connector->create('google.com', 443)->then(
    function (Stream $stream) {
        // connection successfully established
    },
    function (Exception $error) {
        // failed to connect due to $error
    }
);
```

### Client

The `Client` is responsible for communication with your SOCKS server instance.
Its constructor simply accepts an SOCKS proxy URI and a connector used to
connect to the SOCKS proxy server address.

In its most simple form, you can simply pass React's
[`TcpConnector`](https://github.com/reactphp/socket-client#tcpconnector)
like this:

```php
$connector = new React\SocketClient\TcpConnector($loop);
$client = new Client('127.0.0.1:1080', $connector);

You can omit the port if you're using the default SOCKS port 1080:

```php
$client = new Client('127.0.0.1', $connector);
```

If you need custom connector settings (DNS resolution, timeouts etc.), you can explicitly pass a
custom instance of the [`ConnectorInterface`](https://github.com/reactphp/socket-client#connectorinterface):

```php
// use local DNS server
$dnsResolverFactory = new DnsFactory();
$resolver = $dnsResolverFactory->createCached('127.0.0.1', $loop);

// outgoing connections to SOCKS server via interface 192.168.10.1
// this is not to be confused with local DNS resolution (see further below)
$connector = new DnsConnector(
    new TcpConnector($loop, array('bindto' => '192.168.10.1:0')),
    $resolver
);

$client = new Client('my-socks-server.local:1080', $connector);
```

This is the main class in this package.
Because it implements the the [`ConnectorInterface`](#connectorinterface), it
can simply be used in place of a normal connector.
This makes it fairly simple to add SOCKS proxy support to pretty much any
higher-level component:

```diff
- $client = new SomeClient($connector);
+ $proxy = new Client('127.0.0.1:9050', $connector);
+ $client = new SomeClient($proxy);
```

#### Plain TCP connections

The `Client` implements the [`ConnectorInterface`](#connectorinterface) and
hence provides a single public method, the [`create()`](#create) method.
Let's open up a streaming TCP/IP connection and write some data:

```php
$client->create('www.google.com', 80)->then(function (React\Stream\Stream $stream) {
    echo 'connected to www.google.com:80';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});
```

See also the [first example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $tcp->create($host, $port);

$promise->cancel();
```

Calling `cancel()` on a pending promise will cancel the underlying TCP/IP
connection to the SOCKS server and/or the SOCKS protocol negonation and reject
the resulting promise.

#### Secure TLS connections

If you want to establish a secure TLS connection (such as HTTPS) between you and
your destination, you may want to wrap this connector in React's
[`SecureConnector`](https://github.com/reactphp/socket-client#secureconnector):

```php
$ssl = new React\SocketClient\SecureConnector($client, $loop);

// now create an SSL encrypted connection (notice the $ssl instead of $tcp)
$ssl->create('www.google.com',443)->then(function (React\Stream\Stream $stream) {
    // proceed with just the plain text data
    // everything is encrypted/decrypted automatically
    echo 'connected to SSL encrypted www.google.com';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});
```

See also the [second example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise
as usual.

> Also note how secure TLS connections are in fact entirely handled outside of this
SOCKS client implementation.

You can optionally pass additional
[SSL context options](http://php.net/manual/en/context.ssl.php)
to the constructor like this:

```php
$ssl = new React\SocketClient\SecureConnector($client, $loop, array(
    'verify_peer' => false,
    'verify_peer_name' => false
));
```

#### HTTP requests

HTTP operates on a higher layer than this low-level SOCKS implementation.
If you want to issue HTTP requests, you can add a dependency for
[clue/buzz-react](https://github.com/clue/php-buzz-react).
It can interact with this library by issuing all
[http requests through a SOCKS server](https://github.com/clue/php-buzz-react#socks-proxy).
This works for both plain HTTP and SSL encrypted HTTPS requests.

#### Protocol version

This library supports the SOCKS4, SOCKS4a and SOCKS5 protocol versions.

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
    <th><a href="#dns-resolution">Remote DNS resolving</a></th>
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

By default, the `Client` communicates via SOCKS4a with the SOCKS server
– unless you enable [authentication](#authentication), in which case it will
default to SOCKS5.
This is done because SOCKS4a incurs less overhead than SOCKS5 (see above) and
is equivalent with SOCKS4 if you use [local DNS resolution](#dns-resolution).

If want to explicitly set the protocol version, use the supported values URI
schemes `socks4`, `socks4a` or `socks5` as part of the SOCKS URI:

```php
$client = new Client('socks5://127.0.0.1', $connector);
```

As seen above, both SOCKS5 and SOCKS4a support remote and local DNS resolution.
If you've explicitly set this to SOCKS4, then you may want to check the following
chapter about local DNS resolution or you may only connect to IPv4 addresses.

#### DNS resolution

By default, the `Client` does not perform any DNS resolution at all and simply
forwards any hostname you're trying to connect to to the SOCKS server.
The remote SOCKS server is thus responsible for looking up any hostnames via DNS
(this default mode is thus called *remote DNS resolution*).
As seen above, this mode is supported by the SOCKS5 and SOCKS4a protocols, but
not the SOCKS4 protocol, as the protocol lacks a way to communicate hostnames.

On the other hand, all SOCKS protocol versions support sending destination IP
addresses to the SOCKS server.
In this mode you either have to stick to using IPs only (which is ofen unfeasable)
or perform any DNS lookups locally and only transmit the resolved destination IPs
(this mode is thus called *local DNS resolution*).

The default *remote DNS resolution* is useful if your local `Client` either can
not resolve target hostnames because it has no direct access to the internet or
if it should not resolve target hostnames because its outgoing DNS traffic might
be intercepted (in particular when using the
[Tor network](#using-the-tor-anonymity-network-to-tunnel-socks-connections)).

If you want to explicitly use *local DNS resolution* (such as when explicitly
using SOCKS4), you can use the following code:

```php
// usual client setup
$client = new Client($uri, $connector);

// set up DNS server to use (Google's public DNS here)
$factory = new React\Dns\Resolver\Factory();
$resolver = $factory->createCached('8.8.8.8', $loop);

// resolve hostnames via DNS before forwarding resulting IP trough SOCKS server
$dns = new React\SocketClient\DnsConnector($client, $resolver);

// secure TLS via the DNS connector
$ssl = new React\SocketClient\SecureConnector($dns, $loop);

$ssl->create('www.google.com', 443)->then(function ($stream) {
    // …
});
```

See also the [fourth example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise
as usual.

> Also note how local DNS resolution is in fact entirely handled outside of this
SOCKS client implementation.

If you've explicitly set the client to SOCKS4 and stick to the default
*remote DNS resolution*, then you may only connect to IPv4 addresses because
the protocol lacks a way to communicate hostnames.
If you try to connect to a hostname despite, the resulting promise will be
rejected right away.

#### Authentication

This library supports username/password authentication for SOCKS5 servers as
defined in [RFC 1929](http://tools.ietf.org/html/rfc1929).

On the client side, simply pass your username and password to use for
authentication (see below).
For each further connection the client will merely send a flag to the server
indicating authentication information is available.
Only if the server requests authentication during the initial handshake,
the actual authentication credentials will be transmitted to the server.

Note that the password is transmitted in cleartext to the SOCKS proxy server,
so this methods should not be used on a network where you have to worry about eavesdropping.

You can simply pass the authentication information as part of the SOCKS URI:

```php
$client = new Client('username:password@127.0.0.1', $connector);
```

Note that both the username and password must be percent-encoded if they contain
special characters:

```php
$user = 'he:llo';
$pass = 'p@ss';

$client = new Client(
    rawurlencode($user) . ':' . rawurlencode($pass) . '@127.0.0.1',
    $connector
);
```

Authentication is only supported by protocol version 5 (SOCKS5),
so passing authentication to the `Client` enforces communication with protocol
version 5 and complains if you have explicitly set anything else:

```php
// throws InvalidArgumentException
new Client('socks4://user:pass@127.0.0.1', $connector);
```

#### Proxy chaining

The `Client` is responsible for creating connections to the SOCKS server which
then connects to the target host.

```
Client -> SocksServer -> TargetHost
```

Sometimes it may be required to establish outgoing connections via another SOCKS
server.
For example, this can be useful if you want to conceal your origin address.

Client -> MiddlemanSocksServer -> TargetSocksServer -> TargetHost

The `Client` uses any instance of the `ConnectorInterface` to establish
outgoing connections.
In order to connect through another SOCKS server, you can simply use another
SOCKS connector from another SOCKS client like this:

```php
// https via the proxy chain  "MiddlemanSocksServer -> TargetSocksServer -> TargetHost"
// please note how the client uses TargetSocksServer (not MiddlemanSocksServer!),
// which in turn then uses MiddlemanSocksServer.
// this creates a TCP/IP connection to MiddlemanSocksServer, which then connects
// to TargetSocksServer, which then connects to the TargetHost
$middle = new Client($addressMiddle, new TcpConnector($loop));
$target = new Client($addressTarget, $middle);

$ssl = new React\SocketClient\SecureConnector($target, $loop);

$ssl->create('www.google.com', 443)->then(function ($stream) {
    // …
});
```

See also the [third example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise
as usual.

Proxy chaining can happen on the server side and/or the client side:

* If you ask your client to chain through multiple proxies, then each proxy
  server does not really know anything about chaining at all.
  This means that this is a client-only property.

* If you ask your server to chain through another proxy, then your client does
  not really know anything about chaining at all.
  This means that this is a server-only property and not part of this project.
  For example, you can find this in the companion SOCKS server side project
  [clue/socks-server](https://github.com/clue/php-socks-server#proxy-chaining)
  or somewhat similar when you're using the
  [Tor network](#using-the-tor-anonymity-network-to-tunnel-socks-connections).

#### Connection timeout

By default, neither of the above implements any timeouts for establishing remote
connections.
Your underlying operating system may impose limits on pending and/or idle TCP/IP
connections, anywhere in a range of a few minutes to several hours.

Many use cases require more control over the timeout and likely values much
smaller, usually in the range of a few seconds only.

You can use React's
[`TimeoutConnector`](https://github.com/reactphp/socket-client#timeoutconnector)
to decorate any given `ConnectorInterface` instance.
It provides the same `create()` method, but will automatically reject the
underlying connection attempt if it takes too long:

```php
$timeoutConnector = new React\SocketClient\TimeoutConnector($connector, 3.0, $loop);

$timeoutConnector->create('google.com', 80)->then(function ($stream) {
    // connection succeeded within 3.0 seconds
});
```

See also any of the [examples](examples).

Pending connection attempts can be cancelled by cancelling its pending promise
as usual.

> Also note how connection timeout is in fact entirely handled outside of this
SOCKS client implementation.

## Servers

### Using a PHP SOCKS server

* If you're looking for an end-user SOCKS server daemon, you may want to
  use [clue/psocksd](https://github.com/clue/psocksd).
* If you're looking for a SOCKS server implementation, consider using
  [clue/socks-server](https://github.com/clue/php-socks-server).

### Using SSH as a SOCKS server

If you already have an SSH server set up, you can easily use it as a SOCKS
tunnel end point. On your client, simply start your SSH client and use
the `-D [port]` option to start a local SOCKS server (quoting the man page:
a `local "dynamic" application-level port forwarding`) by issuing:

`$ ssh -D 9050 ssh-server`

```PHP
$client = new Client('127.0.0.1:9050', $connector);
```

### Using the Tor (anonymity network) to tunnel SOCKS connections

The [Tor anonymity network](http://www.torproject.org) client software is designed
to encrypt your traffic and route it over a network of several nodes to conceal its origin.
It presents a SOCKS4 and SOCKS5 interface on TCP port 9050 by default
which allows you to tunnel any traffic through the anonymity network.
In most scenarios you probably don't want your client to resolve the target hostnames,
because you would leak DNS information to anybody observing your local traffic.
Also, Tor provides hidden services through an `.onion` pseudo top-level domain
which have to be resolved by Tor.

```PHP
$client = new Client('127.0.0.1:9050', $connector);
```

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/socks-react:^0.6
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

## License

MIT, see LICENSE

## More

* If you want to learn more about processing streams of data, refer to the
  documentation of the underlying
  [react/stream](https://github.com/reactphp/stream) component.
* If you want to learn more about how the
  [`ConnectorInterface`](#connectorinterface) and its usual implementations look
  like, refer to the documentation of the underlying
  [react/socket-client](https://github.com/reactphp/socket-client) component.
* As an alternative to a SOCKS (SOCKS4/SOCKS5) proxy, you may also want to look into
  using an HTTP CONNECT proxy instead.
  You may want to use [clue/http-proxy-react](https://github.com/clue/php-http-proxy-react)
  which also provides an implementation of the
  [`ConnectorInterface`](#connectorinterface) so that supporting either proxy
  protocol should be fairly trivial.
* If you're dealing with public proxies, you'll likely have to work with mixed
  quality and unreliable proxies. You may want to look into using
  [clue/connection-manager-extra](https://github.com/clue/php-connection-manager-extra)
  which allows retrying unreliable ones, implying connection timeouts,
  concurrently working with multiple connectors and more.
* If you're looking for an end-user SOCKS server daemon, you may want to
  use [clue/psocksd](https://github.com/clue/psocksd).
* If you're looking for a SOCKS server implementation, consider using
  [clue/socks-server](https://github.com/clue/php-socks-server).
