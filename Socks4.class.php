<?php

/**
 * 
 * @author me
 * @link http://en.wikipedia.org/wiki/SOCKS#SOCKS_4a
 * @see socks5.lib.php
 */
class Socks4{
    protected $stream;
    
    public function __construct($socksServer){
        if(!is_resource($socksServer)){
            $split = $this->splitAddress($socksServer,'127.0.0.1',1080);
            
            $socksServer = fsockopen($split['host'],$split['port']);
            if($socksServer === false){
                throw new Exception();
            }
        }
        $this->stream = new Stream($socksServer);
    }
    
    public function connect($target){
        return $this->transceive($target,0x01);
    }
    
    public function bind($target){
        return $this->transceive($target,0x02);
    }
    
    protected function splitAdress($target,$defaultHost=NULL,$defaultPort=NULL){
        $ret = array('host'=>$defaultHost,'port'=>$defaultPort);
        if(is_int($target)){
            $ret['port'] = $target;
        }
        $parts = explode(':',$target);
        $ip = $defaultIp;
        
        if($ret['host'] === NULL || $ret['port'] === NULL){
            throw new Exception();
        }
        return $ret;
    }
    
    protected function transceive($target,$method){
        $split = $this->splitAddress($target);
        
        $ip = ip2long(gethostbyname($host));
        if($ip === false){
            throw new Exception();
        }
        $this->stream->send(pack('C2nNC', 0x04,$method,$port,$ip,0x00));
        
        $response = $this->stream->receive(8);
        $data = unpack('Cnull/Cstatus',substr($response,0,2));
        
        if($data['null'] !== 0x00 || $data['status'] !== 0x5a){
            throw new Exception();
        }
        
        return $this->stream;
    }
}