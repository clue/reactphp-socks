<?php

use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use React\HttpClient\ConnectionManagerInterface;
use \Exception;

class Client implements ConnectionManagerInterface
{
    /**
     * 
     * @var ConnectionManagerInterface
     */
    private $connectionManager;
    
    /**
     * 
     * @var Resolver
     */
    private $resolver;
    
    private $socksHost;
    
    private $socksPort;
    
    /**
     * @var LoopInterface
     */
    protected $loop;
    
    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager, Resolver $resolver, $socksHost, $socksPort)
    {
        $this->loop = $loop;
        $this->connectionManager = $connectionManager;
        $this->socksHost = $socksHost;
        $this->socksPort = $socksPort;
    }
    
    public function getConnection($callback, $host, $port)
    {
        $this->connectionManager->getConnection(function($stream,$error=null) use ($callback, $host, $port){
            if ($error === null) {
                call_user_func($callback, null, new Exception('Unable to connect to socks server',0,$error));
            }
            $this->handleConnectedSocks($callback, $stream, $host, $port);
        }, $this->socksHost, $this->socksPort);
    }
    
    protected function handleConnectedSocks($callback, Stream $stream, $host, $port)
    {
        $ip = ip2long($host);                                                   // do not resolve hostname. only try to convert to IP
        $data = pack('C2nNC',0x04,$method,$port,$ip === false ? 1 : $ip,0x00); // send IP or (0.0.0.1) if invalid
        if($ip === false){                                                      // host is not a valid IP => send along hostname
            $data .= $hostname.pack('C',0x00);
        }
        $stream->write($data);
        
        $result = function($error=null) use ($callback,$fnEndPremature,$stream) {
            $stream->removeListener('end', $fnEndPremature);
            call_user_func($callback, $stream, $error);
        };
        
        $fnEndPremature = function(Stream $stream) use ($result){
            $result(new Exception('Premature end while establishing socks session'));
        };
        $stream->on('end',$fnEndPremature);
        
        
        $this->readLength(function($response, Stream $stream) use ($result){
            $data = unpack('Cnull/Cstatus/nport/Nip',$response);
            
            if($data['null'] !== 0x00 || $data['status'] !== 0x5a){
                $result(new Exception('Invalid SOCKS response'));
            }
            
            $stream->bufferSize = 1024;
            $result();
        },$stream,8);
    }
    
    protected function readLength($callback, Stream $stream, $bytes)
    {
        $stream->bufferSize = $bytes;
        
        $buffer = '';
        $stream->on('data', function($data, Stream $stream) use (&$buffer, &$bytes, $callback){
            $bytes -= strlen($data);
            $buffer .= $data;
            
            if ($bytes === 0) {
                call_user_func($callback, $buffer, $stream);
            } else {
                $stream->bufferSize = $bytes;
            }
        });
    }
}
