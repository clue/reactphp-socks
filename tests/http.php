<?php

require_once('Socks4.class.php');
require_once('Socks4a.class.php');
require_once('Socks5.class.php');

class Socks4Test extends PHPUnit_Framework_TestCase{
    
    public function testSocks4(){
        $socks = new Socks4(9050);
        $this->http($socks);
    }
    
    public function testSocks4a(){
        $socks = new Socks4a(NULL,9050);
        $this->http($socks);
    }
    
    public function testSocks5(){
        $socks = new Socks5('localhost',9050);
        $this->http($socks);
    }
    
    protected function http($socks){
        $host = 'google.com';
        $fp = $socks->connect($host,80);
        
        fwrite($fp,"GET / HTTP/1.0\r\nHost: '.$host.'\r\n\r\n");
        
        $ret = stream_get_contents($fp);
        
        //var_dump($ret);
        
        $this->assertStringStartsWith('HTTP/1.',$ret);
        
        return $ret;
    }
}
