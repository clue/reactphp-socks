<?php

// A simple example which uses an HTTP client to request https://example.com/ (optional: Through a SOCKS proxy.)
// To run the example, go to the project root and run:
//
// $ php examples/02-optional-proxy-https-request.php
//
// If you chose the optional route, you can use any kind of proxy, for example https://github.com/leproxy/leproxy (defaults to localhost:8080) and execute it like this:
//
// $ php leproxy.php
//
// To run the same example with your proxy, the proxy URL can be given as an environment variable:
//
// $ socks_proxy=127.0.0.2:1080 php examples/02-optional-proxy-https-request.php

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$connector = null;
$url = getenv('socks_proxy');
if ($url !== false) {
    $proxy = new Clue\React\Socks\Client(
        $url,
        new React\Socket\Connector($loop)
    );
    $connector = new React\Socket\Connector($loop, array(
        'tcp' => $proxy,
        'timeout' => 3.0,
        'dns' => false
    ));
}

$browser = new React\Http\Browser($loop, $connector);

$browser->get('https://example.com/')->then(function (Psr\Http\Message\ResponseInterface $response) {
    var_dump($response->getHeaders(), (string) $response->getBody());
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

$loop->run();
