<?php

/**
 * 
 * @author me
 * @link http://en.wikipedia.org/wiki/SOCKS#SOCKS_4a
 * @link http://ss5.sourceforge.net/socks4A.protocol.txt
 */
class Socks4a extends Socks4{
    protected function transceive($target,$method){
        $split = $this->splitAddress($target);
        
        $ip = ip2long($split['host']);                                          // do not resolve hostname. only try to convert to IP
        $this->stream->push(pack('C2nNC',0x04,$method,$split['port'],$ip === false ? 1 : $ip,0x00)); // send IP or (0.0.0.1) if invalid
        if($ip === false){                                                      // host is not a valid IP => send along hostname
            $this->stream->push($split['host'].pack('C',0x00));
        }
        $this->stream->send();
        
        $response = $this->stream->receive(8);
        $data = unpack('Cnull/Cstatus/nport/Nip',$response);
        
        if($data['null'] !== 0x00 || $data['status'] !== 0x5a){
            throw new Exception();
        }
        
        return $this->stream;
    }
}
