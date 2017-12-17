# clue/socks-react [![Build Status](https://travis-ci.org/clue/php-socks-react.svg?branch=master)](https://travis-ci.org/clue/php-socks-react)

Async SOCKS4, SOCKS4a and SOCKS5 proxy client and server implementation, built on top of [ReactPHP](http://reactphp.org).

The SOCKS protocol family can be used to easily tunnel TCP connections independent
of the actual application level protocol, such as HTTP, SMTP, IMAP, Telnet etc.

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [ConnectorInterface](#connectorinterface)
    * [connect()](#connect)
  * [Client](#client)
    * [Plain TCP connections](#plain-tcp-connections)
    * [Secure TLS connections](#secure-tls-connections)
    * [HTTP requests](#http-requests)
    * [Protocol version](#protocol-version)
    * [DNS resolution](#dns-resolution)
    * [Authentication](#authentication)
    * [Proxy chaining](#proxy-chaining)
    * [Connection timeout](#connection-timeout)
    * [SOCKS over TLS](#socks-over-tls)
    * [Unix domain sockets](#unix-domain-sockets)
  * [Server](#server)
    * [Server connector](#server-connector)
    * [Protocol version](#server-protocol-version)
    * [Authentication](#server-authentication)
    * [Proxy chaining](#server-proxy-chaining)
    * [SOCKS over TLS](#server-socks-over-tls)
    * [Unix domain sockets](#server-unix-domain-sockets)
* [Servers](#servers)
  * [Using a PHP SOCKS server](#using-a-php-socks-server)
  * [Using SSH as a SOCKS server](#using-ssh-as-a-socks-server)
  * [Using the Tor (anonymity network) to tunnel SOCKS connections](#using-the-tor-anonymity-network-to-tunnel-socks-connections)
* [Install](#install)
* [Tests](#tests)
* [License](#license)
* [More](#more)

## Quickstart example

Once [installed](#install), you can use the following code to create a connection
to google.com via a local SOCKS proxy server:

```php
$loop = React\EventLoop\Factory::create();
$client = new Client('127.0.0.1:1080', new Connector($loop));

$client->connect('tcp://www.google.com:80')->then(function (ConnectionInterface $stream) {
    $stream->write("GET / HTTP/1.0\r\n\r\n");
});

$loop->run();
```

If you're not already running any other [SOCKS proxy server](#servers),
you can use the following code to create a SOCKS
proxy server listening for connections on `localhost:1080`:

```php
$loop = React\EventLoop\Factory::create();

// listen on localhost:1080
$socket = new Socket('127.0.0.1:1080', $loop);

// start a new server listening for incoming connection on the given socket
$server = new Server($loop, $socket);

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
[Socket component](https://github.com/reactphp/socket) and used
throughout React's ecosystem.

Most higher-level components (such as HTTP, database or other networking
service clients) accept an instance implementing this interface to create their
TCP/IP connection to the underlying networking service.
This is usually done via dependency injection, so it's fairly simple to actually
swap this implementation against this library in order to connect through a
SOCKS proxy server.

The interface only offers a single method:

#### connect()

The `connect(string $uri): PromiseInterface<ConnectionInterface, Exception>` method
can be used to establish a streaming connection.
It returns a [Promise](https://github.com/reactphp/promise) which either
fulfills with a [ConnectionInterface](https://github.com/reactphp/socket#connectioninterface) or
rejects with an `Exception`:

```php
$connector->connect('tcp://google.com:80')->then(
    function (ConnectionInterface $stream) {
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
[`Connector`](https://github.com/reactphp/socket#connector)
like this:

```php
$connector = new React\Socket\Connector($loop);
$client = new Client('127.0.0.1:1080', $connector);
```

You can omit the port if you're using the default SOCKS port 1080:

```php
$client = new Client('127.0.0.1', $connector);
```

If you need custom connector settings (DNS resolution, timeouts etc.), you can explicitly pass a
custom instance of the [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

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
+ $proxy = new Client('127.0.0.1:1080', $connector);
+ $client = new SomeClient($proxy);
```

#### Plain TCP connections

The `Client` implements the [`ConnectorInterface`](#connectorinterface) and
hence provides a single public method, the [`connect()`](#connect) method.
Let's open up a streaming TCP/IP connection and write some data:

```php
$client->connect('tcp://www.google.com:80')->then(function (ConnectonInterface $stream) {
    echo 'connected to www.google.com:80';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});
```

You can either use the `Client` directly or you may want to wrap this connector
in React's [`Connector`](https://github.com/reactphp/socket#connector):

```php
$connector = new React\Socket\Connector($loop, array(
    'tcp' => $client,
    'dns' => false
));

$connector->connect('tcp://www.google.com:80')->then(function (ConnectonInterface $stream) {
    echo 'connected to www.google.com:80';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});

```

See also the [first example](examples).

The `tcp://` scheme can also be omitted.
Passing any other scheme will reject the promise.

Pending connection attempts can be canceled by canceling its pending promise like so:

```php
$promise = $connector->connect($uri);

$promise->cancel();
```

Calling `cancel()` on a pending promise will cancel the underlying TCP/IP
connection to the SOCKS server and/or the SOCKS protocol negotiation and reject
the resulting promise.

#### Secure TLS connections

If you want to establish a secure TLS connection (such as HTTPS) between you and
your destination, you may want to wrap this connector in React's
[`Connector`](https://github.com/reactphp/socket#connector) or the low-level
[`SecureConnector`](https://github.com/reactphp/socket#secureconnector):

```php
$connector = new React\Socket\Connector($loop, array(
    'tcp' => $client,
    'dns' => false
));

// now create an SSL encrypted connection (notice the $ssl instead of $tcp)
$connector->connect('tls://www.google.com:443')->then(function (ConnectionInterface $stream) {
    // proceed with just the plain text data
    // everything is encrypted/decrypted automatically
    echo 'connected to SSL encrypted www.google.com';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});
```

See also the [second example](examples).

If you use the low-level `SecureConnector`, then the `tls://` scheme can also
be omitted.
Passing any other scheme will reject the promise.

Pending connection attempts can be canceled by canceling its pending promise
as usual.

> Also note how secure TLS connections are in fact entirely handled outside of
  this SOCKS client implementation.

You can optionally pass additional
[SSL context options](http://php.net/manual/en/context.ssl.php)
to the constructor like this:

```php
$connector = new React\Socket\Connector($loop, array(
    'tcp' => $client,
    'tls' => array(
        'verify_peer' => false,
        'verify_peer_name' => false
    ),
    'dns' => false
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

As noted above, the `Client` defaults to using remote DNS resolution.
However, wrapping the `Client` in React's
[`Connector`](https://github.com/reactphp/socket#connector) actually
performs local DNS resolution unless explicitly defined otherwise.
Given that remote DNS resolution is assumed to be the preferred mode, all
other examples explicitly disable DNS resoltion like this:

```php
$connector = new React\Socket\Connector($loop, array(
    'tcp' => $client,
    'dns' => false
));
```

If you want to explicitly use *local DNS resolution* (such as when explicitly
using SOCKS4), you can use the following code:

```php
// set up Connector which uses Google's public DNS (8.8.8.8)
$connector = new React\Socket\Connector($loop, array(
    'tcp' => $client,
    'dns' => '8.8.8.8'
));
```

See also the [fourth example](examples).

Pending connection attempts can be canceled by canceling its pending promise
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

> The authentication details will be transmitted in cleartext to the SOCKS proxy
  server only if it requires username/password authentication.
  If the authentication details are missing or not accepted by the remote SOCKS
  proxy server, it is expected to reject each connection attempt with an
  exception error code of `SOCKET_EACCES` (13).

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

```
Client -> MiddlemanSocksServer -> TargetSocksServer -> TargetHost
```

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
$middle = new Client('127.0.0.1:1080', new Connector($loop));
$target = new Client('example.com:1080', $middle);

$connector = new React\Socket\Connector($loop, array(
    'tcp' => $target,
    'dns' => false
));

$connector->connect('tls://www.google.com:443')->then(function ($stream) {
    // …
});
```

See also the [third example](examples).

Pending connection attempts can be canceled by canceling its pending promise
as usual.

Proxy chaining can happen on the server side and/or the client side:

* If you ask your client to chain through multiple proxies, then each proxy
  server does not really know anything about chaining at all.
  This means that this is a client-only property.

* If you ask your server to chain through another proxy, then your client does
  not really know anything about chaining at all.
  This means that this is a server-only property and not part of this class.
  For example, you can find this in the below [`Server`](#server-proxy-chaining)
  class or somewhat similar when you're using the
  [Tor network](#using-the-tor-anonymity-network-to-tunnel-socks-connections).

#### Connection timeout

By default, the `Client` does not implement any timeouts for establishing remote
connections.
Your underlying operating system may impose limits on pending and/or idle TCP/IP
connections, anywhere in a range of a few minutes to several hours.

Many use cases require more control over the timeout and likely values much
smaller, usually in the range of a few seconds only.

You can use React's [`Connector`](https://github.com/reactphp/socket#connector)
or the low-level
[`TimeoutConnector`](https://github.com/reactphp/socket#timeoutconnector)
to decorate any given `ConnectorInterface` instance.
It provides the same `connect()` method, but will automatically reject the
underlying connection attempt if it takes too long:

```php
$connector = new Connector($loop, array(
    'tcp' => $client,
    'dns' => false,
    'timeout' => 3.0
));

$connector->connect('tcp://google.com:80')->then(function ($stream) {
    // connection succeeded within 3.0 seconds
});
```

See also any of the [examples](examples).

Pending connection attempts can be canceled by canceling its pending promise
as usual.

> Also note how connection timeout is in fact entirely handled outside of this
  SOCKS client implementation.

#### SOCKS over TLS

All [SOCKS protocol versions](#protocol-version) support forwarding TCP/IP
based connections and higher level protocols.
This implies that you can also use [secure TLS connections](#secure-tls-connections)
to transfer sensitive data across SOCKS proxy servers.
This means that no eavesdropper nor the proxy server will be able to decrypt
your data.

However, the initial SOCKS communication between the client and the proxy is
usually via an unencrypted, plain TCP/IP connection.
This means that an eavesdropper may be able to see *where* you connect to and
may also be able to see your [SOCKS authentication](#authentication) details
in cleartext.

As an alternative, you may establish a secure TLS connection to your SOCKS
proxy before starting the initial SOCKS communication.
This means that no eavesdroppper will be able to see the destination address
you want to connect to or your [SOCKS authentication](#authentication) details.

You can use the `sockss://` URI scheme or use an explicit
[SOCKS protocol version](#protocol-version) like this:

```php
$client = new Client('sockss://127.0.0.1:1080', new Connector($loop));

$client = new Client('socks5s://127.0.0.1:1080', new Connector($loop));
```

See also [example 32](examples).

Simiarly, you can also combine this with [authentication](#authentication)
like this:

```php
$client = new Client('sockss://user:pass@127.0.0.1:1080', new Connector($loop));
```

> Note that for most use cases, [secure TLS connections](#secure-tls-connections)
  should be used instead. SOCKS over TLS is considered advanced usage and is
  used very rarely in practice.
  In particular, the SOCKS server has to accept secure TLS connections, see
  also [Server SOCKS over TLS](#server-socks-over-tls) for more details.
  Also, PHP does not support "double encryption" over a single connection.
  This means that enabling [secure TLS connections](#secure-tls-connections)
  over a communication channel that has been opened with SOCKS over TLS
  may not be supported.

> Note that the SOCKS protocol does not support the notion of TLS. The above
  works reasonably well because TLS is only used for the connection between
  client and proxy server and the SOCKS protocol data is otherwise identical.
  This implies that this may also have only limited support for
  [proxy chaining](#proxy-chaining) over multiple TLS paths.

#### Unix domain sockets

All [SOCKS protocol versions](#protocol-version) support forwarding TCP/IP
based connections and higher level protocols.
In some advanced cases, it may be useful to let your SOCKS server listen on a
Unix domain socket (UDS) path instead of a IP:port combination.
For example, this allows you to rely on file system permissions instead of
having to rely on explicit [authentication](#authentication).

You can use the `socks+unix://` URI scheme or use an explicit
[SOCKS protocol version](#protocol-version) like this:

```php
$client = new Client('socks+unix:///tmp/proxy.sock', new Connector($loop));

$client = new Client('socks5+unix:///tmp/proxy.sock', new Connector($loop));
```

Simiarly, you can also combine this with [authentication](#authentication)
like this:

```php
$client = new Client('socks+unix://user:pass@/tmp/proxy.sock', new Connector($loop));
```

> Note that Unix domain sockets (UDS) are considered advanced usage and PHP only
  has limited support for this.
  In particular, enabling [secure TLS](#secure-tls-connections) may not be
  supported.

> Note that SOCKS protocol does not support the notion of UDS paths. The above
  works reasonably well because UDS is only used for the connection between
  client and proxy server and the path will not actually passed over the protocol.
  This implies that this does also not support [proxy chaining](#proxy-chaining)
  over multiple UDS paths.

### Server

The `Server` is responsible for accepting incoming communication from SOCKS clients
and forwarding the requested connection to the target host.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage)
and an underlying TCP/IP socket server like this:

```php
$loop = \React\EventLoop\Factory::create();

// listen on localhost:$port
$socket = new Socket($port, $loop);

$server = new Server($loop, $socket);
```

#### Server connector

The `Server` uses an instance of the [`ConnectorInterface`](#connectorinterface)
to establish outgoing connections for each incoming connection request.

If you need custom connector settings (DNS resolution, timeouts etc.), you can explicitly pass a
custom instance of the [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

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

If you want to forward the outgoing connection through another SOCKS proxy, you
may also pass a [`Client`](#client) instance as a connector, see also
[server proxy chaining](#server-proxy-chaining) for more details.

Internally, the `Server` uses the normal [`connect()`](#connect) method, but
it also passes the original client IP as the `?source={remote}` parameter.
The `source` parameter contains the full remote URI, including the protocol
and any authentication details, for example `socks5://user:pass@1.2.3.4:5678`.
You can use this parameter for logging purposes or to restrict connection
requests for certain clients by providing a custom implementation of the
[`ConnectorInterface`](#connectorinterface).

#### Server protocol version

The `Server` supports all protocol versions (SOCKS4, SOCKS4a and SOCKS5) by default.

If want to explicitly set the protocol version, use the supported values `4`, `4a` or `5`:

```PHP
$server->setProtocolVersion(5);
```

In order to reset the protocol version to its default (i.e. automatic detection),
use `null` as protocol version.

```PHP
$server->setProtocolVersion(null);
```

#### Server authentication

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
$server->setAuth(function ($username, $password, $remote) {
    // either return a boolean success value right away
    // or use promises for delayed authentication

    // $remote is a full URI à la socks5://user:pass@192.168.1.1:1234
    // or socks5s://user:pass@192.168.1.1:1234 for SOCKS over TLS
    // useful for logging or extracting parts, such as the remote IP
    $ip = parse_url($remote, PHP_URL_HOST);

    return ($username === 'root' && $ip === '127.0.0.1');
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

See also [example #12](examples).

If you do not want to use authentication anymore:

```PHP
$server->unsetAuth();
```

#### Server proxy chaining

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
In order to connect through another SOCKS server, you can simply use the
[`Client`](#client) SOCKS connector from above.
You can create a SOCKS `Client` instance like this: 

```php
// set next SOCKS server example.com:1080 as target
$connector = new React\Socket\Connector($loop);
$client = new Client('user:pass@example.com:1080', $connector);

// listen on localhost:1080
$socket = new Socket('127.0.0.1:1080', $loop);

// start a new server which forwards all connections to the other SOCKS server
$server = new Server($loop, $socket, $client);
```

See also [example #21](examples).

Proxy chaining can happen on the server side and/or the client side:

* If you ask your client to chain through multiple proxies, then each proxy
  server does not really know anything about chaining at all.
  This means that this is a client-only property and not part of this class.
  For example, you can find this in the above [`Client`](#proxy-chaining) class.

* If you ask your server to chain through another proxy, then your client does
  not really know anything about chaining at all.
  This means that this is a server-only property and can be implemented as above.

#### Server SOCKS over TLS

All [SOCKS protocol versions](#server-protocol-version) support forwarding TCP/IP
based connections and higher level protocols.
This implies that you can also use [secure TLS connections](#secure-tls-connections)
to transfer sensitive data across SOCKS proxy servers.
This means that no eavesdropper nor the proxy server will be able to decrypt
your data.

However, the initial SOCKS communication between the client and the proxy is
usually via an unencrypted, plain TCP/IP connection.
This means that an eavesdropper may be able to see *where* the client connects
to and may also be able to see the [SOCKS authentication](#authentication)
details in cleartext.

As an alternative, you may listen for SOCKS over TLS connections so
that the client has to establish a secure TLS connection to your SOCKS
proxy before starting the initial SOCKS communication.
This means that no eavesdroppper will be able to see the destination address
the client wants to connect to or their [SOCKS authentication](#authentication)
details.

You can simply start your listening socket on the `tls://` URI scheme like this:

```php
$loop = \React\EventLoop\Factory::create();

// listen on tls://127.0.0.1:1080 with the given server certificate
$socket = new React\Socket\Server('tls://127.0.0.1:1080', $loop, array(
    'tls' => array(
        'local_cert' => __DIR__ . '/localhost.pem',
    )
));
$server = new Server($loop, $socket);
```

See also [example 31](examples).

> Note that for most use cases, [secure TLS connections](#secure-tls-connections)
  should be used instead. SOCKS over TLS is considered advanced usage and is
  used very rarely in practice.

> Note that the SOCKS protocol does not support the notion of TLS. The above
  works reasonably well because TLS is only used for the connection between
  client and proxy server and the SOCKS protocol data is otherwise identical.
  This implies that this does also not support [proxy chaining](#server-proxy-chaining)
  over multiple TLS paths.

#### Server Unix domain sockets

All [SOCKS protocol versions](#server-protocol-version) support forwarding TCP/IP
based connections and higher level protocols.
In some advanced cases, it may be useful to let your SOCKS server listen on a
Unix domain socket (UDS) path instead of a IP:port combination.
For example, this allows you to rely on file system permissions instead of
having to rely on explicit [authentication](#server-authentication).

You can simply start your listening socket on the `unix://` URI scheme like this:

```php
$loop = \React\EventLoop\Factory::create();

// listen on /tmp/proxy.sock
$socket = new React\Socket\Server('unix:///tmp/proxy.sock', $loop);
$server = new Server($loop, $socket);
```

> Note that Unix domain sockets (UDS) are considered advanced usage and that
  the SOCKS protocol does not support the notion of UDS paths. The above
  works reasonably well because UDS is only used for the connection between
  client and proxy server and the path will not actually passed over the protocol.
  This implies that this does also not support [proxy chaining](#server-proxy-chaining)
  over multiple UDS paths.

## Servers

### Using a PHP SOCKS server

* If you're looking for an end-user SOCKS server daemon, you may want to use
  [LeProxy](https://leproxy.org/) or [clue/psocksd](https://github.com/clue/psocksd).
* If you're looking for a SOCKS server implementation, consider using
  the above [`Server`](#server) class.

### Using SSH as a SOCKS server

If you already have an SSH server set up, you can easily use it as a SOCKS
tunnel end point. On your client, simply start your SSH client and use
the `-D <port>` option to start a local SOCKS server (quoting the man page:
a `local "dynamic" application-level port forwarding`).

You can start a local SOCKS server by creating a loopback connection to your
local system if you already run an SSH daemon:

```bash
$ ssh -D 1080 localhost
```

Alternatively, you can start a local SOCKS server tunneling through a given
remote host that runs an SSH daemon:

```bash
$ ssh -D 1080 example.com
```

Now you can simply use this SSH SOCKS server like this:

```PHP
$client = new Client('127.0.0.1:1080', $connector);
```

Note that the above will allow all users on the local system to connect over
your SOCKS server without authentication which may or may not be what you need.
As an alternative, recent OpenSSH client versions also support
[Unix domain sockets](#unix-domain-sockets) (UDS) paths so that you can rely
on Unix file system permissions instead:

```bash
$ ssh -D/tmp/proxy.sock example.com
```

Now you can simply use this SSH SOCKS server like this:

```PHP
$client = new Client('socks+unix:///tmp/proxy.sock', $connector);
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

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/socks-react:^0.8.7
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

The test suite contains a number of tests that rely on a working internet
connection, alternatively you can also run it like this:

```bash
$ php vendor/bin/phpunit --exclude-group internet
```

## License

MIT, see LICENSE

## More

* If you want to learn more about processing streams of data, refer to the
  documentation of the underlying
  [react/stream](https://github.com/reactphp/stream) component.
* If you want to learn more about how the
  [`ConnectorInterface`](#connectorinterface) and its usual implementations look
  like, refer to the documentation of the underlying
  [react/socket component](https://github.com/reactphp/socket).
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
* If you're looking for an end-user SOCKS server daemon, you may want to use
  [LeProxy](https://leproxy.org/) or [clue/psocksd](https://github.com/clue/psocksd).
