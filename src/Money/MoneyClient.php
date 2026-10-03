<?php
declare(strict_types=1);

namespace gCore\gNode\Money;

use gCore\gNode\gNodeClient;
use gCore\gNode\Exception\MoneyException;

/**
 * MoneyClient - exact decimal arithmetic, performed by the gNode daemon
 *
 * Every operation here is a round trip. That is the design, not a compromise:
 * the daemon is the one process in the constellation that links an exact,
 * deterministic, zero-float decimal library (g_math), and PHP on this estate
 * has neither bcmath nor gmp, so a division performed locally would be a
 * float. One authority for the rounding rules, reached over the wire every
 * service already speaks.
 *
 * The replies are the provider's wire shape already: hand `lines()` output
 * straight to the payment API. Nothing in PHP re-formats an amount, because
 * re-formatting is where a width or a separator drifts.
 *
 * Three refusals are deliberate:
 * - the topology fallback is never allowed to answer a money command, because
 *   a fallback's job is to guess plausibly and money must fail closed;
 * - a null reply is an error, not an empty result;
 * - nothing is cached, because a tie policy or a rate can change between
 *   calls and a stale cent is worse than a round trip.
 *
 * @package gCore\gNode\Money
 */
final class MoneyClient
{
    /** @var gNodeClient */
    private $client;

    public function __construct(gNodeClient $client)
    {
        $this->client = $client;
    }

    /**
     * Reconcile invoice lines exactly.
     *
     *   totalAmount = unitPrice x quantity - discountAmount
     *   vatAmount   = totalAmount x (vatRate / (100 + vatRate))
     *   amount      = sum of every line's totalAmount
     *
     * @param string               $currency 3-letter uppercase ISO 4217 code
     * @param array<int,array>     $lines    unitPrice, quantity, [discountAmount], [vatRate]
     * @param array<string,mixed>  $options  tie, strict_rate, expected_amount
     * @return array<string,mixed> currency, exponent, amount, vatAmount, ties, tie_policy, lines
     * @throws MoneyException
     * @api
     */
    public function lines(string $currency, array $lines, array $options = []): array
    {
        $params = ['currency' => $currency, 'lines' => array_values($lines)];
        foreach (['tie', 'strict_rate', 'expected_amount'] as $key) {
            if (array_key_exists($key, $options)) {
                $params[$key] = $options[$key];
            }
        }
        return $this->call('money_lines', $params, ['amount', 'vatAmount', 'lines']);
    }

    /**
     * Split an amount by integer weights so the parts sum to it exactly.
     *
     * @param array<int,int> $weights non-negative, not all zero
     * @return array<string,mixed> currency, amount, parts, exact
     * @throws MoneyException
     * @api
     */
    public function allocate(string $currency, string $amount, array $weights): array
    {
        return $this->call('money_allocate', [
            'currency' => $currency,
            'amount' => $amount,
            'weights' => array_values(array_map('intval', $weights)),
        ], ['parts', 'exact']);
    }

    /**
     * Sum exact decimal strings, optionally asserting the total.
     *
     * @param array<int,string> $values
     * @return array<string,mixed> currency, total, count, matches
     * @throws MoneyException
     * @api
     */
    public function sum(string $currency, array $values, ?string $expected = null): array
    {
        $params = ['currency' => $currency, 'values' => array_values($values)];
        if ($expected !== null) {
            $params['expected'] = $expected;
        }
        return $this->call('money_sum', $params, ['total', 'count']);
    }

    /**
     * Convenience for the common question: do these amounts add up to that one?
     *
     * @param array<int,string> $values
     * @throws MoneyException
     * @api
     */
    public function sumMatches(string $currency, array $values, string $expected): bool
    {
        return $this->sum($currency, $values, $expected)['matches'] === true;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<int,string>   $expectedKeys
     * @return array<string,mixed>
     * @throws MoneyException
     */
    private function call(string $command, array $params, array $expectedKeys): array
    {
        if ($this->client->isUsingFallback()) {
            throw new MoneyException(
                "{$command} refused: the gNode client is on its topology fallback, which cannot "
                . "compute money. Exact arithmetic has one authority and this is not it."
            );
        }

        try {
            $result = $this->client->executeCommand($command, $params);
        } catch (\Throwable $e) {
            throw new MoneyException("{$command} failed: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($result) || $result === []) {
            throw new MoneyException(
                "{$command} returned no result. The daemon did not answer; no amount was computed."
            );
        }
        foreach ($expectedKeys as $key) {
            if (!array_key_exists($key, $result)) {
                throw new MoneyException(
                    "{$command} returned a reply without '{$key}'. Refusing to treat a partial "
                    . "reply as an amount."
                );
            }
        }
        return $result;
    }
}
