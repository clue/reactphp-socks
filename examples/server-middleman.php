<?php

include_once __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new Socks\Factory($loop, $dns);

// set next SOCKS server as target
$target = $factory->createClient('127.0.0.1',9050);
$target->setAuth('user','p@ssw0rd');

// start a new server which forwards all connections to another SOCKS server
$server = new Socks\Server($loop, $target);

$server->listen('9051','localhost');

$loop->run();
