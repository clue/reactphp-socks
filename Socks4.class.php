<?php

/**
 * class implementing the SOCKS 4 protocol
 * 
 * @author Christian Lück <christian@lueck.tv>
 * @copyright Copyright (c) 2011, Christian Lück
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @package Socks
 * @version v0.1.0
 * @link https://github.com/clue/Socks
 * @link http://en.wikipedia.org/wiki/SOCKS#SOCKS_4
 * @link http://ss5.sourceforge.net/socks4.protocol.txt
 */
class Socks4{
    /**
     * address or established connection stream to socks server
     * 
     * @var string|resource
     */
    protected $server;
    
    /**
     * SOCKS server port to connect to
     * 
     * @var int|NULL
     */
    protected $serverPort;
    
    /**
     * instanciate new socks handler (does NOT initialize connection yet)
     * 
     * @param string|int|NULL|resource $socksServer hostname / port or (not recommended:) established connection stream
     * @param int|NULL                 $port        port
     */
    public function __construct($socksServer,$port=NULL){
        if($socksServer === NULL || is_int($socksServer)){
            if($port === NULL){
                $port = $socksServer;
            }
            $socksServer = '127.0.0.1';
        }
        if($port === NULL){
            $port = 1080;
        }
        
        $this->server     = $socksServer;
        $this->serverPort = $port;
    }
    
    /**
     * establish connection to given target (SOCKS CONNECT request)
     * 
     * @param string $hostname hostname to connect to
     * @param int    $port     port to connect to
     * @return resource
     * @throws Exception if connection fails
     * @uses Socks4::transceive()
     */
    public function connect($hostname,$port){
        return $this->transceive($hostname,$port,0x01);
    }
    
    /**
     * experimental: issue a SOCKS BIND request
     * 
     * due to limitations of the SOCKS protocol this can not be generically used
     * to bind to a listening port. carefully read the specs!
     * 
     * @param string $hostname hostname to connect to
     * @param int    $port     port to connect to
     * @return resource
     * @throws Exception if connection fails
     * @uses Socks4::transceive()
     */
    public function bind($hostname,$port){
        return $this->transceive($hostname,$port,0x02);
    }
    
    /**
     * communicate with socks server to establish tunnelled connection to target
     * 
     * @param string $hostname hostname to connect to
     * @param int    $port     port to connect to
     * @param int    $method   SOCKS method to use (connect/bind)
     * @return resource
     * @throws Exception if target is invalid or connection fails
     * @uses gethostbyname() to resolve hostname to IP
     */
    protected function transceive($hostname,$port,$method){
        $ip = ip2long(gethostbyname($hostname));
        if($ip === false){
            throw new Exception('Unable to resolve hostname to IP');
        }
        $stream = $this->streamConnect();
        $response = $this->streamWriteRead($stream,pack('C2nNC',0x04,$method,$port,$ip,0x00),8);
        $data = unpack('Cnull/Cstatus',substr($response,0,2));
        
        if($data['null'] !== 0x00 || $data['status'] !== 0x5a){
            throw new Exception('Invalid SOCKS response');
        }
        
        return $stream;
    }
    
    /**
     * read data string with given length from SOCKS server
     * 
     * @param resource $stream to operate on
     * @param int      $len    ensure full length will be read
     * @return string data string
     * @throws Exception if reading fails
     */
    protected function streamRead($stream,$len){
        //echo '[read '.$len.':';
        $ret = '';
        while(strlen($ret) < $len){
            $part = fread($stream,$len-strlen($ret));
            if($part === false || $part === ''){
                throw new Exception('Unable to read from stream');
            }
            $ret .= $part;
            //echo '['.$part.']';
        }
        //echo ']';
        return $ret;
    }
    
    /**
     * write given data string to SOCKS server
     * 
     * @param resource $stream to operate on
     * @param string   $string ensure full string will be written
     * @return Socks4 $this (chainable)
     * @throws Exception if writing fails
     */
    protected function streamWrite($stream,$string){
        //echo '[write '.$string.':';
        while($string !== ''){
            $l = fwrite($stream,$string);
            if($l === false || $l === 0){
                throw new Exception('Unable to write to stream');
            }
            $string = (string)substr($string,$l);
            //echo '[sent '.$l.', remaining: '.$string.']';
        }
        //echo ']';
        return $this;
    }
    
    /**
     * write given data string to SOCKS server and read $len bytes in response (shortcut function)
     * 
     * @param resource $stream to operate on
     * @param string   $string string to be send to server
     * @param int      $len    number of bytes to be read
     * @return string response data string
     * @throws Exception if reading/writing fails
     * @uses Socks4::streamWrite()
     * @uses Socks4::streamRead()
     */
    protected function streamWriteRead($stream,$string,$len){
        return $this->streamWrite($stream,$string)->streamRead($stream,$len);
    }
    
    /**
     * connect to SOCKS server and return stream resource
     * 
     * @return resource
     * @throws Exception if connection fails
     */
    protected function streamConnect(){
        $ret = NULL;
        if(is_resource($this->server)){
            $ret = $this->server;
            $this->server = NULL; // stream can not be re-used
            return $ret;
        }else if($this->server === NULL){
            throw new Exception('SOCKS was initialized with an established socket which can not be re-used for multiple connections');
        }else{
            $ret = fsockopen($this->server,$this->serverPort);                  // create a fresh connection
            if($ret === false){
                throw new Exception('Unable to connect to SOCKS server');
            }
        }
        return $ret;
    }
}
