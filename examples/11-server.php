<?php

use Clue\React\Socks\Server;
use React\Socket\Server as Socket;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// start a new SOCKS proxy server
$server = new Server($loop);

// listen on 127.0.0.1:1080 or first argument
$socket = new Socket(isset($argv[1]) ? $argv[1] : '127.0.0.1:1080', $loop);
$server->listen($socket);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;

$loop->run();
