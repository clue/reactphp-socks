<?php

/**
 * 
 * @author me
 * @link http://en.wikipedia.org/wiki/SOCKS#SOCKS_4
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
            throw new Exception();
        }
        return $ret;
    }
    
    protected function transceive($target,$method){
        $split = $this->splitAddress($target);
        
        $ip = ip2long(gethostbyname($split['host']));
        if($ip === false){
            throw new Exception();
        }
        $this->stream->send(pack('C2nNC',0x04,$method,$split['port'],$ip,0x00));
        
        $response = $this->stream->receive(8);
        $data = unpack('Cnull/Cstatus',substr($response,0,2));
        
        if($data['null'] !== 0x00 || $data['status'] !== 0x5a){
            throw new Exception();
        }
        
        return $this->stream;
    }
}
