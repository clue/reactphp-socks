<?php

namespace Clue\Tests\React\Socks;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();

        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($arg)
    {
        $mock = $this->createCallableMock();

        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($arg);

        return $mock;
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWithException($class, $message, $code)
    {
        return $this->expectCallableOnceWith($this->logicalAnd(
            $this->isInstanceOf($class),
            $this->callback(function (\Exception $e) use ($message, $code) {
                return strpos($e->getMessage(), $message) !== false && $e->getCode() === $code;
            })
        ));
    }

    /**
     * @link https://github.com/reactphp/react/blob/master/tests/React/Tests/Socket/TestCase.php (taken from reactphp/react)
     */
    protected function createCallableMock()
    {
        return $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
    }

    protected function expectPromiseResolve($promise)
    {
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $that = $this;
        $promise->then(null, function($error) use ($that) {
            $that->assertNull($error);
            $that->fail('promise rejected');
        });
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        return $promise;
    }

    protected function expectPromiseReject($promise)
    {
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $that = $this;
        $promise->then(function($value) use ($that) {
            $that->assertNull($value);
            $that->fail('promise resolved');
        });

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        return $promise;
    }

    public function setExpectedException($exception, $message = '', $code = 0)
    {
        if (method_exists($this, 'expectException')) {
            $this->expectException($exception);
            if ($message !== '') {
                $this->expectExceptionMessage($message);
            }
            $this->expectExceptionCode($code);
        } else {
            parent::setExpectedException($exception, $message, $code);
        }
    }

}
