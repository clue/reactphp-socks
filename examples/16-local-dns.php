<?php

// A simple example which requests https://www.google.com/ through a SOCKS proxy with local DNS resolution.
// The proxy can be given as first argument and defaults to 127.0.0.1:1080 otherwise.
//
// Not already running a SOCKS proxy server? See also example #21 or try this:
// $ ssh -D 1080 localhost
//
// For illustration purposes only. If you want to send HTTP requests in a real
// world project, take a look at example #01, example #02 and https://github.com/reactphp/http#client-usage.

require __DIR__ . '/../vendor/autoload.php';

$url = isset($argv[1]) ? $argv[1] : '127.0.0.1:1080';

$proxy = new Clue\React\Socks\Client($url);

// set up DNS server to use (Google's public DNS)
$connector = new React\Socket\Connector(array(
    'tcp' => $proxy,
    'timeout' => 3.0,
    'dns' => '8.8.8.8'
));

echo 'Demo SOCKS client connecting to SOCKS server ' . $url . PHP_EOL;

$connector->connect('tls://www.google.com:443')->then(function (React\Socket\ConnectionInterface $connection) {
    echo 'connected' . PHP_EOL;
    $connection->write("GET / HTTP/1.0\r\n\r\n");
    $connection->on('data', function ($data) {
        echo $data;
    });
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
