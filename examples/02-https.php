<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;
use React\SocketClient\TcpConnector;
use React\SocketClient\SecureConnector;
use React\SocketClient\TimeoutConnector;

include_once __DIR__.'/../vendor/autoload.php';

$port = isset($argv[1]) ? $argv[1] : 9050;

$loop = React\EventLoop\Factory::create();

$client = new Client('127.0.0.1:' . $port, new TcpConnector($loop));

echo 'Demo SOCKS client connecting to SOCKS server 127.0.0.1:' . $port . PHP_EOL;
echo 'Not already running a SOCKS server? Try this: ssh -D ' . $port . ' localhost' . PHP_EOL;

$ssl = new SecureConnector($client, $loop);

// time out connection attempt in 3.0s
$ssl = new TimeoutConnector($ssl, 3.0, $loop);

$ssl->create('www.google.com', 443)->then(function (Stream $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
