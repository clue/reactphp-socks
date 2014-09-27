<?php

use Clue\React\Socks\Client;
use Clue\React\Socks\Server;
use React\Socket\Server as Socket;

include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// set next SOCKS server localhost:9050 as target
$target = new Client($loop, '127.0.0.1',9050);
$target->setAuth('user','p@ssw0rd');

// listen on localhost:9051
$socket = new Socket($loop);
$socket->listen(9051, 'localhost');

// start a new server which forwards all connections to the other SOCKS server
$server = new Server($loop, $socket, $target->createConnector());

echo 'SOCKS server listening on localhost:9051 (which forwards everything to SOCKS server 127.0.0.1:9050)' . PHP_EOL;

$loop->run();
