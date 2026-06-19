<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Hr\HrApiClientInterface;
use App\Repository\LeaveBalanceRepository;
use App\Repository\LeaveRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Processes pending leave requests and reports each decision to the HR system.
 */
final class LeaveRequestProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaveRequestRepository $leaveRequests,
        private readonly LeaveBalanceRepository $leaveBalances,
        private readonly HrApiClientInterface $hrApi,
        private readonly EntitlementCalculator $entitlementCalculator,
        private readonly WorkingDayCalculator $workingDays,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return list<array{request: int, status: string, days: float}>
     */
    public function processPending(\DateTimeImmutable $runDate): array
    {
        $summary = [];
        $approvedVacationsByEmployee = [];
        $balancesByEmployeeYear = [];

        foreach ($this->prioritizeRequests($this->leaveRequests->findPending()) as $request) {
            $decision = $this->decide($request, $runDate, $approvedVacationsByEmployee, $balancesByEmployeeYear);

            try {
                $response = $this->hrApi->postDecision(
                    [
                        'employeeId' => $request->getEmployee()->getId(),
                        'requestId' => $request->getId(),
                        'decision' => $decision->status->value,
                        'days' => $decision->consumedDays,
                        'reason' => $decision->reason,
                    ],
                    $this->idempotencyKeyFor($request),
                );
            } catch (\Throwable $error) {
                $this->logger->error('Failed to post HR decision.', [
                    'requestId' => $request->getId(),
                    'error' => $error->getMessage(),
                ]);
                continue;
            }

            $request->markDecided($decision->status, $runDate, $decision->reason);
            $request->setExternalReference(\is_string($response['id'] ?? null) ? $response['id'] : null);

            $this->applyBalanceAdjustments($request, $decision, $balancesByEmployeeYear);

            if (LeaveStatus::APPROVED === $decision->status && LeaveType::VACATION === $request->getType()) {
                $this->appendApprovedVacation($request, $approvedVacationsByEmployee);
            }

            $summary[] = [
                'request' => (int) $request->getId(),
                'status' => $decision->status->value,
                'days' => $decision->consumedDays,
            ];
        }

        $this->entityManager->flush();

        return $summary;
    }

    /**
     * Decide a single request.
     */
    /**
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $approvedVacationsByEmployee
     * @param array<string, LeaveBalance>                                                 $balancesByEmployeeYear
     */
    private function decide(
        LeaveRequest $request,
        \DateTimeImmutable $runDate,
        array &$approvedVacationsByEmployee,
        array &$balancesByEmployeeYear,
    ): Decision {
        $employee = $request->getEmployee();

        if (LeaveType::SICK === $request->getType()) {
            return $this->decideSick($request, $approvedVacationsByEmployee);
        }

        if (LeaveType::UNPAID === $request->getType()) {
            return new Decision(LeaveStatus::APPROVED, 0.0, 'unpaid leave');
        }

        if (LeaveType::SPECIAL === $request->getType()) {
            return new Decision(LeaveStatus::APPROVED, 0.0, 'special leave');
        }

        if ($this->overlapsApprovedVacation($request, $approvedVacationsByEmployee)) {
            return new Decision(LeaveStatus::REJECTED, 0.0, 'overlaps approved vacation');
        }

        $consumedByYear = $this->workingDays->countWorkingDaysByYear(
            $employee,
            $request->getStartDate(),
            $request->getEndDate(),
        );

        $consumedByYear = $this->applyHalfDayAdjustments($request, $consumedByYear);
        $totalConsumed = array_sum($consumedByYear);

        foreach ($consumedByYear as $year => $consumed) {
            $balance = $this->balanceForEmployeeYear($employee, (int) $year, $balancesByEmployeeYear);
            $remaining = $this->remainingBalance($balance, $runDate);

            if ($consumed > $remaining) {
                return new Decision(LeaveStatus::REJECTED, 0.0, 'insufficient balance');
            }
        }

        return new Decision(LeaveStatus::APPROVED, $totalConsumed, 'within balance', $consumedByYear);
    }

    /**
     * @param list<LeaveRequest> $requests
     *
     * @return list<LeaveRequest>
     */
    private function prioritizeRequests(array $requests): array
    {
        $sick = [];
        $other = [];

        foreach ($requests as $request) {
            if (LeaveType::SICK === $request->getType()) {
                $sick[] = $request;
            } else {
                $other[] = $request;
            }
        }

        return array_merge($sick, $other);
    }

    /**
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $approvedVacationsByEmployee
     */
    private function decideSick(LeaveRequest $request, array &$approvedVacationsByEmployee): Decision
    {
        $overlapByYear = $this->overlappingVacationDaysByYear($request, $approvedVacationsByEmployee);
        $totalOverlap = array_sum($overlapByYear);

        if ($request->hasMedicalCertificate() && $totalOverlap > 0) {
            $adjustments = [];
            foreach ($overlapByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }
                $adjustments[(int) $year] = -$days;
            }

            return new Decision(LeaveStatus::APPROVED, 0.0, 'certified sick during vacation', $adjustments);
        }

        return new Decision(LeaveStatus::APPROVED, 0.0, 'sick leave');
    }

    /**
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $approvedVacationsByEmployee
     *
     * @return array<int, float>
     */
    private function overlappingVacationDaysByYear(LeaveRequest $request, array &$approvedVacationsByEmployee): array
    {
        $employee = $request->getEmployee();
        $overlapDays = [];

        foreach ($this->approvedVacationsForEmployee($employee, $approvedVacationsByEmployee) as $range) {
            if (!$this->rangesOverlap($request->getStartDate(), $request->getEndDate(), $range['start'], $range['end'])) {
                continue;
            }

            $overlapStart = max($request->getStartDate(), $range['start']);
            $overlapEnd = min($request->getEndDate(), $range['end']);

            $daysByYear = $this->workingDays->countWorkingDaysByYear($employee, $overlapStart, $overlapEnd);
            $daysByYear = $this->applyHalfDayAdjustmentsForOverlap($request, $overlapStart, $overlapEnd, $daysByYear);

            foreach ($daysByYear as $year => $days) {
                $overlapDays[$year] = ($overlapDays[$year] ?? 0.0) + $days;
            }
        }

        return $overlapDays;
    }

    private function rangesOverlap(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        \DateTimeImmutable $otherStart,
        \DateTimeImmutable $otherEnd,
    ): bool {
        return $start <= $otherEnd && $end >= $otherStart;
    }

    /**
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $approvedVacationsByEmployee
     */
    private function overlapsApprovedVacation(LeaveRequest $request, array &$approvedVacationsByEmployee): bool
    {
        foreach ($this->approvedVacationsForEmployee($request->getEmployee(), $approvedVacationsByEmployee) as $range) {
            if ($this->rangesOverlap($request->getStartDate(), $request->getEndDate(), $range['start'], $range['end'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $approvedVacationsByEmployee
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    private function approvedVacationsForEmployee(\App\Entity\Employee $employee, array &$approvedVacationsByEmployee): array
    {
        $employeeId = (int) $employee->getId();

        if (!isset($approvedVacationsByEmployee[$employeeId])) {
            $approvedVacationsByEmployee[$employeeId] = [];
            foreach ($this->leaveRequests->findApprovedVacationsForEmployee($employeeId) as $approved) {
                $approvedVacationsByEmployee[$employeeId][] = [
                    'start' => $approved->getStartDate(),
                    'end' => $approved->getEndDate(),
                ];
            }
        }

        return $approvedVacationsByEmployee[$employeeId];
    }

    /**
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $approvedVacationsByEmployee
     */
    private function appendApprovedVacation(LeaveRequest $request, array &$approvedVacationsByEmployee): void
    {
        $employeeId = (int) $request->getEmployee()->getId();
        $approvedVacationsByEmployee[$employeeId][] = [
            'start' => $request->getStartDate(),
            'end' => $request->getEndDate(),
        ];
    }

    /**
     * @param array<int, float> $consumedByYear
     *
     * @return array<int, float>
     */
    private function applyHalfDayAdjustments(LeaveRequest $request, array $consumedByYear): array
    {
        $startYear = (int) $request->getStartDate()->format('Y');
        $endYear = (int) $request->getEndDate()->format('Y');

        if ($request->isHalfDayStart()) {
            $consumedByYear[$startYear] = max(0.0, ($consumedByYear[$startYear] ?? 0.0) - 0.5);
        }

        if ($request->isHalfDayEnd()) {
            $consumedByYear[$endYear] = max(0.0, ($consumedByYear[$endYear] ?? 0.0) - 0.5);
        }

        return $consumedByYear;
    }

    /**
     * @param array<int, float> $daysByYear
     *
     * @return array<int, float>
     */
    private function applyHalfDayAdjustmentsForOverlap(
        LeaveRequest $request,
        \DateTimeImmutable $overlapStart,
        \DateTimeImmutable $overlapEnd,
        array $daysByYear,
    ): array {
        if ($request->isHalfDayStart() && $request->getStartDate() == $overlapStart) {
            $year = (int) $overlapStart->format('Y');
            $daysByYear[$year] = max(0.0, ($daysByYear[$year] ?? 0.0) - 0.5);
        }

        if ($request->isHalfDayEnd() && $request->getEndDate() == $overlapEnd) {
            $year = (int) $overlapEnd->format('Y');
            $daysByYear[$year] = max(0.0, ($daysByYear[$year] ?? 0.0) - 0.5);
        }

        return $daysByYear;
    }

    private function remainingBalance(LeaveBalance $balance, \DateTimeImmutable $runDate): float
    {
        $carryover = $balance->getCarriedOverDays();
        $expiry = $balance->getCarryoverExpiresOn();
        if (null !== $expiry && $runDate > $expiry) {
            $carryover = 0.0;
        }

        $entitlement = $this->entitlementCalculator->calculate($balance->getEmployee(), $balance->getYear());

        return $entitlement + $carryover - $balance->getUsedDays();
    }

    private function idempotencyKeyFor(LeaveRequest $request): string
    {
        return sprintf('absence-run-%d', (int) $request->getId());
    }

    /**
     * @param array<string, LeaveBalance> $balancesByEmployeeYear
     */
    private function applyBalanceAdjustments(
        LeaveRequest $request,
        Decision $decision,
        array &$balancesByEmployeeYear,
    ): void {
        if ([] === $decision->balanceAdjustments) {
            return;
        }

        foreach ($decision->balanceAdjustments as $year => $days) {
            $balance = $this->balanceForEmployeeYear($request->getEmployee(), (int) $year, $balancesByEmployeeYear);
            $next = $balance->getUsedDays() + $days;
            $balance->setUsedDays(max(0.0, $next));
        }
    }

    /**
     * @param array<string, LeaveBalance> $balancesByEmployeeYear
     */
    private function balanceForEmployeeYear(
        \App\Entity\Employee $employee,
        int $year,
        array &$balancesByEmployeeYear,
    ): LeaveBalance {
        $employeeId = (int) $employee->getId();
        $key = sprintf('%d:%d', $employeeId, $year);

        if (!isset($balancesByEmployeeYear[$key])) {
            $balance = $this->leaveBalances->findForEmployeeAndYear($employee, $year);

            if (null === $balance) {
                throw new \RuntimeException(sprintf('No leave balance for employee #%d in %d.', $employeeId, $year));
            }

            $balancesByEmployeeYear[$key] = $balance;
        }

        return $balancesByEmployeeYear[$key];
    }
}
