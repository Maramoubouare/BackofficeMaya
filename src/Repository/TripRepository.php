<?php

namespace App\Repository;

use App\Entity\Trip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    public function findActiveTrips(?int $companyId = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('t.departureTime', 'ASC');

        if ($companyId) {
            $qb->andWhere('t.company = :company')
               ->setParameter('company', $companyId);
        }

        return $qb->getQuery()->getResult();
    }

    public function searchTrips(string $from, string $to, ?\DateTime $date = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.departureCity = :from')
            ->andWhere('t.arrivalCity = :to')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.availableSeats > 0')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('active', true)
            ->orderBy('t.departureTime', 'ASC');

        // Filtrer par jour de la semaine si date fournie
        if ($date) {
            $dayOfWeek = (int) $date->format('N'); // 1=Lundi, 7=Dimanche
            $qb->andWhere('JSON_CONTAINS(t.daysOfWeek, :day) = 1')
               ->setParameter('day', json_encode($dayOfWeek));
        }

        return $qb->getQuery()->getResult();
    }
}