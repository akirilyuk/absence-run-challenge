<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\LeaveStatus;

/**
 * The outcome of evaluating a single leave request.
 */
final readonly class Decision
{
    public function __construct(
        public LeaveStatus $status,
        public float $consumedDays,
        public string $reason,
        /** @var array<int, float> $balanceAdjustments */
        public array $balanceAdjustments = [],
    ) {
    }
}
