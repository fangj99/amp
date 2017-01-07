<?php

namespace Amp\Test;

use Amp;
use Amp\{ Producer, Listener, Pause, Emitter };
use AsyncInterop\Loop;

class ListenerTest extends \PHPUnit_Framework_TestCase {
    const TIMEOUT = 10;

    public function testSingleEmittingStream() {
        Loop::execute(Amp\wrap(function () {
            $value = 1;
            $stream = new Producer(function (callable $emit) use ($value) {
                yield $emit($value);
                return $value;
            });

            $listener = new Listener($stream);

            while (yield $listener->advance()) {
                $this->assertSame($listener->getCurrent(), $value);
            }

            $this->assertSame($listener->getResult(), $value);
        }));
    }

    /**
     * @depends testSingleEmittingStream
     */
    public function testFastEmittingStream() {
        Loop::execute(Amp\wrap(function () {
            $count = 10;

            $emitter = new Emitter;

            $listener = new Listener($emitter->stream());

            for ($i = 0; $i < $count; ++$i) {
                $promises[] = $emitter->emit($i);
            }

            $emitter->resolve($i);

            for ($i = 0; yield $listener->advance(); ++$i) {
                $this->assertSame($listener->getCurrent(), $i);
            }

            $this->assertSame($count, $i);
            $this->assertSame($listener->getResult(), $i);
        }));
    }

    /**
     * @depends testSingleEmittingStream
     */
    public function testSlowEmittingStream() {
        Loop::execute(Amp\wrap(function () {
            $count = 10;
            $stream = new Producer(function (callable $emit) use ($count) {
                for ($i = 0; $i < $count; ++$i) {
                    yield new Pause(self::TIMEOUT);
                    yield $emit($i);
                }
                return $i;
            });

            $listener = new Listener($stream);

            for ($i = 0; yield $listener->advance(); ++$i) {
                $this->assertSame($listener->getCurrent(), $i);
            }

            $this->assertSame($count, $i);
            $this->assertSame($listener->getResult(), $i);
        }));
    }

    /**
     * @depends testFastEmittingStream
     */
    public function testDrain() {
        Loop::execute(Amp\wrap(function () {
            $count = 10;

            $emitter = new Emitter;

            $listener = new Listener($emitter->stream());

            for ($i = 0; $i < $count; ++$i) {
                $promises[] = $emitter->emit($i);
            }

            $emitter->resolve($i);

            $values = $listener->drain();

            $this->assertSame(\range(0, $count - 1), $values);
        }));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage The stream has not resolved
     */
    public function testDrainBeforeResolution() {
        $emitter = new Emitter;

        $listener = new Listener($emitter->stream());

        $listener->drain();
    }

    public function testFailingStream() {
        Loop::execute(Amp\wrap(function () {
            $exception = new \Exception;

            $emitter = new Emitter;

            $listener = new Listener($emitter->stream());

            $emitter->fail($exception);

            try {
                while (yield $listener->advance());
                $this->fail("Listener::advance() should throw stream failure reason");
            } catch (\Exception $reason) {
                $this->assertSame($exception, $reason);
            }

            try {
                $result = $listener->getResult();
                $this->fail("Listener::getResult() should throw stream failure reason");
            } catch (\Exception $reason) {
                $this->assertSame($exception, $reason);
            }
        }));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Promise returned from advance() must resolve before calling this method
     */
    public function testGetCurrentBeforeAdvanceResolves() {
        $emitter = new Emitter;

        $listener = new Listener($emitter->stream());

        $promise = $listener->advance();

        $listener->getCurrent();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage The stream has resolved
     */
    public function testGetCurrentAfterResolution() {
        $emitter = new Emitter;

        $listener = new Listener($emitter->stream());

        $emitter->resolve();

        $listener->getCurrent();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage The stream has not resolved
     */
    public function testGetResultBeforeResolution() {
        Loop::execute(Amp\wrap(function () {
            $emitter = new Emitter;

            $listener = new Listener($emitter->stream());

            $listener->getResult();
        }));
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage The prior promise returned must resolve before invoking this method again
     */
    public function testConsecutiveAdvanceCalls() {
        $emitter = new Emitter;
        $listener = new Listener($emitter->stream());
        $listener->advance();
        $listener->advance();
    }
}