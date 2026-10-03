<?php
declare(strict_types=1);
namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\gNodeClient;
use gCore\gNode\Money\Money;
use gCore\gNode\Money\MoneyClient;
use gCore\gNode\Exception\MoneyException;

/**
 * The money boundary, exercised without a daemon.
 *
 * What is unit-testable here is not the arithmetic — that is the daemon's, and
 * it is validated against exact-rational references on the Rust side. What is
 * testable is every way a caller or a half-answered reply could end up being
 * treated as an amount.
 */
final class MoneyTest extends TestCase
{
    public function testExactDecimalStringsAreAccepted(): void
    {
        foreach (['19.99', '0', '0.00', '-5.50', '1234567.89', '1250', '0.000001'] as $value) {
            $this->assertSame($value, Money::of($value, 'EUR')->amount(), $value);
        }
    }

    public function testAnythingThatIsNotAnExactDecimalIsRefused(): void
    {
        foreach (['', '.5', '1e2', '1,50', 'abc', '1.2.3', '--5', '+5', '019', 'NaN',
                  'Infinity', '0.0000001', '19.99 ', ' ', '1 000'] as $value) {
            try {
                Money::of($value, 'EUR');
                $this->fail('accepted ' . var_export($value, true));
            } catch (MoneyException $e) {
                $this->assertStringContainsString('exact decimal', $e->getMessage());
            }
        }
    }

    public function testAFloatCannotEvenBeOffered(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line deliberate: the signature is the guard */
        Money::of(19.99, 'EUR');
    }

    public function testCurrencyMustBeAThreeLetterUppercaseCode(): void
    {
        foreach (['eur', 'EURO', 'EU', '', 'E1R'] as $code) {
            try {
                Money::of('1.00', $code);
                $this->fail('accepted currency ' . var_export($code, true));
            } catch (MoneyException $e) {
                $this->assertStringContainsString('ISO 4217', $e->getMessage());
            }
        }
    }

    public function testMinorUnitsWidenToTheExponent(): void
    {
        $this->assertSame('33.99', Money::ofMinor(3399, 2, 'EUR')->amount());
        $this->assertSame('0.07', Money::ofMinor(7, 2, 'EUR')->amount());
        $this->assertSame('-0.07', Money::ofMinor(-7, 2, 'EUR')->amount());
        $this->assertSame('1250', Money::ofMinor(1250, 0, 'JPY')->amount());
        $this->assertSame('0.000001', Money::ofMinor(1, 6, 'EUR')->amount());
        $this->expectException(MoneyException::class);
        Money::ofMinor(1, 7, 'EUR');
    }

    public function testEqualityIgnoresTrailingZerosAndSignedZero(): void
    {
        $this->assertTrue(Money::of('19.9', 'EUR')->equals(Money::of('19.900', 'EUR')));
        $this->assertTrue(Money::of('-0.00', 'EUR')->equals(Money::of('0', 'EUR')));
        $this->assertFalse(Money::of('19.90', 'EUR')->equals(Money::of('19.91', 'EUR')));
        $this->assertFalse(Money::of('1.00', 'EUR')->equals(Money::of('1.00', 'USD')));
    }

    public function testZeroAndSignPredicates(): void
    {
        $this->assertTrue(Money::of('0.00', 'EUR')->isZero());
        $this->assertTrue(Money::of('-0.00', 'EUR')->isZero());
        $this->assertFalse(Money::of('-0.00', 'EUR')->isNegative());
        $this->assertTrue(Money::of('-0.01', 'EUR')->isNegative());
        $this->assertFalse(Money::of('0.01', 'EUR')->isNegative());
        $this->assertSame('19.99 EUR', (string) Money::of('19.99', 'EUR'));
    }

    public function testMoneyHasNoArithmetic(): void
    {
        $methods = get_class_methods(Money::class);
        foreach (['add', 'plus', 'subtract', 'minus', 'multiply', 'times',
                  'divide', 'allocate', 'percentage', 'round'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods, "Money::{$forbidden} must not exist");
        }
    }

    public function testTheFallbackIsNeverAllowedToComputeMoney(): void
    {
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(true);
        $client->expects($this->never())->method('executeCommand');

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageMatches('/fallback/');
        (new MoneyClient($client))->sum('EUR', ['1.00']);
    }

