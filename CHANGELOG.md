# CHANGELOG

This file is a manually maintained list of changes for each release. Feel free
to add your changes here when sending pull requests. Also send corrections if
you spot any mistakes.

## 0.4.0 (2013-XX-XX)

* BC break: Update react to current v0.3 and thus also replace `ConnectionManager` with `Connector`
* BC break: New `Client::createConnector()` replaces inheriting `ConnectionManagerInterface`
* BC break: New `Client::createSecureConnector()` replaces `Client::createSecureConnectionManager()` 

## 0.3.1 (2012-12-29)

* Fix: Server event logging
* Fix: Closing invalid connections

## 0.3.0 (2012-12-23)

* Feature: Add async `Server` implementation

## 0.2.0 (2012-12-03)

* BC break: Whole new API, now using async patterns based on react/react
* BC break: Re-organize into `Socks` namespace
* Feature: Whole new async API: `PromiseInterface Client::getConnection(string $hostname, int $port)`
* Feature: SOCKS5 username/password authentication: `Client::setAuth(string $username, string $password)`
* Feature: SOCKS4a/SOCKS5 support local *and* remote resolving: `Client::setResolveLocal(boolean $resolveLocal)`
* Feature: SOCKS protocol can now be switched during runtime: `Client::setProtocolVersion(string $version)`
* Feature: Simple interface for HTTP over SOCKS: `HttpClient Client::createHttpClient()`
* Feature: Simple interface for SSL/TLS over SOCKS: `Client` now implements `ConnectionManagerInterface`
* Feature: Simple interface for TCP over SOCKS: `SecureConnectionManager Client::createSecureConnectionManager()`

## 0.1.0 (2011-05-16)

* First tagged release
* Simple, blocking API: `resource Socks::connect(string $hostname, int $port)`
* Support for SOCKS4, SOCKS4a, SOCKS5 and hostname, IPv4 and IPv6 addressing

