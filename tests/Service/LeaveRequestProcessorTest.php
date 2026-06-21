<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Tests\AbsenceRunTestCase;

final class LeaveRequestProcessorTest extends AbsenceRunTestCase
{
    public function testApprovesVacationWithinBalance(): void
    {
        $employee = $this->employee('Full Timer');
        $balance = $this->balance($employee, 2025);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $summary = $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertCount(1, $summary);
        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testRejectsVacationExceedingBalance(): void
    {
        $employee = $this->employee('Low Balance', '2018-01-01', 5, 'BE', 20);
        $balance = $this->balance($employee, 2025, 0.0, null, 19.0);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $request->getStatus());
        self::assertSame(19.0, $balance->getUsedDays());
    }

    public function testReportsEachDecisionToHrApi(): void
    {
        $employee = $this->employee('Full Timer');
        $balance = $this->balance($employee, 2025);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertCount(1, $this->hrApi->calls);
        self::assertSame('approved', $this->hrApi->calls[0]['decision']['decision']);
        self::assertSame(5.0, $this->hrApi->calls[0]['decision']['days']);
        self::assertSame('absence-run-'.$request->getId(), $this->hrApi->calls[0]['key']);
    }

    public function testExcludesWeekendsHolidaysAndHalfDays(): void
    {
        $employee = $this->employee('Holiday Case', '2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, 2025);
        $request = $this->vacation($employee, '2025-06-05', '2025-06-11');
        $request->setHalfDayStart(true);
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(3.5, $balance->getUsedDays());
    }

    public function testCarryoverExpiryIsRespected(): void
    {
        $employee = $this->employee('Carryover');
        $balance = $this->balance($employee, 2025, 6.0, '2025-03-31', 24.0);
        $longRequest = $this->vacation($employee, '2025-05-19', '2025-05-23', '2025-04-01');
        $shortRequest = $this->vacation($employee, '2025-04-28', '2025-04-30', '2025-04-02');
        $shortRequest->setHalfDayStart(true);
        $this->persist($employee, $balance, $longRequest, $shortRequest);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $longRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $shortRequest->getStatus());
        self::assertSame(26.5, $balance->getUsedDays());
    }

    public function testCertifiedSickCreditsOverlappingVacation(): void
    {
        $employee = $this->employee('Dilan', '2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, 2025, 0.0, null, 10.0);
        $approvedVacation = $this->vacation($employee, '2025-03-17', '2025-03-28');
        $approvedVacation->setStatus(LeaveStatus::APPROVED);
        $sick = $this->sick($employee, '2025-03-24', '2025-03-26', true);
        $this->persist($employee, $balance, $approvedVacation, $sick);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $sick->getStatus());
        self::assertSame(7.0, $balance->getUsedDays());
    }

    public function testUncertifiedSickDoesNotCreditVacation(): void
    {
        $employee = $this->employee('Sick No Cert', '2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, 2025, 0.0, null, 10.0);
        $approvedVacation = $this->vacation($employee, '2025-03-17', '2025-03-28');
        $approvedVacation->setStatus(LeaveStatus::APPROVED);
        $sick = $this->sick($employee, '2025-03-24', '2025-03-26', false);
        $this->persist($employee, $balance, $approvedVacation, $sick);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $sick->getStatus());
        self::assertSame(10.0, $balance->getUsedDays());
    }

    public function testOverlappingVacationsRejectLaterSubmission(): void
    {
        $employee = $this->employee('Overlap', '2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, 2025);
        $first = $this->vacation($employee, '2025-05-26', '2025-05-30', '2025-04-01');
        $second = $this->vacation($employee, '2025-05-28', '2025-06-03', '2025-04-02');
        $this->persist($employee, $balance, $first, $second);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $first->getStatus());
        self::assertSame(LeaveStatus::REJECTED, $second->getStatus());
        self::assertSame(4.0, $balance->getUsedDays());
    }

