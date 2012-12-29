<?php

include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new Socks\Factory($loop, $dns);

$connectionManager = new ConnectionManager\ConnectionManager($loop, $dns);

$socket = new React\Socket\Server($loop);

$server = new Socks\Server($socket, $loop, $connectionManager);

$server = $factory->createServer();
$server->setAuthArray(array(
    'tom' => 'god',
    'user' => 'p@ssw0rd'
));

$socket->listen('9050','localhost');

echo 'SOCKS server listening on localhost:9050' . PHP_EOL;

$loop->run();
