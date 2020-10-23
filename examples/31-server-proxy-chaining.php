<?php

// A more advanced example which runs a SOCKS proxy server that forwards to
// other SOCKS servers (proxy chaining).
// The listen address can be given as first argument.
// The upstream proxy servers can be given as additional arguments.
//
// See also examples #12 and #14 for the client side.

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[2])) {
    echo 'No arguments given! Run with <listen> <proxy1> [<proxyN>...]' . PHP_EOL;
    echo 'You can add 1..n proxies in the path' . PHP_EOL;
    exit(1);
}

$listen = $argv[1];
$path = array_slice($argv, 2);

// Alternatively, you can also hard-code these values like this:
//$listen = '127.0.0.1:9050';
//$path = array('127.0.0.1:9051', '127.0.0.1:9052', '127.0.0.1:9053');

$loop = React\EventLoop\Factory::create();

// set next SOCKS server chain -> p1 -> p2 -> p3 -> destination
$connector = new React\Socket\Connector($loop);
foreach ($path as $proxy) {
    $connector = new Clue\React\Socks\Client($proxy, $connector);
}

// start a new SOCKS proxy server which forwards all connections to the other SOCKS server
$server = new Clue\React\Socks\Server($loop, $connector);

// listen on 127.0.0.1:1080 or first argument
$socket = new React\Socket\Server($listen, $loop);
$server->listen($socket);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;
echo 'Forwarding via: ' . implode(' -> ', $path) . PHP_EOL;

$loop->run();
