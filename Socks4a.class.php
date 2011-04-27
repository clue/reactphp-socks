<?php

/**
 * class implementing the SOCKS 4a protocol
 * 
 * SOCKS 4a is a simple extension to the SOCKS 4 protocol that uses the SOCKS server to resolve the target host name instead of the client resolving it locally
 * 
 * @author Christian Lück <christian@lueck.tv>
 * @copyright Copyright (c) 2011, Christian Lück
 * @license http://www.opensource.org/licenses/isc-license ISC License
 * @package Socks
 * @version v0.0.1
 * @link http://en.wikipedia.org/wiki/SOCKS#SOCKS_4a
 * @link http://ss5.sourceforge.net/socks4A.protocol.txt
 */
class Socks4a extends Socks4{
    /**
     * communicate with socks server to establish tunnelled connection to target
     * 
     * unlike Socks4::transceive() this method does NOT use gethostname() to
     * resolve the target host name into an IP address and instead leaves it
     * up the the SOCKS server to resolve the host name.
     * 
     * @param string $target hostname:port to connect to
     * @param int    $method SOCKS method to use (connect/bind)
     * @return Stream
     * @throws Exception if target is invalid or connection fails
     * @uses Socks4::splitAddress()
     * @see Socks4::transceive() for comparison (which uses gethostname() to locally resolve the target hostname)
     */
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