    public function testUnpaidAndSpecialDoNotConsumeBalance(): void
    {
        $employee = $this->employee('Unpaid', '2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, 2025, 0.0, null, 5.0);
        $unpaid = $this->unpaid($employee, '2025-05-05', '2025-05-09');
        $special = $this->special($employee, '2025-06-02', '2025-06-02');
        $this->persist($employee, $balance, $unpaid, $special);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $unpaid->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $special->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testReRunSkipsNonPendingRequests(): void
    {
        $employee = $this->employee('ReRun');
        $balance = $this->balance($employee, 2025);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
        self::assertCount(1, $this->hrApi->calls);
    }

    public function testHrFailureLeavesRequestPendingForRetry(): void
    {
        $employee = $this->employee('Retry');
        $balance = $this->balance($employee, 2025);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $this->hrApi->failNext = true;
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::PENDING, $request->getStatus());
        self::assertSame(0.0, $balance->getUsedDays());

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testJoinerProRataEntitlement(): void
    {
        $employee = $this->employee('Joiner', '2025-03-10', 5, 'BE', 30);
        $balance = $this->balance($employee, 2025, 0.0, null, 22.0);
        $fullDay = $this->vacation($employee, '2025-07-07', '2025-07-07', '2025-04-01');
        $halfDay = $this->vacation($employee, '2025-07-08', '2025-07-08', '2025-04-02');
        $halfDay->setHalfDayStart(true);
        $this->persist($employee, $balance, $fullDay, $halfDay);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $fullDay->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $halfDay->getStatus());
        self::assertSame(22.5, $balance->getUsedDays());
    }

    public function testLeaverProRataEntitlement(): void
    {
        $employee = $this->employee('Leaver', '2018-01-01', 5, 'BE', 30, '2025-06-10');
        $balance = $this->balance($employee, 2025, 0.0, null, 14.5);
        $fullDay = $this->vacation($employee, '2025-05-05', '2025-05-05', '2025-04-01');
        $halfDay = $this->vacation($employee, '2025-05-06', '2025-05-06', '2025-04-02');
        $halfDay->setHalfDayStart(true);
        $this->persist($employee, $balance, $fullDay, $halfDay);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $fullDay->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $halfDay->getStatus());
        self::assertSame(15.0, $balance->getUsedDays());
    }

    public function testPartTimeEntitlementScaling(): void
    {
        $employee = $this->employee('Part Time', '2018-01-01', 3, 'BE', 28);
        $balance = $this->balance($employee, 2025, 0.0, null, 16.5);
        $fullDay = $this->vacation($employee, '2025-05-05', '2025-05-05', '2025-04-01');
        $halfDay = $this->vacation($employee, '2025-05-06', '2025-05-06', '2025-04-02');
        $halfDay->setHalfDayStart(true);
        $this->persist($employee, $balance, $fullDay, $halfDay);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $fullDay->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $halfDay->getStatus());
        self::assertSame(17.0, $balance->getUsedDays());
    }

    public function testCrossYearRequiresBalanceInBothYears(): void
    {
        $employee = $this->employee('Cross Year');
        $balance2025 = $this->balance($employee, 2025, 0.0, null, 26.0);
        $balance2026 = $this->balance($employee, 2026);
        $request = $this->vacation($employee, '2025-12-29', '2026-01-03');
        $this->persist($employee, $balance2025, $balance2026, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $request->getStatus());
        self::assertSame(26.0, $balance2025->getUsedDays());
        self::assertSame(0.0, $balance2026->getUsedDays());
    }

    public function testUnknownStateAssumesNoHolidays(): void
    {
        $employee = $this->employee('Unknown State', '2018-01-01', 5, 'NW', 28);
        $balance = $this->balance($employee, 2025);
        $request = $this->vacation($employee, '2025-01-01', '2025-01-01');
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(1.0, $balance->getUsedDays());
    }

    private function employee(
        string $name,
        string $start = '2018-01-01',
        int $workingDays = 5,
        string $state = 'BE',
        int $contractual = 28,
        ?string $end = null,
    ): Employee {
        return new Employee(
            $name,
            new \DateTimeImmutable($start),
            $workingDays,
            $state,
            $contractual,
            null !== $end ? new \DateTimeImmutable($end) : null,
        );
    }

    private function balance(
        Employee $employee,
        int $year,
        float $carryover = 0.0,
        ?string $carryoverExpires = null,
        float $usedDays = 0.0,
    ): LeaveBalance {
        return new LeaveBalance(
            $employee,
            $year,
            $carryover,
            null !== $carryoverExpires ? new \DateTimeImmutable($carryoverExpires) : null,
            $usedDays,
        );
    }

    private function vacation(Employee $employee, string $start, string $end, string $submittedAt = '2025-04-10'): LeaveRequest
    {
        return new LeaveRequest(
            $employee,
            LeaveType::VACATION,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submittedAt),
        );
    }

    private function sick(
        Employee $employee,
        string $start,
        string $end,
        bool $certificate,
        string $submittedAt = '2025-04-10',
    ): LeaveRequest {
        $request = new LeaveRequest(
            $employee,
            LeaveType::SICK,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submittedAt),
        );
        $request->setMedicalCertificate($certificate);

        return $request;
    }

    private function unpaid(Employee $employee, string $start, string $end, string $submittedAt = '2025-04-10'): LeaveRequest
    {
        return new LeaveRequest(
            $employee,
            LeaveType::UNPAID,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submittedAt),
        );
    }

    private function special(Employee $employee, string $start, string $end, string $submittedAt = '2025-04-10'): LeaveRequest
    {
        return new LeaveRequest(
            $employee,
            LeaveType::SPECIAL,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submittedAt),
        );
    }
}
