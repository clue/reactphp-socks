<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;

include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$client = new Client($loop, '127.0.0.1', 9050);
$client->setTimeout(3.0);
$client->setResolveLocal(false);
// $client->setProtocolVersion(5);
// $client->setAuth('test','test');

echo 'Demo SOCKS client connecting to SOCKS server 127.0.0.1:9050' . PHP_EOL;
echo 'Not already running a SOCKS server? Try this: ssh -D 9050 localhost' . PHP_EOL;

$tcp = $client->createConnector();

$tcp->create('www.google.com', 80)->then(function (Stream $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'var_dump');

$loop->run();
