<?php

include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new Socks\Factory($loop, $dns);
$server = $factory->createServer();
$server->listen('9050','localhost');
$server->setAuthArray(array(
    'tom' => 'god',
    'user' => 'p@ssw0rd'
));

echo 'SOCKS server listening on localhost:9050' . PHP_EOL;

$loop->run();
