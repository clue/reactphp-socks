<?php

// A more advanced example which runs a SOCKS proxy server that randomly forwards
// to a pool of SOCKS servers (random proxy chaining).
// The listen address can be given as first argument.
// The upstream proxy servers can be given as additional arguments.
//
// See also examples #01 and #02 for the client side.

use React\EventLoop\Factory as LoopFactory;
use ConnectionManager\Extra\Multiple\ConnectionManagerRandom;
use React\Socket\Server as Socket;
use Clue\React\Socks\Server;
use Clue\React\Socks\Client;
use React\Socket\Connector;

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

$loop = LoopFactory::create();

// forward to socks server listening on 127.0.0.1:9051-9053
// this connector randomly picks one of the the attached connectors from the pool
$connector = new Connector($loop);
$clients = array();
foreach ($pool as $proxy) {
    $clients []= new Client($proxy, $connector);
}
$connector = new ConnectionManagerRandom($clients);

// start the SOCKS proxy server using our connection manager for outgoing connections
$server = new Server($loop, $socket, $connector);

// listen on 127.0.0.1:1080 or first argument
$socket = new Socket($listen, $loop);
$server->listen($socket);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;
echo 'Randomly picking from: ' . implode(', ', $pool) . PHP_EOL;

$loop->run();
