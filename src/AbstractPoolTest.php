<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Parallel;

use Closure;
use Money\Money;
use parallel\Future\Error\Killed;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use WyriHaximus\React\Parallel\ClosedException;
use function React\Promise\all;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Parallel\PoolInterface;
use function Safe\sleep;

abstract class AbstractPoolTest extends AsyncTestCase
{
    public function provideCallablesAndTheirExpectedResults(): iterable
    {
        yield 'math' => [
            function (int ...$ints) {
                $result = 0;

                foreach ($ints as $int) {
                    $result += $int;
                }

                return $result;
            },
            [
                1,
                2,
                3,
            ],
            6,
        ];

        yield 'money-same-currency' => [
            function (Money $euro, Money $usd) {
                return $euro->isSameCurrency($usd);
            },
            [
                Money::EUR(512),
                Money::USD(512),
            ],
            false,
        ];

        yield 'money-add' => [
            function (Money ...$euros) {
                $total = Money::EUR(0);

                foreach ($euros as $euro) {
                    $total = $total->add($euro);
                }

                return (int)$total->getAmount();
            },
            [
                Money::EUR(512),
                Money::EUR(512),
            ],
            1024,
        ];

        yield 'sleep' => [
            function () {
                sleep(1);

                return true;
            },
            [],
            true,
        ];
    }

    /**
     * @dataProvider provideCallablesAndTheirExpectedResults
     * @param mixed[] $args
     * @param mixed $expectedResult
     */
    public function testFullRunThrough(Closure $callable, array $args, $expectedResult): void
    {
        $loop = Factory::create();
        $pool = $this->createPool($loop);

        /** @psalm-suppress UndefinedInterfaceMethod */
        $promise = $pool->run($callable, $args)->always(function () use ($pool): void {
            $pool->close();
        });
        $result = $this->await($promise, $loop);

        self::assertSame($expectedResult, $result);
    }

    /**
     * @dataProvider provideCallablesAndTheirExpectedResults
     * @param mixed[] $args
     * @param mixed $expectedResult
     */
    public function testFullRunThroughMultipleConsecutiveCalls(Closure $callable, array $args, $expectedResult): void
    {
        $loop = Factory::create();
        $pool = $this->createPool($loop);

        $promises = [];
        foreach (\range(0, 8) as $i) {
            $promises[$i] = $pool->run($callable, $args);
        }
        $results = $this->await(all($promises)->always(function () use ($pool): void {
            $pool->close();
        }), $loop);

        foreach ($results as $result) {
            self::assertSame($expectedResult, $result);
        }
    }

    /**
     * @dataProvider provideCallablesAndTheirExpectedResults
     * @param mixed[] $args
     * @param mixed $expectedResult
     */
    public function testClosedPoolShouldNotRunClosures(Closure $callable, array $args, $expectedResult): void
    {
        self::expectException(ClosedException::class);

        $loop = Factory::create();
        $pool = $this->createPool($loop);
        self::assertTrue($pool->close());

        $this->await($pool->run($callable, $args), $loop);
    }

    public function testKillingPoolWhileRunningClosuresShouldNotYieldValidResult(): void
    {
        self::expectException(Killed::class);

        $loop = Factory::create();
        $pool = $this->createPool($loop);

        $loop->futureTick(function () use ($pool) {
            $pool->kill();
        });

        try {
            $this->await($pool->run(function () {
                sleep(1);

                return 123;
            }), $loop);
        } catch (\UnexpectedValueException $unexpectedValueException) {
            /** @psalm-suppress InvalidThrow */
            throw $unexpectedValueException->getPrevious();
        }
    }

    abstract protected function createPool(LoopInterface $loop): PoolInterface;
}
