<?php

use Clue\React\Socks\Client;
use Clue\React\Socks\Server;
include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// set next SOCKS server as target
$target = new Client($loop, '127.0.0.1',9050);
$target->setAuth('user','p@ssw0rd');

// start a new server which forwards all connections to another SOCKS server
$socket = new React\Socket\Server($loop);
$socket->listen(9051, 'localhost');

$server = new Server($loop, $socket, $target->createConnector());

echo 'SOCKS server listening on localhost:9051 (which forwards everything to SOCKS server 127.0.0.1:9050)' . PHP_EOL;

$loop->run();
