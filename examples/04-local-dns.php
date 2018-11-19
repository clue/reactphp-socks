<?php

// A simple example which requests https://www.google.com/ through a SOCKS proxy with local DNS resolution.
// The proxy can be given as first argument and defaults to localhost:1080 otherwise.
//
// Not already running a SOCKS proxy server? See also example #11 or try this: `ssh -D 1080 localhost`
//
// For illustration purposes only. If you want to send HTTP requests in a real
// world project, take a look at https://github.com/clue/reactphp-buzz#socks-proxy

use Clue\React\Socks\Client;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

$proxy = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';

$loop = React\EventLoop\Factory::create();

// set up DNS server to use (Google's public DNS)
$client = new Client($proxy, new Connector($loop));
$connector = new Connector($loop, array(
    'tcp' => $client,
    'timeout' => 3.0,
    'dns' => '8.8.8.8'
));

echo 'Demo SOCKS client connecting to SOCKS server ' . $proxy . PHP_EOL;

$connector->connect('tls://www.google.com:443')->then(function (ConnectionInterface $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
