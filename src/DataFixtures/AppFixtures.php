<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * A realistic pay period to process.
 *
 * The scenario is the leave year 2025, with a run date of 2025-04-15. Run the
 * processor with:  bin/console app:absence:run --date=2025-04-15
 */
final class AppFixtures extends Fixture
{
    /*
    we need PHP >= 8.3 to support this typed annotation
    https://www.php.net/manual/en/language.types.declarations.php
     8.3.0	Support for class, interface, trait, and enum constant typing has been added.
    */
    private const int LEAVE_YEAR = 2025;

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $date = static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d);

        // --- Anna Becker — full-time, Bavaria, with us since 2018. -----------------
        $anna = new Employee('Anna Becker', $date('2018-06-01'), 5, 'BY', 28);
        $manager->persist($anna);
        $manager->persist(new LeaveBalance($anna, self::LEAVE_YEAR, 6.0, $date('2025-03-31'), 24.0));

        $this->request($manager, $anna, LeaveType::VACATION, '2025-05-19', '2025-05-23', '2025-04-10');
        $this->request($manager, $anna, LeaveType::VACATION, '2025-04-28', '2025-04-30', '2025-04-11', halfDayStart: true);
        $this->request($manager, $anna, LeaveType::SPECIAL, '2025-06-02', '2025-06-02', '2025-04-12');

        // --- Bjarne Vogt — part-time (three days a week), Berlin. -------------------
        $bjarne = new Employee('Bjarne Vogt', $date('2017-01-01'), 3, 'BE', 28);
        $manager->persist($bjarne);
        $manager->persist(new LeaveBalance($bjarne, self::LEAVE_YEAR, 0.0, null, 14.0));

        $this->request($manager, $bjarne, LeaveType::VACATION, '2025-07-07', '2025-07-11', '2025-04-09');

        // --- Carla Roth — full-time, Berlin, joined this March. --------------------
        $carla = new Employee('Carla Roth', $date('2025-03-01'), 5, 'BE', 30);
        $manager->persist($carla);
        $manager->persist(new LeaveBalance($carla, self::LEAVE_YEAR, 0.0, null, 21.0));

        $this->request($manager, $carla, LeaveType::VACATION, '2025-07-07', '2025-07-11', '2025-04-08');

        // --- Dilan Yilmaz — full-time, Bavaria. ------------------------------------
        $dilan = new Employee('Dilan Yilmaz', $date('2015-01-01'), 5, 'BY', 30);
        $manager->persist($dilan);
        $manager->persist(new LeaveBalance($dilan, self::LEAVE_YEAR, 0.0, null, 10.0));

        // Vacation already taken in March (this is where the 10 used days come from).
        $marchVacation = new LeaveRequest($dilan, LeaveType::VACATION, $date('2025-03-17'), $date('2025-03-28'), $date('2025-02-01'));
        $marchVacation->markDecided(LeaveStatus::APPROVED, $date('2025-02-15'), 'within balance');
        $manager->persist($marchVacation);

        // Then a sick note arrived covering part of that vacation.
        $this->request($manager, $dilan, LeaveType::SICK, '2025-03-24', '2025-03-26', '2025-04-01', medicalCertificate: true);

        // --- Eva Klein — full-time, Berlin. ----------------------------------------
        $eva = new Employee('Eva Klein', $date('2019-01-01'), 5, 'BE', 28);
        $manager->persist($eva);
        $manager->persist(new LeaveBalance($eva, self::LEAVE_YEAR, 0.0, null, 5.0));

        // A February booking that was later cancelled.
        $februaryVacation = new LeaveRequest($eva, LeaveType::VACATION, $date('2025-02-10'), $date('2025-02-14'), $date('2025-01-10'));
        $februaryVacation->markDecided(LeaveStatus::CANCELLED, $date('2025-01-20'), 'cancelled by employee');
        $manager->persist($februaryVacation);

        $this->request($manager, $eva, LeaveType::UNPAID, '2025-05-05', '2025-05-09', '2025-04-02');
        $this->request($manager, $eva, LeaveType::VACATION, '2025-06-05', '2025-06-11', '2025-04-03', halfDayStart: true);

        // --- Felix Wolf — full-time, Berlin. ---------------------------------------
        $felix = new Employee('Felix Wolf', $date('2016-01-01'), 5, 'BE', 28);
        $manager->persist($felix);
        $manager->persist(new LeaveBalance($felix, self::LEAVE_YEAR, 0.0, null, 0.0));

        $this->request($manager, $felix, LeaveType::VACATION, '2025-05-26', '2025-05-30', '2025-04-04');
        $this->request($manager, $felix, LeaveType::VACATION, '2025-05-28', '2025-06-03', '2025-04-05');

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
