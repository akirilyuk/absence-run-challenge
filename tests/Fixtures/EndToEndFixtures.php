<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class EndToEndFixtures extends Fixture
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $date = static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d);

        // Unknown federal state: still count working days (no regional holidays).
        $unknown = new Employee('Uma Unknown', $date('2018-01-01'), 5, 'NW', 28);
        $manager->persist($unknown);
        $manager->persist(new LeaveBalance($unknown, 2025, 0.0, null, 0.0));
        $this->request($manager, $unknown, LeaveType::VACATION, '2025-01-01', '2025-01-01', '2025-04-01');

        // Joiner pro-rata: Apr-Dec = 9 months => 30 * 9/12 = 22.5 entitlement.
        $joiner = new Employee('Jill Joiner', $date('2025-03-10'), 5, 'BE', 30);
        $manager->persist($joiner);
        $manager->persist(new LeaveBalance($joiner, 2025, 0.0, null, 22.0));
        $this->request($manager, $joiner, LeaveType::VACATION, '2025-07-08', '2025-07-08', '2025-04-02', halfDayStart: true);

        // Leaver pro-rata: Jan-Jun = 6 months => 30 * 6/12 = 15 entitlement.
        $leaver = new Employee('Lenny Leaver', $date('2018-01-01'), 5, 'BE', 30, $date('2025-06-10'));
        $manager->persist($leaver);
        $manager->persist(new LeaveBalance($leaver, 2025, 0.0, null, 14.5));
        $this->request($manager, $leaver, LeaveType::VACATION, '2025-05-06', '2025-05-06', '2025-04-03', halfDayStart: true);

        // Join + leave: Apr-Oct = 7 months => 30 * 7/12 = 17.5 entitlement.
        $both = new Employee('Jamie Both', $date('2025-03-10'), 5, 'BE', 30, $date('2025-10-12'));
        $manager->persist($both);
        $manager->persist(new LeaveBalance($both, 2025, 0.0, null, 17.0));
        $this->request($manager, $both, LeaveType::VACATION, '2025-08-01', '2025-08-01', '2025-04-04', halfDayStart: true);

        // Part-time: 28 * 3/5 = 16.8 => 17 entitlement.
        $part = new Employee('Pat Part', $date('2018-01-01'), 3, 'BE', 28);
        $manager->persist($part);
        $manager->persist(new LeaveBalance($part, 2025, 0.0, null, 16.5));
        $this->request($manager, $part, LeaveType::VACATION, '2025-05-07', '2025-05-07', '2025-04-05', halfDayStart: true);

        // Sick without certificate overlapping approved vacation (no credit back).
        $sick = new Employee('Nina Sick', $date('2018-01-01'), 5, 'BY', 28);
        $manager->persist($sick);
        $manager->persist(new LeaveBalance($sick, 2025, 0.0, null, 5.0));
        $approved = new LeaveRequest($sick, LeaveType::VACATION, $date('2025-03-10'), $date('2025-03-14'), $date('2025-02-01'));
        $approved->markDecided(LeaveStatus::APPROVED, $date('2025-02-15'), 'within balance');
        $manager->persist($approved);
        $this->request($manager, $sick, LeaveType::SICK, '2025-03-12', '2025-03-13', '2025-04-06');

        // Cross-year request with enough balance in both years.
        $enough = new Employee('Yara Yearly', $date('2018-01-01'), 5, 'BE', 28);
        $manager->persist($enough);
        $manager->persist(new LeaveBalance($enough, 2025, 0.0, null, 24.0));
        $manager->persist(new LeaveBalance($enough, 2026, 0.0, null, 0.0));
        $this->request($manager, $enough, LeaveType::VACATION, '2025-12-29', '2026-01-03', '2025-04-07');

        // Cross-year request with insufficient balance (reject).
        $short = new Employee('Ned NoBalance', $date('2018-01-01'), 5, 'BE', 28);
        $manager->persist($short);
        $manager->persist(new LeaveBalance($short, 2025, 0.0, null, 27.0));
        $manager->persist(new LeaveBalance($short, 2026, 0.0, null, 27.0));
        $this->request($manager, $short, LeaveType::VACATION, '2025-12-29', '2026-01-03', '2025-04-08');

        $manager->flush();
    }

    private function request(
        ObjectManager $manager,
        Employee $employee,
        LeaveType $type,
        string $start,
        string $end,
        string $submittedAt,
        bool $halfDayStart = false,
        bool $halfDayEnd = false,
        bool $medicalCertificate = false,
    ): void {
        $request = new LeaveRequest(
            $employee,
            $type,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submittedAt),
        );
        $request
            ->setHalfDayStart($halfDayStart)
            ->setHalfDayEnd($halfDayEnd)
            ->setMedicalCertificate($medicalCertificate);

        $manager->persist($request);
    }
}
