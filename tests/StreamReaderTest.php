<?php

use Clue\React\Socks\StreamReader;

class StreamReaderTest extends TestCase
{
    private $reader;

    public function setUp()
    {
        $this->reader = new StreamReader();
    }

    public function testA()
    {
        $that = $this;

        $this->reader->readByte()->then($this->expectCallableOnce(ord('h')));
        $this->reader->readByteAssert(ord('e'))->then($this->expectCallableOnce(ord('e')));
        $this->reader->readLength(4)->then($this->expectCallableOnce('llo '));
        $this->reader->readBinary(array('w'=>'C', 'o' => 'C'))->then($this->expectCallableOnce(array('w' => ord('w'), 'o' => ord('o'))));

        $this->reader->write('hello world');

        $this->assertEquals('rld', $this->reader->getBuffer());
    }
}
