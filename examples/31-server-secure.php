<?php

use Clue\React\Socks\Server;
use React\Socket\Server as Socket;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// listen on tls://127.0.0.1:1080 or first argument
$listen = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';
$socket = new Socket('tls://' . $listen, $loop, array('tls' => array(
    'local_cert' => __DIR__ . '/localhost.pem',
)));

// start a new server listening for incoming connection on the given socket
$server = new Server($loop, $socket);

echo 'SOCKS over TLS server listening on ' . str_replace('tls:', 'sockss:', $socket->getAddress()) . PHP_EOL;

$loop->run();
