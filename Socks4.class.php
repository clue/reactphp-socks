<?php

/**
 * class implementing the SOCKS 4 protocol
 * 
 * @author Christian Lück <christian@lueck.tv>
 * @copyright Copyright (c) 2011, Christian Lück
 * @license http://www.opensource.org/licenses/isc-license ISC License
 * @package Socks
 * @version v0.0.1
 * @link http://en.wikipedia.org/wiki/SOCKS#SOCKS_4
 * @link http://ss5.sourceforge.net/socks4.protocol.txt
 * @see socks5.lib.php
 */
class Socks4{
    /**
     * connection stream to socks server
     * 
     * @var Stream
     */
    protected $stream;
    
    /**
     * initialize connection to given socks server
     * 
     * @param string|int|resource $socksServer hostname and/or port or established connection stream
     * @throws Exception if connection to socks server fails
     */
    public function __construct($socksServer){
        if(!is_resource($socksServer)){
            $split = $this->splitAddress($socksServer,'127.0.0.1',1080);
            
            $socksServer = fsockopen($split['host'],$split['port']);
            if($socksServer === false){
                throw new Exception('Unable to connect to SOCKS server');
            }
        }
        $this->stream = $socksServer;
    }
    
    /**
     * establish connection to given target (SOCKS CONNECT request)
     * 
     * @param string $target hostname:port to connect to
     * @return resource
     * @throws Exception if target is invalid or connection fails
     * @uses Socks4::transceive()
     */
    public function connect($target){
        return $this->transceive($target,0x01);
    }
    
    /**
     * experimental: issue a SOCKS BIND request
     * 
     * due to limitations of the SOCKS protocol this can not be generically used
     * to bind to a listening port. carefully read the specs!
     * 
     * @param string $target hostname:port to connect to
     * @return resource
     * @throws Exception if target is invalid or connection fails
     * @uses Socks4::transceive()
     */
    public function bind($target){
        return $this->transceive($target,0x02);
    }
    
    /**
     * split the given target address into host:ip parts
     * 
     * @param string|int  $target      target in the form of 'hostname','hostname:port' or just 'port'
     * @param string|NULL $defaultHost default host to assume if only a port is given
     * @param int|NULL    $defaultPort default port to assume if only a hostname is given
     * @return array {host,port}
     * @throws Exception if either host or IP remains unknown (i.e. no value and no default value given)
     */
    protected function splitAdress($target,$defaultHost=NULL,$defaultPort=NULL){
        $ret = array('host'=>$defaultHost,'port'=>$defaultPort);
        if(is_int($target)){
            $ret['port'] = $target;
        }else{
            $parts = explode(':',$target);
            $ret['host'] = $parts[0];
            if(isset($parts[1])){
                $ret['port'] = (int)$parts[1];
            }
        }
        if($ret['host'] === NULL || $ret['port'] === NULL){
            throw new Exception('Unable to split address');
        }
        return $ret;
    }
    
    /**
     * communicate with socks server to establish tunnelled connection to target
     * 
     * @param string $target hostname:port to connect to
     * @param int    $method SOCKS method to use (connect/bind)
     * @return resource
     * @throws Exception if target is invalid or connection fails
     * @uses Socks4::splitAddress()
     * @uses gethostbyname() to resolve hostname to IP
     */
    protected function transceive($target,$method){
        $split = $this->splitAddress($target);
        
        $ip = ip2long(gethostbyname($split['host']));
        if($ip === false){
            throw new Exception('Unable to resolve hostname to IP');
        }
        $response = $this->streamWrite(pack('C2nNC',0x04,$method,$split['port'],$ip,0x00))->streamRead(8);
        $data = unpack('Cnull/Cstatus',substr($response,0,2));
        
        if($data['null'] !== 0x00 || $data['status'] !== 0x5a){
            $this->streamClose();
            throw new Exception('Invalid SOCKS response');
        }
        
        return $this->stream;
    }
    
    /**
     * read data string with given length from SOCKS server
     * 
     * @param int $len ensure full length will be read
     * @return string data string
     * @throws Exception if reading fails
     */
    protected function streamRead($len){
        $ret = '';
        while(strlen($ret) < $len){
            $part = fread($this->stream,$len-strlen($ret));
            if($part === false){
                throw new Exception('Unable to read from stream');
            }
        }
        return $ret;
    }
    
    /**
     * write given data string to SOCKS server
     * 
     * @param string $string ensure full string will be written
     * @return Socks4 $this (chainable)
     * @throws Exception if writing fails
     */
    protected function streamWrite($string){
        while($string !== ''){
            $l = fwrite($this->stream,$string);
            if($l === false){
                throw new Exception('Unable to write to stream');
            }
            $string = substr($string,$l);
        }
        return $this;
    }
    
    /**
     * close stream to SOCKS server
     * 
     * @return Socks4 $this (chainable)
     */
    protected function streamClose(){
        fclose($this->stream);
        $this->stream = NULL;
        return $this;
    }
}
