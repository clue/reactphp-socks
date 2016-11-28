<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;
use React\SocketClient\TcpConnector;
use React\SocketClient\SecureConnector;

include_once __DIR__.'/../vendor/autoload.php';

$first = isset($argv[1]) ? $argv[1] : 9050;
$second = isset($argv[2]) ? $argv[2] : $first;

$loop = React\EventLoop\Factory::create();

// https via the proxy chain  "foo -> bar -> target"
// please note how the client uses bar (not foo!), which in turn then uses foo
// this creates a TCP/IP connection to foo, which then connects to bar, which then connects to the target
$foo = new Client('127.0.0.1:' . $first, new TcpConnector($loop));
$bar = new Client('127.0.0.1:' . $second, $foo);

$ssl = new SecureConnector($bar, $loop);

echo 'Demo SOCKS client connecting to SOCKS proxy server chain 127.0.0.1:' . $first . ' and 127.0.0.1:' . $second . PHP_EOL;
echo 'Not already running a SOCKS server? Try this: ssh -D ' . $first . ' localhost' . PHP_EOL;

$ssl->create('www.google.com', 443)->then(function (Stream $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
