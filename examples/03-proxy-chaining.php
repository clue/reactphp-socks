<?php

use Clue\React\Socks\Client;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[1])) {
    echo 'No arguments given! Run with <proxy1> [<proxyN>...]' . PHP_EOL;
    echo 'You can add 1..n proxies in the path' . PHP_EOL;
    exit(1);
}

$path = array_slice($argv, 1);

// Alternatively, you can also hard-code this value like this:
//$path = array('127.0.0.1:9051', '127.0.0.1:9052', '127.0.0.1:9053');

$loop = React\EventLoop\Factory::create();

// set next SOCKS server chain via p1 -> p2 -> p3 -> destination
$connector = new Connector($loop);
foreach ($path as $proxy) {
    $connector = new Client($proxy, $connector);
}

// please note how the client uses p3 (not p1!), which in turn then uses the complete chain
// this creates a TCP/IP connection to p1, which then connects to p2, then to p3, which then connects to the target
$connector = new Connector($loop, array(
    'tcp' => $connector,
    'timeout' => 3.0,
    'dns' => false
));

echo 'Demo SOCKS client connecting to SOCKS proxy server chain ' . implode(' -> ', $path) . PHP_EOL;

$connector->connect('tls://www.google.com:443')->then(function (ConnectionInterface $stream) {
    echo 'connected' . PHP_EOL;
    $stream->write("GET / HTTP/1.0\r\n\r\n");
    $stream->on('data', function ($data) {
        echo $data;
    });
}, 'printf');

$loop->run();
