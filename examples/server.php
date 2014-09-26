<?php

use Clue\React\Socks\Server;
include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$socket = new React\Socket\Server($loop);
$socket->listen('9050','localhost');

$server = new Server($loop, $socket);
$server->setAuthArray(array(
    'tom' => 'god',
    'user' => 'p@ssw0rd'
));

echo 'SOCKS server listening on localhost:9050' . PHP_EOL;

$loop->run();
