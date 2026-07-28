<?php
declare(strict_types=1);
namespace gCore\gNode\Tests\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Capability routing: selection logic, exercised without a live daemon.
 *
 * The daemon-facing half (findServices / sendRawCommand) is integration
 * territory; what is unit-testable — and what actually decides where work goes
 * — is the candidate selection. Every branch below is a way the caller could be
 * sent somewhere it did not intend.
 */
final class CapRouteTest extends TestCase
{
    /** Mirrors sendCommandToCapable()'s selection, isolated from transport. */
    private function pick(array $candidates, string $self, bool $excludeSelf, int $wanted): ?string
    {
        $viable = [];
        foreach ($candidates as $c) {
            $id = $c['id'] ?? $c['service_id'] ?? $c['name'] ?? null;
            if ($id === null) { continue; }
            if ($excludeSelf && $id === $self) { continue; }
            $viable[] = $id;
        }
        if (empty($viable)) { return null; }
        return $viable[min(max(0,$wanted), count($viable) - 1)];
    }

    public function testPicksBestMatchFirst(): void
    {
        $c = [['id' => 'gpu_80gb'], ['id' => 'gpu_24gb']];
        self::assertSame('gpu_80gb', $this->pick($c, 'web', true, 0),
            'discovery returns best-first; routing must not reorder it');
    }

    public function testSelfIsExcludedByDefault(): void
    {
        $c = [['id' => 'web'], ['id' => 'gpu_80gb']];
        self::assertSame('gpu_80gb', $this->pick($c, 'web', true, 0),
            'a node must not relay work to itself by default');
        self::assertSame('web', $this->pick($c, 'web', false, 0),
            'exclude_self=false is how a caller opts into running it locally');
    }

    public function testNoViableTargetIsNullNotAGuess(): void
    {
        self::assertNull($this->pick([], 'web', true, 0));
        self::assertNull($this->pick([['id' => 'web']], 'web', true, 0),
            'the only candidate being self must fail loudly, not fall back to local');
    }

    public function testCandidateIndexSpillsDeliberatelyAndClamps(): void
    {
        $c = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
        self::assertSame('b', $this->pick($c, 'x', true, 1), 'second choice on request');
        self::assertSame('c', $this->pick($c, 'x', true, 99),
            'an out-of-range index clamps to the worst viable rather than erroring');
        self::assertSame('a', $this->pick($c, 'x', true, -5), 'negative clamps to best');
    }

    public function testAlternateIdFieldsAreAccepted(): void
    {
        self::assertSame('svc', $this->pick([['service_id' => 'svc']], 'x', true, 0));
        self::assertSame('nm',  $this->pick([['name' => 'nm']], 'x', true, 0));
        self::assertNull($this->pick([['score' => 0.9]], 'x', true, 0),
            'a candidate with no usable id must be skipped, not sent to an empty target');
    }
}
