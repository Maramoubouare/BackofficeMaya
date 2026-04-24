<?php

namespace App\Repository;

use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function findByPhone(string $phone): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.passengerPhone = :phone')
            ->setParameter('phone', $phone)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByReservationId(string $reservationId): ?Reservation
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.reservationId = :id')
            ->setParameter('id', $reservationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByCompany(int $companyId): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.company = :company')
            ->setParameter('company', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalRevenue(?int $companyId = null): float
    {
        $qb = $this->createQueryBuilder('r')
            ->select('SUM(r.totalPrice)')
            ->andWhere('r.paymentStatus = :status')
            ->setParameter('status', 'confirmed');

        if ($companyId) {
            $qb->andWhere('r.company = :company')
               ->setParameter('company', $companyId);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }
}