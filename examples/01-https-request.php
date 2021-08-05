<?php

// A simple example which uses an HTTP client to request https://example.com/ through a SOCKS proxy.
// You can use any kind of proxy, for example https://github.com/leproxy/leproxy (defaults to localhost:8080) and execute it like this:
//
// $ php leproxy.php
//
// The proxy in this example defaults to localhost:1080.
// To run the example go to the project root and run:
//
// $ php examples/01-https-request.php
//
// To run the same example with your proxy, the proxy URL can be given as an environment variable:
//
// $ socks_proxy=127.0.0.2:1080 php examples/01-https-request.php

require __DIR__ . '/../vendor/autoload.php';

$url = getenv('socks_proxy');
if ($url === false) {
    $url = 'localhost:1080';
}

$proxy = new Clue\React\Socks\Client($url);

$connector = new React\Socket\Connector(array(
    'tcp' => $proxy,
    'timeout' => 3.0,
    'dns' => false
));

$browser = new React\Http\Browser($connector);

$browser->get('https://example.com/')->then(function (Psr\Http\Message\ResponseInterface $response) {
    var_dump($response->getHeaders(), (string) $response->getBody());
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
