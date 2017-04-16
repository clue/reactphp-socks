<?php

// A SOCKS server that randomly forwards (proxy chaining) to a pool of SOCKS servers

use React\EventLoop\Factory as LoopFactory;
use ConnectionManager\Extra\Multiple\ConnectionManagerRandom;
use React\Socket\Server as Socket;
use Clue\React\Socks\Server;
use Clue\React\Socks\Client;
use React\Socket\TcpConnector;

require __DIR__ . '/../vendor/autoload.php';

$port = isset($argv[1]) ? $argv[1] : 9050;

$loop = LoopFactory::create();

// forward to socks server listening on 127.0.0.1:9051-9053
$tcp = new TcpConnector($loop);
$client1 = new Client('127.0.0.1:9051', $tcp);
$client2 = new Client('127.0.0.1:9052', $tcp);
$client3 = new Client('127.0.0.1:9053', $tcp);

// this connector randomly picks one of the the attached connectors from the pool
$connector = new ConnectionManagerRandom(array(
    $client1,
    $client2,
    $client3
));

// start the server socket listening on localhost:$port for incoming socks connections
$socket = new Socket($port, $loop);

// start the actual socks server on the given server socket and using our connection manager for outgoing connections
$server = new Server($loop, $socket, $connector);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;

$loop->run();