    public function testANullReplyIsAnErrorNotAnEmptyResult(): void
    {
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')->willReturn(null);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageMatches('/no result/');
        (new MoneyClient($client))->sum('EUR', ['1.00']);
    }

    public function testAPartialReplyIsRefused(): void
    {
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')->willReturn(['currency' => 'EUR']);

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageMatches('/partial reply/');
        (new MoneyClient($client))->sum('EUR', ['1.00']);
    }

    public function testADaemonErrorSurfacesAsAMoneyException(): void
    {
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')
            ->willThrowException(new \RuntimeException('vatRate "25.01" carries two decimal places'));

        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageMatches('/two decimal places/');
        (new MoneyClient($client))->lines('EUR', [['unitPrice' => '1.00', 'quantity' => 1]]);
    }

    public function testLinesSendsExactlyWhatWasAskedAndNothingElse(): void
    {
        $seen = null;
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')->willReturnCallback(
            function (string $cmd, array $params) use (&$seen) {
                $seen = [$cmd, $params];
                return ['amount' => '19.00', 'vatAmount' => '3.30', 'lines' => []];
            }
        );

        $lines = [7 => ['unitPrice' => '10.00', 'quantity' => 2, 'vatRate' => '21']];
        $out = (new MoneyClient($client))->lines('EUR', $lines, ['tie' => 'half_even']);

        $this->assertSame('money_lines', $seen[0]);
        $this->assertSame('EUR', $seen[1]['currency']);
        $this->assertSame('half_even', $seen[1]['tie']);
        $this->assertArrayNotHasKey('strict_rate', $seen[1]);
        $this->assertArrayNotHasKey('expected_amount', $seen[1]);
        $this->assertSame([0], array_keys($seen[1]['lines']), 'lines must be re-indexed for JSON');
        $this->assertSame('19.00', $out['amount']);
    }

    public function testAllocateCoercesWeightsToIntegersAndReindexes(): void
    {
        $seen = null;
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')->willReturnCallback(
            function (string $cmd, array $params) use (&$seen) {
                $seen = $params;
                return ['parts' => ['3.34', '3.33', '3.33'], 'exact' => true];
            }
        );

        (new MoneyClient($client))->allocate('EUR', '10.00', [3 => '1', 9 => 1, 11 => 1]);
        $this->assertSame([1, 1, 1], $seen['weights']);
        $this->assertSame('10.00', $seen['amount']);
    }

    public function testParseNeedsExactlyOneInputForm(): void
    {
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->expects($this->never())->method('executeCommand');
        $money = new MoneyClient($client);

        foreach ([[null, null], ['1.00', 100]] as [$value, $minor]) {
            try {
                $money->parse('EUR', $value, $minor);
                $this->fail('accepted both/neither');
            } catch (MoneyException $e) {
                $this->assertStringContainsString('exactly one', $e->getMessage());
            }
        }
    }

    public function testParseSendsOnlyTheFormItWasGiven(): void
    {
        $seen = [];
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')->willReturnCallback(
            function (string $cmd, array $params) use (&$seen) {
                $seen[] = $params;
                return ['value' => '19.99', 'minor' => 1999, 'exponent' => 2];
            }
        );
        $money = new MoneyClient($client);

        $money->parse('EUR', '19.99');
        $this->assertArrayHasKey('value', $seen[0]);
        $this->assertArrayNotHasKey('minor', $seen[0]);

        $money->parse('EUR', null, 1999);
        $this->assertSame(1999, $seen[1]['minor']);
        $this->assertArrayNotHasKey('value', $seen[1]);
    }

    public function testSumMatchesReportsOnlyAnExplicitTrue(): void
    {
        $client = $this->createMock(gNodeClient::class);
        $client->method('isUsingFallback')->willReturn(false);
        $client->method('executeCommand')->willReturn(
            ['total' => '3.30', 'count' => 2, 'matches' => false]
        );
        $this->assertFalse((new MoneyClient($client))->sumMatches('EUR', ['1.10', '2.20'], '3.31'));
    }
}
