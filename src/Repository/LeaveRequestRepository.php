<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequest>
 */
class LeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequest::class);
    }

    /**
     * All requests still awaiting a decision, oldest submission first.
     *
     * @return list<LeaveRequest>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->setParameter('status', LeaveStatus::PENDING)
            ->orderBy('r.submittedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved vacation requests for a specific employee.
     *
     * @return list<LeaveRequest>
     */
    public function findApprovedVacationsForEmployee(int $employeeId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->andWhere('r.type = :type')
            ->andWhere('r.employee = :employee')
            ->setParameter('status', LeaveStatus::APPROVED)
            ->setParameter('type', LeaveType::VACATION)
            ->setParameter('employee', $employeeId)
            ->orderBy('r.startDate', 'ASC')
            ->addOrderBy('r.endDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
