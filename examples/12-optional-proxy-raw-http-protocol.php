<?php

// A simple example which requests http://google.com/ directly (optional: Through a SOCKS proxy.)
// To run the example, go to the project root and run:
//
// $ php examples/12-optional-proxy-raw-http-protocol.php
//
// If you chose the optional route, you can use any kind of proxy, for example https://github.com/leproxy/leproxy (defaults to localhost:8080) and execute it like this:
//
// $ php leproxy.php
//
// To run the same example with your proxy, the proxy URL can be given as an environment variable:
//
// $ socks_proxy=127.0.0.2:1080 php examples/12-optional-proxy-raw-http-protocol.php
//
// This example highlights how changing from direct connection to using a proxy
// actually adds very little complexity and does not mess with your actual
// network protocol otherwise.
//
// For illustration purposes only. If you want to send HTTP requests in a real
// world project, take a look at example #01, example #02 and https://github.com/reactphp/http#client-usage.

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$connector = new React\Socket\Connector($loop);

$url = getenv('socks_proxy');
if ($url !== false) {
    $client = new Clue\React\Socks\Client($url, $connector);
    $connector = new React\Socket\Connector($loop, array(
        'tcp' => $client,
        'timeout' => 3.0,
        'dns' => false
    ));
}

echo 'Demo SOCKS client connecting to SOCKS server ' . $url . PHP_EOL;

$connector->connect('tcp://www.google.com:80')->then(function (React\Socket\ConnectionInterface $connection) {
    echo 'connected' . PHP_EOL;
    $connection->write("GET / HTTP/1.0\r\n\r\n");
    $connection->on('data', function ($data) {
        echo $data;
    });
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

$loop->run();
