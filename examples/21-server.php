<?php

// A simple example which runs a SOCKS proxy server.
// The listen address can be given as first argument and defaults to localhost:1080 otherwise.
//
// See also examples #12 and #14 for the client side.

require __DIR__ . '/../vendor/autoload.php';

// start a new SOCKS proxy server
$server = new Clue\React\Socks\Server();

// listen on 127.0.0.1:1080 or first argument
$socket = new React\Socket\Server(isset($argv[1]) ? $argv[1] : '127.0.0.1:1080');
$server->listen($socket);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;
