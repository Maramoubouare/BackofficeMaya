<?php
// src/Service/AvailabilityManager.php

namespace App\Service;

use App\Entity\Trip;
use App\Entity\TripAvailability;
use App\Entity\Reservation;
use App\Repository\TripAvailabilityRepository;
use Doctrine\ORM\EntityManagerInterface;

class AvailabilityManager
{
    private TripAvailabilityRepository $availabilityRepo;
    private EntityManagerInterface $em;

    public function __construct(
        TripAvailabilityRepository $availabilityRepo,
        EntityManagerInterface $em
    ) {
        $this->availabilityRepo = $availabilityRepo;
        $this->em = $em;
    }

    /**
     * Réserve des places pour un trajet à une date donnée
     */
    public function reserveSeats(Trip $trip, \DateTimeInterface $travelDate, int $numSeats): bool
    {
        $availability = $this->availabilityRepo->findOrCreateAvailability($trip, $travelDate);

        if ($availability->reserveSeats($numSeats)) {
            $this->em->flush();
            return true;
        }

        return false;
    }

    /**
     * Libère des places (annulation de réservation)
     */
    public function releaseSeats(Trip $trip, \DateTimeInterface $travelDate, int $numSeats): void
    {
        $availability = $this->availabilityRepo->findOrCreateAvailability($trip, $travelDate);
        $availability->releaseSeats($numSeats);
        $this->em->flush();
    }

    /**
     * Vérifie si un trajet a assez de places pour une date
     */
    public function hasAvailableSeats(Trip $trip, \DateTimeInterface $travelDate, int $numSeats): bool
    {
        $availability = $this->availabilityRepo->findOrCreateAvailability($trip, $travelDate);
        return $availability->hasAvailableSeats($numSeats);
    }

    /**
     * Récupère le nombre de places disponibles
     */
    public function getAvailableSeats(Trip $trip, \DateTimeInterface $travelDate): int
    {
        $availability = $this->availabilityRepo->findOrCreateAvailability($trip, $travelDate);
        return $availability->getAvailableSeats();
    }

    /**
     * Génère les disponibilités pour un trajet sur les 90 prochains jours
     */
    public function generateAvailabilities(Trip $trip, int $days = 90): void
    {
        $daysOfWeek = $trip->getDaysOfWeek(); // [1, 3, 5] = Lun, Mer, Ven
        
        $startDate = new \DateTime();
        $endDate = (clone $startDate)->modify("+{$days} days");
        
        $currentDate = clone $startDate;
        
        while ($currentDate <= $endDate) {
            $dayOfWeek = (int) $currentDate->format('N'); // 1=Lun, 7=Dim
            
            // Si ce jour fait partie des jours de circulation du trajet
            if (in_array($dayOfWeek, $daysOfWeek)) {
                $this->availabilityRepo->findOrCreateAvailability($trip, clone $currentDate);
            }
            
            $currentDate->modify('+1 day');
        }
    }

    /**
     * Met à jour les disponibilités après modification d'un trajet
     */
    public function updateAvailabilitiesForTrip(Trip $trip): void
    {
        // Régénère les disponibilités futures
        $this->generateAvailabilities($trip, 90);
    }

    /**
     * Récupère les statistiques de remplissage pour un trajet
     */
    public function getTripOccupancyStats(Trip $trip, int $days = 30): array
    {
        $startDate = new \DateTime();
        $endDate = (clone $startDate)->modify("+{$days} days");
        
        $availabilities = $this->availabilityRepo->findAvailabilitiesForPeriod($trip, $startDate, $endDate);
        
        $totalSeats = 0;
        $reservedSeats = 0;
        
        foreach ($availabilities as $availability) {
            $totalSeats += $availability->getTotalSeats();
            $reservedSeats += $availability->getReservedSeats();
        }
        
        $occupancyRate = $totalSeats > 0 ? ($reservedSeats / $totalSeats) * 100 : 0;
        
        return [
            'total_seats' => $totalSeats,
            'reserved_seats' => $reservedSeats,
            'available_seats' => $totalSeats - $reservedSeats,
            'occupancy_rate' => round($occupancyRate, 2),
            'days_analyzed' => count($availabilities),
        ];
    }
}