<?php

use React\EventLoop\Factory as Loopfactory;
use ConnectionManager\Extra\Multiple\ConnectionManagerSelective;
use React\Socket\Server as Socket;
use Clue\React\Socks\Server;
use Clue\React\Socks\Client;
use ConnectionManager\Extra\ConnectionManagerReject;
use React\SocketClient\Connector;
use React\Dns\Resolver\Factory;

require __DIR__ . '/vendor/autoload.php';

$port = isset($argv[1]) ? $argv[1] : 9050;

$loop = LoopFactory::create();

// create a connector that rejects the connection
$reject = new ConnectionManagerReject();

// create an actual connector that establishes real connections (uses Google's public DNS)
$factory = new Factory();
$resolver = $factory->createCached('8.8.8.8', $loop);
$permit = new Connector($loop, $resolver);

// this connector selectively picks one of the the attached connectors depending on the target address
$connector = new ConnectionManagerSelective();

// default connector => permit everything
$connector->addConnectionManagerFor($permit, '*', '*', 100);

// reject youtube.com
$connector->addConnectionManagerFor($reject, '*.youtube.com');

// reject unencrypted HTTP for google.com
$connector->addConnectionManagerFor($reject, 'www.google.com', 80);

// start the server socket listening on localhost:$port for incoming socks connections
$socket = new Socket($loop);
$socket->listen($port, 'localhost');

// start the actual socks server on the given server socket and using our connection manager for outgoing connections
$server = new Server($loop, $socket, $connector);

echo 'SOCKS server listening on localhost:' . $port . PHP_EOL;

$loop->run();
