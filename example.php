<?php

include_once __DIR__.'/vendor/autoload.php';
include_once __DIR__.'/Factory.php';
include_once __DIR__.'/Client.php';

$loop = $loop = React\EventLoop\Factory::create(); 

$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$factory = new Factory($loop, $dns);

$client = $factory->createClient('localhost', 9050);

$client->getConnection(function($stream, $error){
    echo 'connected to target';
}, 'www.google.com', 80);


$loop->addTimer(10, function(){
    echo 'timeout?';
});

$loop->run();

echo 'done?';
