# SOCKS client library

Async SOCKS client library to connect to SOCKS4, SOCKS4a and SOCKS5 servers

## Description

TODO: Full description, simple introduction, what is SOCKS, how does it work, what does this library actually do.

## Example

TODO:
```PHP

// see example.php

```

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

## TODO

* Publish on packagist
* Automatically pick *best* SOCKS version available

## License

MIT, see license.txt

