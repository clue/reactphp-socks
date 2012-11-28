<?php

use React\HttpClient\Client as HttpClient;
use React\HttpClient\Response;
use React\Stream\Stream;

include_once __DIR__.'/vendor/autoload.php';
include_once __DIR__.'/Factory.php';
include_once __DIR__.'/Client.php';
// include_once __DIR__.'/ConnectionManagerTimeoutInterface.php';
// include_once __DIR__.'/ConnectionManagerTimeout.php';
include_once __DIR__.'/ConnectionManagerFsockopen.php';

$loop = $loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new Factory($loop, $dns);

$client = $factory->createClient('127.0.0.1', 9050);

function ex(Exception $exception=null){
    if($exception !== null){
        echo 'message: '.$exception->getMessage().PHP_EOL;
        while(($exception = $exception->getPrevious())){
            echo 'previous: '.$exception->getMessage().PHP_EOL;
        }
    }
}

$client->getConnection('www.google.com', 80)->then(
    function ($stream) {
        echo 'connection OK'.PHP_EOL;
    },
    function (Exception $error) {
        echo 'connection ';
        ex($error);
    }
);

$client->getConnection('www.google.commm', 80)->then(
    null,
    function (Exception $error) {
        echo 'www.google.commm ';
        ex($error);
    }
);

$client->getConnection('www.google.com', 8080)->then(
    null,
    function (Exception $error) {
        echo 'www.google.com:8080 ';
        ex($error);
    }
);


// $factory = new React\HttpClient\Factory();
// $httpclient = $factory->create($loop, $dns);
$httpclient = $client->createHttpClient();

$request = $httpclient->request('GET','http://www.google.com/',array('user-agent'=>'none'));
$request->on('response',function(Response $response){
    echo '[response1]'.PHP_EOL;
    $response->on('data',function($data){
        echo $data;
    });
});
$request->end();

$loop->addTimer(8, function() use ($loop){
    $loop->stop();
    echo 'STOP - stopping mainloop after 5 seconds'.PHP_EOL;
});

$loop->run();
