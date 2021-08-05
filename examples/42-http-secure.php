<?php

// A more advanced example which requests http://google.com/ through a secure SOCKS over TLS proxy.
// The proxy can be given as first argument and defaults to localhost:1080 otherwise.
//
// See also example #41 for the server side.
//
// For illustration purposes only. If you want to send HTTP requests in a real
// world project, take a look at example #01, example #02 and https://github.com/reactphp/http#client-usage.

require __DIR__ . '/../vendor/autoload.php';

$url = isset($argv[1]) ? $argv[1] : 'sockss://127.0.0.1:1080';

$connector = new React\Socket\Connector(array(
    'tls' => array(
        'verify_peer' => false,
        'verify_peer_name' => false
    )
));

$proxy = new Clue\React\Socks\Client($url, $connector);

$connector = new React\Socket\Connector(array(
    'tcp' => $proxy,
    'timeout' => 3.0,
    'dns' => false
));

echo 'Demo SOCKS over TLS client connecting to secure SOCKS server ' . $url . PHP_EOL;

$connector->connect('tcp://www.google.com:80')->then(function (React\Socket\ConnectionInterface $connection) {
    echo 'connected' . PHP_EOL;
    $connection->write("GET / HTTP/1.0\r\n\r\n");
    $connection->on('data', function ($data) {
        echo $data;
    });
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
