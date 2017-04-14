<?php

use Clue\React\Socks\Client;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

include_once __DIR__.'/../vendor/autoload.php';

$port = isset($argv[1]) ? $argv[1] : 9050;

$loop = React\EventLoop\Factory::create();

// set up DNS server to use (Google's public DNS)
$client = new Client('127.0.0.1:' . $port, new TcpConnector($loop));
$connector = new Connector($loop, array(
    'tcp' => $client,
    'timeout' => 3.0,
    'dns' => '8.8.8.8'
));

echo 'Demo SOCKS client connecting to SOCKS server 127.0.0.1:' . $port . PHP_EOL;
echo 'Not already running a SOCKS server? Try this: ssh -D ' . $port . ' localhost' . PHP_EOL;

$connector->connect('tls://www.google.com:443')->then(function (ConnectionInterface $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
