<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Parallel;

use Closure;
use Money\Money;
use parallel\Future\Error\Killed;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use UnexpectedValueException;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Parallel\ClosedException;
use WyriHaximus\React\Parallel\PoolInterface;
use function range;
use function React\Promise\all;
use function Safe\sleep;

abstract class AbstractPoolTest extends AsyncTestCase
{
    /**
     * @return iterable<mixed>
     */
    final public function provideCallablesAndTheirExpectedResults(): iterable
    {
        yield 'math' => [
            static function (int ...$ints): int {
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
            static function (Money $euro, Money $usd): bool {
                return $euro->isSameCurrency($usd);
            },
            [
                Money::EUR(512),
                Money::USD(512),
            ],
            false,
        ];

        yield 'money-add' => [
            static function (Money ...$euros): int {
                $total = Money::EUR(0);

                foreach ($euros as $euro) {
                    $total = $total->add($euro);
                }

                return (int) $total->getAmount();
            },
            [
                Money::EUR(512),
                Money::EUR(512),
            ],
            1024,
        ];

        yield 'sleep' => [
            static function (): bool {
                sleep(1);

                return true;
            },
            [],
            true,
        ];
    }

    /**
     * @param mixed[] $args
     * @param mixed   $expectedResult
     *
     * @dataProvider provideCallablesAndTheirExpectedResults
     */
    final public function testFullRunThrough(Closure $callable, array $args, $expectedResult): void
    {
        $loop = Factory::create();
        $pool = $this->createPool($loop);

        /** @psalm-suppress UndefinedInterfaceMethod */
        $promise = $pool->run($callable, $args)->always(static function () use ($pool): void {
            $pool->close();
        });
        $result  = $this->await($promise, $loop);

        self::assertSame($expectedResult, $result);
    }

    /**
     * @param mixed[] $args
     * @param mixed   $expectedResult
     *
     * @dataProvider provideCallablesAndTheirExpectedResults
     */
    final public function testFullRunThroughMultipleConsecutiveCalls(Closure $callable, array $args, $expectedResult): void
    {
        $loop = Factory::create();
        $pool = $this->createPool($loop);

        $promises = [];
        foreach (range(0, 8) as $i) {
            $promises[$i] = $pool->run($callable, $args);
        }

        $results = $this->await(all($promises)->always(static function () use ($pool): void {
            $pool->close();
        }), $loop);

        foreach ($results as $result) {
            self::assertSame($expectedResult, $result);
        }
    }

    /**
     * @param mixed[] $args
     * @param mixed   $expectedResult
     *
     * @dataProvider provideCallablesAndTheirExpectedResults
     */
    final public function testClosedPoolShouldNotRunClosures(Closure $callable, array $args, $expectedResult): void
    {
        self::expectException(ClosedException::class);

        $loop = Factory::create();
        $pool = $this->createPool($loop);
        self::assertTrue($pool->close());

        $this->await($pool->run($callable, $args), $loop);
    }

    final public function testKillingPoolWhileRunningClosuresShouldNotYieldValidResult(): void
    {
        self::expectException(Killed::class);

        $loop = Factory::create();
        $pool = $this->createPool($loop);

        $loop->futureTick(static function () use ($pool): void {
            $pool->kill();
        });

        try {
            $this->await($pool->run(static function (): int {
                sleep(1);

                return 123;
            }), $loop);
        } catch (UnexpectedValueException $unexpectedValueException) {
            /** @psalm-suppress InvalidThrow */
            throw $unexpectedValueException->getPrevious();
        }
    }

    abstract protected function createPool(LoopInterface $loop): PoolInterface;
}
