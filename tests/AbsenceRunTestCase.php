<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Repository\LeaveBalanceRepository;
use App\Repository\LeaveRequestRepository;
use App\Service\EntitlementCalculator;
use App\Service\HolidayCalendar;
use App\Service\LeaveRequestProcessor;
use App\Service\WorkingDayCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for processor tests: boots the kernel, gives each test a fresh
 * SQLite schema, and wires the processor against an in-memory HR API client.
 */
abstract class AbsenceRunTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected FakeHrApiClient $hrApi;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->hrApi = new FakeHrApiClient();
    }

    protected function processor(): LeaveRequestProcessor
    {
        $requests = $this->em->getRepository(LeaveRequest::class);
        $balances = $this->em->getRepository(LeaveBalance::class);
        \assert($requests instanceof LeaveRequestRepository);
        \assert($balances instanceof LeaveBalanceRepository);

        $holidayCalendar = new HolidayCalendar();
        $workingDays = new WorkingDayCalculator($holidayCalendar);
        $entitlement = new EntitlementCalculator();

        return new LeaveRequestProcessor($this->em, $requests, $balances, $this->hrApi, $entitlement, $workingDays);
    }

    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }
}
