<?php

// A SOCKS server that randomly forwards (proxy chaining) to a pool of SOCKS servers

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

$socket = new Socket($listen, $loop);

// start the actual socks server on the given server socket and using our connection manager for outgoing connections
$server = new Server($loop, $socket, $connector);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;
echo 'Randomly picking from: ' . implode(', ', $pool) . PHP_EOL;

$loop->run();
