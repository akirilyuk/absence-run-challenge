<?php

declare(strict_types=1);

namespace App\Tests;

use App\Hr\HrApiClientInterface;

/**
 * In-memory HR API client for tests — records calls instead of making HTTP requests.
 */
final class FakeHrApiClient implements HrApiClientInterface
{
    /** @var list<array{decision: array<string, mixed>, key: string}> */
    public array $calls = [];
    public bool $failNext = false;

    /** @var array<string, array{id: string}> */
    private array $responsesByKey = [];

    #[\Override]
    public function postDecision(array $decision, string $idempotencyKey): array
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('HR API unavailable');
        }

        $this->calls[] = ['decision' => $decision, 'key' => $idempotencyKey];
        if (!isset($this->responsesByKey[$idempotencyKey])) {
            $this->responsesByKey[$idempotencyKey] = ['id' => 'fake_'.\count($this->responsesByKey)];
        }

        return $this->responsesByKey[$idempotencyKey];
    }
}
