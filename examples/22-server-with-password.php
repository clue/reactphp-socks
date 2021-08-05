<?php

// A simple example which runs a SOCKS proxy server with hard-coded authentication details.
// The listen address can be given as first argument and defaults to 127.0.0.1:1080 otherwise.
//
// See also examples #12 and #14 for the client side.
//
// Note that the client examples do not pass any authentication details by default
// and as such will fail to authenticate against this example server. You can
// explicitly pass authentication details to the client examples like this:
//
// $ http_proxy=alice:password@127.0.0.1:1080 php examples/01-https-request.php

require __DIR__ . '/../vendor/autoload.php';

// start a new SOCKS proxy server
// require authentication and hence make this a SOCKS5-only server
$socks = new Clue\React\Socks\Server(null, null, array(
    'alice' => 'password',
    'bob' => 's3cret!1'
));

// listen on 127.0.0.1:1080 or first argument
$socket = new React\Socket\SocketServer(isset($argv[1]) ? $argv[1] : '127.0.0.1:1080');
$socks->listen($socket);

echo 'SOCKS5 server requiring authentication listening on ' . $socket->getAddress() . PHP_EOL;
