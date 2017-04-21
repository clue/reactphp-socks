<?php

use Clue\React\Socks\Client;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

require __DIR__ . '/../vendor/autoload.php';

$first = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';
$second = isset($argv[2]) ? $argv[2] : $first;

$loop = React\EventLoop\Factory::create();

// https via the proxy chain  "foo -> bar -> target"
// please note how the client uses bar (not foo!), which in turn then uses foo
// this creates a TCP/IP connection to foo, which then connects to bar, which then connects to the target
$foo = new Client($first, new TcpConnector($loop));
$bar = new Client($second, $foo);

$connector = new Connector($loop, array(
    'tcp' => $bar,
    'timeout' => 3.0,
    'dns' => false
));

echo 'Demo SOCKS client connecting to SOCKS proxy server chain ' . $first . ' and ' . $second . PHP_EOL;

$connector->connect('tls://www.google.com:443')->then(function (ConnectionInterface $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
