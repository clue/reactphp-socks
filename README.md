# SOCKS client library

Async SOCKS client library to connect to SOCKS4, SOCKS4a and SOCKS5 servers

## Description

The SOCKS protocol family can be used to easily tunnel TCP connections independent of the actual application level protocol, such as HTTP, SMTP, IMAP, Telnet, etc.

While SOCKS4 already had (a somewhat limited) support for `SOCKS BIND` requests and SOCKS5 added generic UDP support (`SOCKS UDPASSOCIATE`), this library focuses on the most commonly used core feature of `SOCKS CONNECT`. In this mode, a SOCKS server acts as a generic proxy allowing higher level application protocols to work through it.

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
    <th>Tunnel connections</th>
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
    <th>Username/Password authentication</th>
    <td>✗</td>
    <td>✗</td>
    <td>✓ (as per <a href="http://tools.ietf.org/html/rfc1929">RFC 1929</a>)</td>
  </tr>
  <tr>
    <th># handshake roundtrips</th>
    <td>1</td>
    <td>1</td>
    <td>2 (3 with authentication)</td>
  </tr>
  <tr>
    <th>Handshake traffic</th>
    <td>17 bytes</td>
    <td>17 bytes<br />(+ hostname + 1 for remote resolving)</td>
    <td><em>variable</em>?<br />(+ hostname + authentication + IPv6)</td>
  </tr>
  
</table>

## Example

Initialize connection to remote SOCKS server:

```PHP
<?php
include_once __DIR__.'/vendor/autoload.php';

$loop = $loop = React\EventLoop\Factory::create();

// use google's dns servers
$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

// create SOCKS client which communicates with SOCKS server 127.0.0.1:9050
$factory = new Socks\Factory($loop, $dns);
$client = $factory->createClient('127.0.0.1', 9050);

// now work with your $client, see below

$loop->start();
```

### Tunnelled TCP connections

`Socks` uses a [Promise](https://github.com/reactphp/promise)-based interface which makes working with asynchronous functions a breeze. Let's open up a TCP [Stream](https://github.com/reactphp/stream) connection and write some data:
```PHP
$client->getConnection('www.google.com',80)->then(function (React\Stream\Stream $stream) {
    echo 'connected to www.google.com:80';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});
```

### HTTP requests

Or if all you want to do is HTTP requests, `Socks` provides an even simpler [HTTP client](https://github.com/reactphp/http-client) interface:
```PHP
$httpclient = $client->createHttpClient();

$request = $httpclient->request('GET', 'https://www.google.com/', array('user-agent'=>'Custom/1.0'));
$request->on('response', function (React\HttpClient\Response $response) {
    var_dump('Headers received:', $response->getHeaders());
    
    // dump whole response body
    $response->on('data', function ($data) {
        echo $data;
    });
});
$request->end();
```
Yes, this works for both plain HTTP and SSL encrypted HTTPS requests.

### SSL/TLS encrypted

If you want to connect to arbitrary SSL/TLS servers, there sure too is an easy to use API available:
```PHP
$ssl = $client->createSecureConnectionManager();

// now create an SSL encrypted connection (notice the $ssl instead of $client)
$ssl->getConnection('www.google.com',443)->then(function (React\Stream\Stream $stream) {
    // proceed with just the plain text data and everything is encrypted/decrypted automatically
    echo 'connected to SSL encrypted www.google.com';
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    // ...
});
```

## Usage

### Using SSH as a SOCKS server

If you already have an SSH server set up, you can easily use it as a SOCKS tunnel end point. On your client, simply start your SSH client and use the `-D [port]` option to start a local SOCKS server (quoting the man page: a `local "dynamic" application-level port forwarding`) by issuing:

`$ ssh -D 9050 ssh-server`

```PHP
$client = $factory->createClient('127.0.0.1', 9050);
```

### Using the Tor (anonymity network) to tunnel SOCKS connections

The [Tor anonymity network](http://www.torproject.org) client software is designed to encrypt your traffic and route it over a network of several nodes to conceal its origin. It presents a SOCKS4 and SOCKS5 interface on TCP port 9050 by default which allows you to tunnel any traffic through the anonymity network. In most scenarios you probably don't want your client to resolve the target hostnames, because you would leak DNS information to anybody observing your local traffic. Also, Tor provides hidden services through an `.onion` pseudo top-level domain which have to be resolved by Tor.

```PHP

$client = $factory->createClient('127.0.0.1', 9050);
$client->setResolveLocal(false);
```

## Install

The recommended way to install this library is [through composer](http://getcomposer.org). [New to composer?](http://getcomposer.org/doc/00-intro.md)

```JSON
{
    "require": {
        "clue/Socks": "dev-master"
    }
}
```

## License

MIT, see license.txt

