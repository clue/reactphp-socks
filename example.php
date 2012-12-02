<?php

use Socks\SecureConnectionManager;

use React\Promise\PromiseInterface;

use React\HttpClient\Client as HttpClient;
use React\HttpClient\Response;
use React\Stream\Stream;

include_once __DIR__.'/vendor/autoload.php';

$loop = $loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new Socks\Factory($loop, $dns);

$client = $factory->createClient('127.0.0.1', 9050);
$client->setTimeout(3.0);
$client->setAuth('test','test');

function ex(Exception $exception=null)
{
    if ($exception !== null) {
        echo 'message: ' . $exception->getMessage() . PHP_EOL;
        while (($exception = $exception->getPrevious())) {
            echo 'previous: ' . $exception->getMessage() . PHP_EOL;
        }
    }
}

function assertFail(PromiseInterface $promise, $name='end')
{
    return $promise->then(
        function (Stream $stream) use ($name) {
            echo 'FAIL: connection to '.$name.' OK' . PHP_EOL;
            $stream->close();
        },
        function (Exception $error) use ($name) {

            echo 'EXPECTED: connection to '.$name.' failed: ';
            ex($error);
        }
    );
}

function assertOkay(PromiseInterface $promise, $name='end')
{
    return $promise->then(
        function ($stream) use ($name) {
            echo 'EXPECTED: connection to '.$name.' OK' . PHP_EOL;
            $stream->close();
        },
        function (Exception $error) use ($name) {
            echo 'FAIL: connection to '.$name.' failed: ';
            ex($error);
        }
    );
}

assertOkay($client->getConnection('www.google.com', 80), 'www.google.com:80');

assertFail($client->getConnection('www.google.commm', 80), 'www.google.commm:80');

assertFail($client->getConnection('www.google.com', 8080), 'www.google.com:8080');

$ssl = $client->createSecureConnectionManager();

assertOkay($ssl->getConnection('www.google.com', 443), 'ssl://www.google.com:443');

assertFail($ssl->getConnection('www.google.com', 80), 'ssl://www.google.com:80');

assertFail($ssl->getConnection('www.google.com', 8080), 'ssl://www.google.com:8080');

// $ssl->getConnection('127.0.0.1','443')->then(function (React\Stream $stream) {
//     echo 'connected';
//     $stream->write("GET / HTTP/1.0\r\n\r\n");
//     $stream->on('data', function ($data) {
//         echo $data;
//     });
// });

// $factory = new React\HttpClient\Factory();
// $httpclient = $factory->create($loop, $dns);
$httpclient = $client->createHttpClient();

$request = $httpclient->request('GET', 'https://www.google.com/', array('user-agent'=>'none'));
$request->on('response', function (Response $response) {
    echo '[response1]' . PHP_EOL;
    //var_dump($response->getHeaders());
    $response->on('data', function ($data) {
        echo $data;
    });
});
$request->end();

$loop->addTimer(8, function() use ($loop) {
    $loop->stop();
    echo 'STOP - stopping mainloop after 5 seconds' . PHP_EOL;
});

$loop->run();
