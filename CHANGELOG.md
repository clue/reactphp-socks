# Changelog

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
