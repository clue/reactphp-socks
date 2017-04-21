<?php

// A SOCKS server that forwards (proxy chaining) to a another SOCKS server

use Clue\React\Socks\Client;
use Clue\React\Socks\Server;
use React\Socket\Server as Socket;
use React\Socket\TcpConnector;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// set next SOCKS server 127.0.0.1:1080 as target
$connector = new TcpConnector($loop);
$proxy = isset($argv[2]) ? $argv[2] : 'user:p%40ssw0rd@127.0.0.1:1080';
$target = new Client($proxy, $connector);

// listen on 127.0.0.1:1080 or first argument
$listen = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';
$socket = new Socket($listen, $loop);

// start a new server which forwards all connections to the other SOCKS server
$server = new Server($loop, $socket, $target);

echo 'SOCKS server listening on ' . $socket->getAddress() . ' (which forwards everything to target SOCKS server ' . $proxy . ')' . PHP_EOL;

$loop->run();
