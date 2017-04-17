<?php

// A SOCKS server that forwards (proxy chaining) to a another SOCKS server

use Clue\React\Socks\Client;
use Clue\React\Socks\Server;
use React\Socket\Server as Socket;
use React\Socket\TcpConnector;

include_once __DIR__.'/../vendor/autoload.php';

$myPort = isset($argv[1]) ? $argv[1] : 9051;
$otherPort = isset($argv[2]) ? $argv[2] : 9050;

$loop = React\EventLoop\Factory::create();

// set next SOCKS server localhost:$otherPort as target
$connector = new TcpConnector($loop);
$target = new Client('user:p%40ssw0rd@127.0.0.1:' . $otherPort, $connector);

// listen on localhost:$myPort
$socket = new Socket($myPort, $loop);

// start a new server which forwards all connections to the other SOCKS server
$server = new Server($loop, $socket, $target);

echo 'SOCKS server listening on ' . $socket->getAddress() . ' (which forwards everything to target SOCKS server 127.0.0.1:' . $otherPort . ')' . PHP_EOL;
echo 'Not already running the target SOCKS server? Try this: php 02-server-with-password.php ' . $otherPort . PHP_EOL;

$loop->run();
