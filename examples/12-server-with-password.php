<?php

use Clue\React\Socks\Server;
use React\Socket\Server as Socket;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// listen on 127.0.0.1:1080 or first argument
$listen = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';
$socket = new Socket($listen, $loop);

// start a new server listening for incoming connection on the given socket
// require authentication and hence make this a SOCKS5-only server
$server = new Server($loop, $socket);
$server->setAuthArray(array(
    'tom' => 'god',
    'user' => 'p@ssw0rd'
));

echo 'SOCKS5 server requiring authentication listening on ' . $socket->getAddress() . PHP_EOL;

$loop->run();
