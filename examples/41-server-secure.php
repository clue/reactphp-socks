<?php

// A more advanced example which runs a secure SOCKS over TLS proxy server.
// The listen address can be given as first argument and defaults to localhost:1080 otherwise.
//
// See also example #42 for the client side.

require __DIR__ . '/../vendor/autoload.php';

// start a new SOCKS proxy server
$server = new Clue\React\Socks\Server();

// listen on tls://127.0.0.1:1080 or first argument
$uri = 'tls://' . (isset($argv[1]) ? $argv[1] : '127.0.0.1:1080');
$socket = new React\Socket\SocketServer($uri, array(
    'tls' => array(
        'local_cert' => __DIR__ . '/localhost.pem',
    )
));
$server->listen($socket);

echo 'SOCKS over TLS server listening on ' . str_replace('tls:', 'sockss:', $socket->getAddress()) . PHP_EOL;
