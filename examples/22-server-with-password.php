<?php

// A simple example which runs a SOCKS proxy server with hard-coded authentication details.
// The listen address can be given as first argument and defaults to localhost:1080 otherwise.
//
// See also examples #12 and #14 for the client side.
//
// Note that the client examples do not pass any authentication details by default
// and as such will fail to authenticate against this example server. You can
// explicitly pass authentication details to the client example like this:
//
// $ php examples/12-optional-proxy-raw-http-protocol.php tom:god@localhost:1080

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// start a new SOCKS proxy server
// require authentication and hence make this a SOCKS5-only server
$server = new Clue\React\Socks\Server($loop, null, array(
    'tom' => 'god',
    'user' => 'p@ssw0rd'
));

// listen on 127.0.0.1:1080 or first argument
$socket = new React\Socket\Server(isset($argv[1]) ? $argv[1] : '127.0.0.1:1080', $loop);
$server->listen($socket);

echo 'SOCKS5 server requiring authentication listening on ' . $socket->getAddress() . PHP_EOL;

$loop->run();
