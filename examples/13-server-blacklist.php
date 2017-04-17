<?php

// A SOCKS server that rejects connections to some domains (blacklist / filtering)

use React\EventLoop\Factory as LoopFactory;
use ConnectionManager\Extra\Multiple\ConnectionManagerSelective;
use React\Socket\Server as Socket;
use Clue\React\Socks\Server;
use ConnectionManager\Extra\ConnectionManagerReject;
use React\Socket\Connector;

require __DIR__ . '/../vendor/autoload.php';

$port = isset($argv[1]) ? $argv[1] : 9050;

$loop = LoopFactory::create();

// create a connector that rejects the connection
$reject = new ConnectionManagerReject();

// create an actual connector that establishes real connections
$permit = new Connector($loop);

// this connector selectively picks one of the the attached connectors depending on the target address
// reject youtube.com and unencrypted HTTP for google.com
// default connctor: permit everything
$connector = new ConnectionManagerSelective(array(
    '*.youtube.com' => $reject,
    'www.google.com:80' => $reject,
    '*' => $permit
));

// start the server socket listening on localhost:$port for incoming socks connections
$socket = new Socket($port, $loop);

// start the actual socks server on the given server socket and using our connection manager for outgoing connections
$server = new Server($loop, $socket, $connector);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;

$loop->run();
