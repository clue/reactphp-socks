<?php

use Clue\React\Socks\Client;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

$proxy = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';

$loop = React\EventLoop\Factory::create();

$client = new Client('sockss://' . $proxy, new Connector($loop, array('tls' => array(
    'verify_peer' => false,
    'verify_peer_name' => false
))));
$connector = new Connector($loop, array(
    'tcp' => $client,
    'timeout' => 3.0,
    'dns' => false
));

echo 'Demo SOCKS over TLS client connecting to secure SOCKS server ' . $proxy . PHP_EOL;

$connector->connect('tcp://www.google.com:80')->then(function (ConnectionInterface $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
