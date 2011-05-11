<?php

/**
 * class implementing the SOCKS 5 protocol
 * 
 * The SOCKS 5 protocol is an extension of the SOCKS 4 protocol that is defined in RFC 1928. It offers more choices of authentication, adds support for IPv6 and UDP that can be used for DNS lookups. The initial handshake now consists of the following:
 */
class Socks5 extends Socks4{
    protected $auth = NULL;
    
    /**
     * set login data for username/password authentication method (RFC1929) 
     * 
     * @param string $username
     * @param string $password
     * @return Socks5 $this (chainable)
     * @link http://tools.ietf.org/html/rfc1929
     */
    public function setAuth($username,$password){
        $this->auth = pack('C2',0x01,strlen($username)).$username.pack('C',strlen($password)).$password;
        return $this;
    }
    
    
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
        
        $packet = pack('C',0x05);
        if($this->auth === NULL){
            $packet .= pack('C2',0x01,0x00);                                    // one method, no authentication
        }else{
            $packet .= pack('C3',0x02,0x02,0x00);                               // two methods, username/password and no authentication
        }
        
        $this->stream->writeEnsure($packet);
        $response = $this->stream->readEnsure(2);
        
        $data = unpack('Cversion/Cmethod',$response);
        if($data['version'] !== 0x05){
            throw new Exception('Version/Protocol mismatch');
        }
        if($data['method'] === 0x02 && $this->auth !== NULL){                   // username/password authentication
            $this->stream->writeEnsure($this->auth);
            $response = $this->stream->readEnsure(2);
            $data = unpack('Cversion/Cstatus',$response);
            
            if($data['version'] !== 0x01 || $data['status'] !== 0x00){
                throw new Exception('Username/Password authentication failed');
            }
        }else if($data['method'] !== 0x00){                                     // any other method than "no authentication"
            throw new Exception('Unacceptable authentication method requested');
        }
        
        $ip = ip2long($split['host']);                                          // do not resolve hostname. only try to convert to IP
        
        $packet = pack('C3',0x05,$method,0x00);
        if($ip === false){                                                      // not an IP, send as hostname
            $packet .= pack('C2',0x03,strlen($split['host'])).$split['host'];
        }else{                                                                  // send as IPv4
            $packet .= pack('CN',0x01,$ip);
        }                                                                       // TODO: support IPv6 target address
        $packet .= pack('n',$split['port']);
        
        $this->stream->writeEnsure($packet);
        $response = $this->stream->readEnsure(4);
        $data = unpack('Cversion/Cstatus/Cnull/Ctype',$response);
        
        if($data['version'] !== 0x05 || $data['status'] !== 0x00 || $data['null'] !== 0x00){
            throw new Exception('Protocol error');
        }
        if($data['type'] === 0x01){                                             // ipv4 address
            $this->stream->readEnsure(6);                                       // skip IP and port
        }else if($data['type'] === 0x03){                                       // domain name
            $response = $this->stream->readEnsure(1);                           // read domain name length
            $data = unpack('Clength',$response);
            $this->stream->readEnsure($data['length']+2);                       // skip domain name and port
        }else if($data['type'] === 0x04){                                       // IPv6 address
            $this->stream->readEnsure(18);                                      // skip IP and port
        }else{
            throw new Exception('Protocol error: Invalid address type');
        }
        
        return $this->stream;
    }
}
