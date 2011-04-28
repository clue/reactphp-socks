<?php

class Stream{
    protected $fp;
    
    public function __construct($stream){
        $this->fp = $stream;
    }
    
    public function read($len){
        $ret = fread($this->fp,$length);
        if($ret === false){
            throw new Stream_Exception('Unable to read from stream');
        }
        return $ret;
    }
    
    public function write($string){
        $ret = fwrite($this->fp,$string);
        if($ret === false){
            throw new Stream_Exception('Unable to write to stream');
        }
        return $ret;
    }
    
    public function readEnsure($len){
        $ret = '';
        while(strlen($ret) < $len){
            $ret .= $this->read($len-strlen($ret));
        }
        return $ret;
    }
    
    public function writeEnsure($string){
        $len = strlen($string);
        while($string !== ''){
            $string = substr($string,$this->write($string));
        }
        return $len;
    }
    
    public function close(){
        fclose($this->fp);
        return $this;
    }
}
