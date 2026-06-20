<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\DataFixtures\AppFixtures;
use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Tests\Fixtures\EndToEndFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\HttpClient;

final class AbsenceRunEndToEndTest extends KernelTestCase
{
    private string $hrBaseUrl;
    private int $hrPort;

    /** @var resource|null */
    private $serverHandle;
    private array $serverPipes = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $em = $this->entityManager();
        $this->resetDatabase($em);
        (new AppFixtures())->load($em);
        (new EndToEndFixtures())->load($em);

        $this->configureHrEndpoint();
        $this->startHrServer();
        $this->resetHrState();
    }

    protected function tearDown(): void
    {
        $this->stopHrServer();

        parent::tearDown();
    }

    public function testEndToEndRunUpdatesDatabaseAndHrApiState(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:absence:run');

        $tester = new CommandTester($command);
        $tester->execute(['--date' => '2025-04-15']);

        $em = $this->entityManager();
        $this->assertPrimaryOutcomes($em);
        $stateFile = $this->assertHrState(18);

        $firstState = json_decode((string) file_get_contents($stateFile), true);
        self::assertIsArray($firstState);
        self::assertArrayHasKey('decisions', $firstState);
        self::assertArrayHasKey('idempotency', $firstState);
        $firstDecisionCount = \count($firstState['decisions']);
        $firstKeyCount = \count($firstState['idempotency']);

        $tester->execute(['--date' => '2025-04-15']);

        $em->clear();
        $this->assertPrimaryOutcomes($em);

        $secondState = json_decode((string) file_get_contents($stateFile), true);
        self::assertIsArray($secondState);
        self::assertArrayHasKey('decisions', $secondState);
        self::assertArrayHasKey('idempotency', $secondState);
        self::assertSame($firstDecisionCount, \count($secondState['decisions']));
        self::assertSame($firstKeyCount, \count($secondState['idempotency']));
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function resetDatabase(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function employeeByName(EntityManagerInterface $em, string $name): Employee
    {
        $employee = $em->getRepository(Employee::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Employee::class, $employee);

        return $employee;
    }

    private function requestByStart(
        EntityManagerInterface $em,
        Employee $employee,
        LeaveType $type,
        string $startDate,
    ): LeaveRequest {
        $request = $em->getRepository(LeaveRequest::class)->findOneBy([
            'employee' => $employee,
            'type' => $type,
            'startDate' => new \DateTimeImmutable($startDate),
        ]);
        self::assertInstanceOf(LeaveRequest::class, $request);

        return $request;
    }

    private function balanceForYear(EntityManagerInterface $em, Employee $employee, int $year): LeaveBalance
    {
        $balance = $em->getRepository(LeaveBalance::class)->findOneBy([
            'employee' => $employee,
            'year' => $year,
        ]);
        self::assertInstanceOf(LeaveBalance::class, $balance);

        return $balance;
    }

    private function assertPrimaryOutcomes(EntityManagerInterface $em): void
    {
        $anna = $this->employeeByName($em, 'Anna Becker');
        $felix = $this->employeeByName($em, 'Felix Wolf');
        $dilan = $this->employeeByName($em, 'Dilan Yilmaz');
        $unknown = $this->employeeByName($em, 'Uma Unknown');
        $joiner = $this->employeeByName($em, 'Jill Joiner');
        $leaver = $this->employeeByName($em, 'Lenny Leaver');
        $both = $this->employeeByName($em, 'Jamie Both');
        $part = $this->employeeByName($em, 'Pat Part');
        $sick = $this->employeeByName($em, 'Nina Sick');
        $enough = $this->employeeByName($em, 'Yara Yearly');
        $short = $this->employeeByName($em, 'Ned NoBalance');

        $annaLong = $this->requestByStart($em, $anna, LeaveType::VACATION, '2025-05-19');
        $annaShort = $this->requestByStart($em, $anna, LeaveType::VACATION, '2025-04-28');
        $felixFirst = $this->requestByStart($em, $felix, LeaveType::VACATION, '2025-05-26');
        $felixSecond = $this->requestByStart($em, $felix, LeaveType::VACATION, '2025-05-28');
        $unknownRequest = $this->requestByStart($em, $unknown, LeaveType::VACATION, '2025-01-01');
        $joinerRequest = $this->requestByStart($em, $joiner, LeaveType::VACATION, '2025-07-08');
        $leaverRequest = $this->requestByStart($em, $leaver, LeaveType::VACATION, '2025-05-06');
        $bothRequest = $this->requestByStart($em, $both, LeaveType::VACATION, '2025-08-01');
        $partRequest = $this->requestByStart($em, $part, LeaveType::VACATION, '2025-05-07');
        $sickRequest = $this->requestByStart($em, $sick, LeaveType::SICK, '2025-03-12');
        $crossEnough = $this->requestByStart($em, $enough, LeaveType::VACATION, '2025-12-29');
        $crossShort = $this->requestByStart($em, $short, LeaveType::VACATION, '2025-12-29');

        self::assertSame(LeaveStatus::REJECTED, $annaLong->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $annaShort->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $felixFirst->getStatus());
        self::assertSame(LeaveStatus::REJECTED, $felixSecond->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $unknownRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $joinerRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $leaverRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $bothRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $partRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $sickRequest->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $crossEnough->getStatus());
        self::assertSame(LeaveStatus::REJECTED, $crossShort->getStatus());

        self::assertSame(7.0, $this->balanceForYear($em, $dilan, 2025)->getUsedDays());
        self::assertSame(1.0, $this->balanceForYear($em, $unknown, 2025)->getUsedDays());
        self::assertSame(22.5, $this->balanceForYear($em, $joiner, 2025)->getUsedDays());
        self::assertSame(15.0, $this->balanceForYear($em, $leaver, 2025)->getUsedDays());
        self::assertSame(17.5, $this->balanceForYear($em, $both, 2025)->getUsedDays());
        self::assertSame(17.0, $this->balanceForYear($em, $part, 2025)->getUsedDays());
        self::assertSame(5.0, $this->balanceForYear($em, $sick, 2025)->getUsedDays());
        self::assertSame(27.0, $this->balanceForYear($em, $short, 2025)->getUsedDays());
        self::assertSame(27.0, $this->balanceForYear($em, $short, 2026)->getUsedDays());
        self::assertSame(27.0, $this->balanceForYear($em, $enough, 2025)->getUsedDays());
        self::assertSame(2.0, $this->balanceForYear($em, $enough, 2026)->getUsedDays());
    }

    private function assertHrState(int $expectedDecisions): string
    {
        $stateFile = $this->stateFilePath();
        self::assertFileExists($stateFile);

        $payload = json_decode((string) file_get_contents($stateFile), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('decisions', $payload);
        self::assertCount($expectedDecisions, $payload['decisions']);

        return $stateFile;
    }

    private function startHrServer(): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = sprintf(
            'php -S 127.0.0.1:%d mock-hr-api/server.php',
            $this->hrPort,
        );

        $handle = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 2));
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Failed to start mock HR API server.');
        }

        $this->serverHandle = $handle;
        $this->serverPipes = $pipes;

        $this->waitForServer();
    }

    private function waitForServer(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->hrPort);
            if (false !== $socket) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }

        throw new \RuntimeException('Mock HR API server did not start in time.');
    }

    private function stopHrServer(): void
    {
        if (null === $this->serverHandle) {
            return;
        }

        foreach ($this->serverPipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($this->serverHandle);
        proc_close($this->serverHandle);

        $this->serverHandle = null;
        $this->serverPipes = [];
    }

    private function resetHrState(): void
    {
        $stateFile = $this->stateFilePath();
        if (file_exists($stateFile)) {
            @unlink($stateFile);
        }

        $client = HttpClient::create();
        $client->request('POST', $this->hrBaseUrl.'/v1/_reset', [
            'headers' => ['Authorization' => 'Bearer demo-secret-token-7Qx2'],
        ])->getStatusCode();
    }

    private function configureHrEndpoint(): void
    {
        $baseUrl = self::getContainer()->getParameter('hr_api.base_url');
        $this->hrBaseUrl = is_string($baseUrl) ? $baseUrl : 'http://127.0.0.1:18081';

        $parts = parse_url($this->hrBaseUrl);
        $this->hrPort = isset($parts['port']) ? (int) $parts['port'] : 80;
    }

    private function stateFilePath(): string
    {
        return dirname(__DIR__, 2).'/mock-hr-api/state.json';
    }
}
