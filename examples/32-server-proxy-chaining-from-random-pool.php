<?php

// A more advanced example which runs a SOCKS proxy server that randomly forwards
// to a pool of SOCKS servers (random proxy chaining).
// The listen address can be given as first argument.
// The upstream proxy servers can be given as additional arguments.
//
// See also examples #12 and #14 for the client side.

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[3])) {
    echo 'No arguments given! Run with <listen> <proxy1> <proxyN>...' . PHP_EOL;
    echo 'You can add 2..n proxies in the pool' . PHP_EOL;
    exit(1);
}

$listen = $argv[1];
$pool = array_slice($argv, 2);

// Alternatively, you can also hard-code these values like this:
//$listen = '127.0.0.1:9050';
//$pool = array('127.0.0.1:9051', '127.0.0.1:9052', '127.0.0.1:9053');

// forward to socks server listening on 127.0.0.1:9051-9053
// this connector randomly picks one of the the attached connectors from the pool
$connector = new React\Socket\Connector();
$proxies = array();
foreach ($pool as $proxy) {
    $proxies []= new Clue\React\Socks\Client($proxy, $connector);
}
$connector = new ConnectionManager\Extra\Multiple\ConnectionManagerRandom($proxies);

// start the SOCKS proxy server using our connection manager for outgoing connections
$server = new Clue\React\Socks\Server(null, $connector);

// listen on 127.0.0.1:1080 or first argument
$socket = new React\Socket\Server($listen);
$server->listen($socket);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;
echo 'Randomly picking from: ' . implode(', ', $pool) . PHP_EOL;
