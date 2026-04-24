<?php
// src/Repository/TripAvailabilityRepository.php

namespace App\Repository;

use App\Entity\TripAvailability;
use App\Entity\Trip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TripAvailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripAvailability::class);
    }

    /**
     * Trouve ou crée une disponibilité pour un trajet et une date
     */
    public function findOrCreateAvailability(Trip $trip, \DateTimeInterface $travelDate): TripAvailability
    {
        $availability = $this->findOneBy([
            'trip' => $trip,
            'travelDate' => $travelDate,
        ]);

        if (!$availability) {
            $availability = new TripAvailability();
            $availability->setTrip($trip);
            $availability->setTravelDate($travelDate);
            $availability->setTotalSeats($trip->getTotalSeats());
            $availability->setAvailableSeats($trip->getAvailableSeats());
            
            $this->getEntityManager()->persist($availability);
            $this->getEntityManager()->flush();
        }

        return $availability;
    }

    /**
     * Récupère les disponibilités pour un trajet sur une période
     */
    public function findAvailabilitiesForPeriod(Trip $trip, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('ta')
            ->where('ta.trip = :trip')
            ->andWhere('ta.travelDate BETWEEN :start AND :end')
            ->setParameter('trip', $trip)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('ta.travelDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les trajets avec places disponibles pour une date
     */
    public function findAvailableTripsForDate(\DateTimeInterface $date, ?string $departureCity = null, ?string $arrivalCity = null): array
    {
        $qb = $this->createQueryBuilder('ta')
            ->join('ta.trip', 't')
            ->where('ta.travelDate = :date')
            ->andWhere('ta.availableSeats > 0')
            ->andWhere('t.isActive = true')
            ->setParameter('date', $date);

        if ($departureCity) {
            $qb->andWhere('t.departureCity = :departure')
               ->setParameter('departure', $departureCity);
        }

        if ($arrivalCity) {
            $qb->andWhere('t.arrivalCity = :arrival')
               ->setParameter('arrival', $arrivalCity);
        }

        return $qb->orderBy('t.departureTime', 'ASC')
                  ->getQuery()
                  ->getResult();
    }

    /**
     * Nettoie les anciennes disponibilités (plus de 6 mois)
     */
    public function cleanOldAvailabilities(): int
    {
        $sixMonthsAgo = new \DateTime('-6 months');
        
        return $this->createQueryBuilder('ta')
            ->delete()
            ->where('ta.travelDate < :date')
            ->setParameter('date', $sixMonthsAgo)
            ->getQuery()
            ->execute();
    }
}