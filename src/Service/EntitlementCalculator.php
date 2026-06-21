<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;

final class EntitlementCalculator
{
    public function calculate(Employee $employee, int $year): float
    {
        $base = max($employee->getContractualLeaveDays(), 20);
        $fullMonths = $this->fullMonthsEmployed($employee, $year);

        if (0 === $fullMonths) {
            return 0.0;
        }

        $proRata = $fullMonths / 12;
        $partTime = $employee->getWorkingDaysPerWeek() / 5;

        $raw = $base * $proRata * $partTime;

        return $this->roundUpToHalfDay($raw);
    }

    private function fullMonthsEmployed(Employee $employee, int $year): int
    {
        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year));

        $employmentStart = $employee->getEmploymentStartDate();
        $employmentEnd = $employee->getEmploymentEndDate();

        if (null !== $employmentEnd && $employmentEnd < $yearStart) {
            return 0;
        }

        if ($employmentStart > $yearEnd) {
            return 0;
        }

        $effectiveStart = $employmentStart > $yearStart ? $employmentStart : $yearStart;
        $effectiveEnd = null !== $employmentEnd && $employmentEnd < $yearEnd ? $employmentEnd : $yearEnd;

        if ($effectiveEnd < $effectiveStart) {
            return 0;
        }

        $startMonth = (int) $effectiveStart->format('n');
        if (1 !== (int) $effectiveStart->format('j')) {
            ++$startMonth;
        }

        $endMonth = (int) $effectiveEnd->format('n');

        if ($startMonth > 12 || $startMonth > $endMonth) {
            return 0;
        }

        return $endMonth - $startMonth + 1;
    }

    private function roundUpToHalfDay(float $value): float
    {
        return ceil($value * 2) / 2;
    }
}
