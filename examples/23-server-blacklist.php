<?php

// A more advanced example which runs a SOCKS proxy server that rejects connections
// to some domains (blacklist /filtering).
// The listen address can be given as first argument and defaults to localhost:1080 otherwise.
//
// See also examples #12 and #14 for the client side.
// Client example #12 is expected to fail because port 80 is blocked in this server example.
// Client example #14 is expected to succceed because it is not blacklisted.

require __DIR__ . '/../vendor/autoload.php';

// create a connector that rejects the connection
$reject = new ConnectionManager\Extra\ConnectionManagerReject();

// create an actual connector that establishes real connections
$permit = new React\Socket\Connector();

// this connector selectively picks one of the the attached connectors depending on the target address
// reject youtube.com and unencrypted HTTP for google.com
// default connctor: permit everything
$connector = new ConnectionManager\Extra\Multiple\ConnectionManagerSelective(array(
    '*.youtube.com' => $reject,
    'www.google.com:80' => $reject,
    '*' => $permit
));

// start a new SOCKS proxy server using our connection manager for outgoing connections
$server = new Clue\React\Socks\Server(null, $connector);

// listen on 127.0.0.1:1080 or first argument
$socket = new React\Socket\SocketServer(isset($argv[1]) ? $argv[1] : '127.0.0.1:1080');
$server->listen($socket);

echo 'SOCKS server listening on ' . $socket->getAddress() . PHP_EOL;
