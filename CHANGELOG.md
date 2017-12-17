# Changelog

## 0.8.7 (2017-12-17)

*   Feature: Support SOCKS over TLS (`sockss://` URI scheme)
    (#70 and #71 by @clue)

    ```php
    // new: now supports SOCKS over TLS
    $client = new Client('socks5s://localhost', $connector);
    ```

*   Feature: Support communication over Unix domain sockets (UDS)
    (#69 by @clue)

    ```php
    // new: now supports SOCKS over Unix domain sockets (UDS)
    $client = new Client('socks5+unix:///tmp/proxy.sock', $connector);
    ```

*   Improve test suite by adding forward compatibility with PHPUnit 6
    (#68 by @clue)

## 0.8.6 (2017-09-17)

*   Feature: Forward compatibility with Evenement v3.0
    (#67 by @WyriHaximus)

## 0.8.5 (2017-09-01)

*   Feature: Use socket error codes for connection rejections
    (#63 by @clue)
    
    ```php
    $promise = $proxy->connect('imap.example.com:143');
    $promise->then(null, function (Exeption $e) {
        if ($e->getCode() === SOCKET_EACCES) {
            echo 'Failed to authenticate with proxy!';
        }
        throw $e;
    });
    ```

*   Feature: Report matching SOCKS5 error codes for server side connection errors
    (#62 by @clue)

*   Fix: Fix SOCKS5 client receiving destination hostnames and
    fix IPv6 addresses as hostnames for TLS certificates
    (#64 and #65 by @clue)

*   Improve test suite by locking Travis distro so new defaults will not break the build and
    optionally exclude tests that rely on working internet connection
    (#61 and #66 by @clue)

## 0.8.4 (2017-07-27)

*   Feature: Server now passes client source address to Connector
    (#60 by @clue)

## 0.8.3 (2017-07-18)

*   Feature: Pass full remote URI as parameter to authentication callback
    (#58 by @clue)

    ```php
    // new third parameter passed to authentication callback
    $server->setAuth(function ($user, $pass, $remote) {
        $ip = parse_url($remote, PHP_URL_HOST);
        
        return ($ip === '127.0.0.1');
    });
    ```

*   Fix: Fix connecting to IPv6 address via SOCKS5 server and validate target
    URI so hostname can not contain excessive URI components
    (#59 by @clue)

*   Improve test suite by fixing HHVM build for now again and ignore future HHVM build errors
    (#57 by @clue)

## 0.8.2 (2017-05-09)

*   Feature: Forward compatibility with upcoming Socket v1.0 and v0.8
    (#56 by @clue)

## 0.8.1 (2017-04-21)

*   Update examples to use URIs with default port 1080 and accept proxy URI arguments
    (#54 by @clue)

*   Remove now unneeded dependency on `react/stream`
    (#55 by @clue)

## 0.8.0 (2017-04-18)

*   Feature: Merge `Server` class from clue/socks-server
    (#52 by @clue)

    ```php
    $socket = new React\Socket\Server(1080, $loop);
    $server = new Clue\React\Socks\Server($loop, $socket);
    ```

    > Upgrading from [clue/socks-server](https://github.com/clue/php-socks-server)?
      The classes have been moved as-is, so you can simply start using the new
      class name `Clue\React\Socks\Server` with no other changes required.

## 0.7.0 (2017-04-14)

*   Feature / BC break: Replace depreacted SocketClient with Socket v0.7 and
    use `connect($uri)` instead of `create($host, $port)`
    (#51 by @clue)

    ```php
    // old
    $connector = new React\SocketClient\TcpConnector($loop);
    $client = new Client(1080, $connector);
    $client->create('google.com', 80)->then(function (Stream $conn) {
        $conn->write("…");
    });

    // new
    $connector = new React\Socket\TcpConnector($loop);
    $client = new Client(1080, $connector);
    $client->connect('google.com:80')->then(function (ConnectionInterface $conn) {
        $conn->write("…");
    });
    ```

*   Improve test suite by adding PHPUnit to require-dev
    (#50 by @clue)

## 0.6.0 (2016-11-29)

* Feature / BC break: Pass connector into `Client` instead of loop, remove unneeded deps
  (#49 by @clue)

  ```php
  // old (connector is create implicitly)
  $client = new Client('127.0.0.1', $loop);

  // old (connector can optionally be passed)
  $client = new Client('127.0.0.1', $loop, $connector);

  // new (connector is now mandatory)
  $connector = new React\SocketClient\TcpConnector($loop);
  $client = new Client('127.0.0.1', $connector);
  ```

* Feature / BC break: `Client` now implements `ConnectorInterface`, remove `Connector` adapter
  (#47 by @clue)

  ```php
  // old (explicit connector functions as an adapter)
  $connector = $client->createConnector();
  $promise = $connector->create('google.com', 80);

  // new (client can be used as connector right away)
  $promise = $client->create('google.com', 80);
  ```

* Feature / BC break: Remove `createSecureConnector()`, use `SecureConnector` instead
  (#47 by @clue)

  ```php
  // old (tight coupling and hidden dependency)
  $tls = $client->createSecureConnector();
  $promise = $tls->create('google.com', 443);

  // new (more explicit, loose coupling)
  $tls = new React\SocketClient\SecureConnector($client, $loop);
  $promise = $tls->create('google.com', 443);
  ```

* Feature / BC break: Remove `setResolveLocal()` and local DNS resolution and default to remote DNS resolution, use `DnsConnector` instead
  (#44 by @clue)

  ```php
  // old (implicitly defaults to true, can be disabled)
  $client->setResolveLocal(false);
  $tcp = $client->createConnector();
  $promise = $tcp->create('google.com', 80);

  // new (always disabled, can be re-enabled like this)
  $factory = new React\Dns\Resolver\Factory();
  $resolver = $factory->createCached('8.8.8.8', $loop);
  $tcp = new React\SocketClient\DnsConnector($client, $resolver);
  $promise = $tcp->create('google.com', 80);
  ```

* Feature / BC break: Remove `setTimeout()`, use `TimeoutConnector` instead
  (#45 by @clue)

  ```php
  // old (timeout only applies to TCP/IP connection)
  $client = new Client('127.0.0.1', …);
  $client->setTimeout(3.0);
  $tcp = $client->createConnector();
  $promise = $tcp->create('google.com', 80);

  // new (timeout can be added to any layer)
  $client = new Client('127.0.0.1', …);
  $tcp = new React\SocketClient\TimeoutConnector($client, 3.0, $loop);
  $promise = $tcp->create('google.com', 80);
  ```

* Feature / BC break: Remove `setProtocolVersion()` and `setAuth()` mutators, only support SOCKS URI for protocol version and authentication (immutable API)
  (#46 by @clue)

  ```php
  // old (state can be mutated after instantiation)
  $client = new Client('127.0.0.1', …);
  $client->setProtocolVersion('5');
  $client->setAuth('user', 'pass');

  // new (immutable after construction, already supported as of v0.5.2 - now mandatory)
  $client = new Client('socks5://user:pass@127.0.0.1', …);
  ```

## 0.5.2 (2016-11-25)

* Feature: Apply protocol version and username/password auth from SOCKS URI
  (#43 by @clue)

  ```php
  // explicitly use SOCKS5
  $client = new Client('socks5://127.0.0.1', $loop);

  // use authentication (automatically SOCKS5)
  $client = new Client('user:pass@127.0.0.1', $loop);
  ```

* More explicit client examples, including proxy chaining
  (#42 by @clue)

## 0.5.1 (2016-11-21)

* Feature: Support Promise cancellation
  (#39 by @clue)

  ```php
  $promise = $connector->create($host, $port);

  $promise->cancel();
  ```

* Feature: Timeout now cancels pending connection attempt
  (#39, #22 by @clue)

## 0.5.0 (2016-11-07)

* Remove / BC break: Split off Server to clue/socks-server
  (#35 by @clue)

  > Upgrading? Check [clue/socks-server](https://github.com/clue/php-socks-server) for details.

* Improve documentation and project structure

## 0.4.0 (2016-03-19)

* Feature: Support proper SSL/TLS connections with additional SSL context options
  (#31, #33 by @clue)

* Documentation for advanced Connector setups (bindto, multihop)
  (#32 by @clue)

## 0.3.0 (2015-06-20)

* BC break / Feature: Client ctor now accepts a SOCKS server URI
  ([#24](https://github.com/clue/php-socks-react/pull/24))
  
    ```php
// old
$client = new Client($loop, 'localhost', 9050);

// new
$client = new Client('localhost:9050', $loop);
```

* Feature: Automatically assume default SOCKS port (1080) if not given explicitly
  ([#26](https://github.com/clue/php-socks-react/pull/26))

* Improve documentation and test suite

## 0.2.1 (2014-11-13)

* Support React PHP v0.4 (while preserving BC with React PHP v0.3)
  ([#16](https://github.com/clue/php-socks-react/pull/16))

* Improve examples and add first class support for HHVM
  ([#15](https://github.com/clue/php-socks-react/pull/15) and [#17](https://github.com/clue/php-socks-react/pull/17))

## 0.2.0 (2014-09-27)

* BC break / Feature: Simplify constructors by making parameters optional.
  ([#10](https://github.com/clue/php-socks-react/pull/10))
  
  The `Factory` has been removed, you can now create instances of the `Client`
  and `Server` yourself:
  
  ```php
  // old
  $factory = new Factory($loop, $dns);
  $client = $factory->createClient('localhost', 9050);
  $server = $factory->createSever($socket);
  
  // new
  $client = new Client($loop, 'localhost', 9050);
  $server = new Server($loop, $socket);
  ```

* BC break: Remove HTTP support and link to [clue/buzz-react](https://github.com/clue/php-buzz-react) instead.
  ([#9](https://github.com/clue/php-socks-react/pull/9))
  
  HTTP operates on a different layer than this low-level SOCKS library.
  Removing this reduces the footprint of this library.
  
  > Upgrading? Check the [README](https://github.com/clue/php-socks-react#http-requests) for details.  

* Fix: Refactored to support other, faster loops (libev/libevent)
  ([#12](https://github.com/clue/php-socks-react/pull/12))

* Explicitly list dependencies, clean up examples and extend test suite significantly

## 0.1.0 (2014-05-19)

* First stable release
* Async SOCKS `Client` and `Server` implementation
* Project was originally part of [clue/socks](https://github.com/clue/php-socks)
  and was split off from its latest releave v0.4.0
  ([#1](https://github.com/clue/reactphp-socks/issues/1))

> Upgrading from clue/socks v0.4.0? Use namespace `Clue\React\Socks` instead of `Socks` and you're ready to go!

## 0.0.0 (2011-04-26)

* Initial concept, originally tracked as part of
  [clue/socks](https://github.com/clue/php-socks)
