<?php

// A simple example which requests http://google.com/ directly (optional: Through a SOCKS proxy.)
// To run the example, go to the project root and run:
//
// $ php examples/14-optional-proxy-raw-https-protocol.php
//
// If you chose the optional route, you can use any kind of proxy, for example https://github.com/leproxy/leproxy (defaults to localhost:8080) and execute it like this:
//
// $ php leproxy.php
//
// To run the same example with your proxy, the proxy URL can be given as an environment variable:
//
// $ socks_proxy=127.0.0.2:1080 php examples/14-optional-proxy-raw-https-protocol.php
//
// This example highlights how changing from direct connection to using a proxy
// actually adds very little complexity and does not mess with your actual
// network protocol otherwise.
//
// For illustration purposes only. If you want to send HTTP requests in a real
// world project, take a look at example #01, example #02 and https://github.com/reactphp/http#client-usage.

require __DIR__ . '/../vendor/autoload.php';

$url = getenv('socks_proxy');
if ($url !== false) {
    $proxy = new Clue\React\Socks\Client($url);

    $connector = new React\Socket\Connector(null, array(
        'tcp' => $proxy,
        'timeout' => 3.0,
        'dns' => false
    ));
} else {
    $connector = new React\Socket\Connector();
}

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
