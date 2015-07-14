<?php

use React\EventLoop\Factory as Loopfactory;
use ConnectionManager\Extra\Multiple\ConnectionManagerRandom;
use React\Socket\Server as Socket;
use Clue\React\Socks\Server;
use Clue\React\Socks\Client;

require __DIR__ . '/vendor/autoload.php';

$port = isset($argv[1]) ? $argv[1] : 9050;

$loop = LoopFactory::create();

// this connector randomly picks one of the the attached connectors from the pool
$connector = new ConnectionManagerRandom();

// forward to socks server listening on 127.0.0.1:9051
$client = new Client('127.0.0.1:9051', $loop);
$connector->addConnectionManager($client->createConnector());

// forward to socks server listening on 127.0.0.1:9052
$client = new Client('127.0.0.1:9052', $loop);
$connector->addConnectionManager($client->createConnector());

// forward to socks server listening on 127.0.0.1:9053
$client = new Client('127.0.0.1:9053', $loop);
$connector->addConnectionManager($client->createConnector());

// start the server socket listening on localhost:$port for incoming socks connections
$socket = new Socket($loop);
$socket->listen($port, 'localhost');

// start the actual socks server on the given server socket and using our connection manager for outgoing connections
$server = new Server($loop, $socket, $connector);

echo 'SOCKS server listening on localhost:' . $port . PHP_EOL;

$loop->run();
