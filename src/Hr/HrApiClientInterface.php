<?php

declare(strict_types=1);

namespace App\Hr;

interface HrApiClientInterface
{
    /**
     * Post a leave decision to the HR system.
     *
     * @param array<string, mixed> $decision       the decision payload
     * @param string               $idempotencyKey sent as the Idempotency-Key header; the HR API
     *                                             returns the original result for a repeated key
     *
     * @return array<string, mixed> the decoded response
     */
    public function postDecision(array $decision, string $idempotencyKey): array;
}
