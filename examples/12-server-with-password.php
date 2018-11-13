<?php

use Clue\React\Socks\Server;
use React\Socket\Server as Socket;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// start a new SOCKS proxy server
// require authentication and hence make this a SOCKS5-only server
$server = new Server($loop);
$server->setAuthArray(array(
    'tom' => 'god',
    'user' => 'p@ssw0rd'
));

// listen on 127.0.0.1:1080 or first argument
$socket = new Socket(isset($argv[1]) ? $argv[1] : '127.0.0.1:1080', $loop);
$server->listen($socket);

echo 'SOCKS5 server requiring authentication listening on ' . $socket->getAddress() . PHP_EOL;

$loop->run();
