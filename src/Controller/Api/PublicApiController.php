<?php

namespace App\Controller\Api;

use App\Entity\Reservation;
use App\Entity\TripAvailability;
use App\Repository\TripRepository;
use App\Repository\TripAvailabilityRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/public', name: 'api_public_')]
class PublicApiController extends AbstractController
{
    #[Route('', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'ok', 'service' => 'Maya API']);
    }

    /**
     * GET /api/public/trips
     */
    #[Route('/trips', name: 'trips', methods: ['GET'])]
    public function getTrips(TripRepository $tripRepo): JsonResponse
    {
        // ✅ FILTRER : trajets actifs ET compagnies actives
        $qb = $tripRepo->createQueryBuilder('t')
            ->leftJoin('t.company', 'c')
            ->where('t.isActive = true')
            ->andWhere('c.isActive = true')  // ✅ AJOUTÉ
            ->orderBy('t.departureTime', 'ASC');
        
        $trips = $qb->getQuery()->getResult();
        
        $data = [];
        foreach ($trips as $trip) {
            $data[] = [
                'id' => $trip->getId(),
                'company_id' => $trip->getCompany()->getId(),
                'company_name' => $trip->getCompany()->getCompanyName(),
                'departure_city' => $trip->getDepartureCity(),
                'arrival_city' => $trip->getArrivalCity(),
                'departure_time' => $trip->getDepartureTime()->format('H:i'),
                'arrival_time' => $trip->getArrivalTime()->format('H:i'),
                'duration' => $trip->getDuration(),
                'price' => $trip->getPrice(),
                'total_seats' => $trip->getTotalSeats(),
                'available_seats' => $trip->getAvailableSeats(),
            ];
        }
        
        return $this->json([
            'success' => true,
            'count' => count($data),
            'data' => $data
        ]);
    }

    /**
     * GET /api/public/availabilities?date=2026-02-05
     */
    #[Route('/availabilities', name: 'availabilities', methods: ['GET'])]
    public function getAvailabilities(
        Request $request,
        TripAvailabilityRepository $availabilityRepo,
        TripRepository $tripRepo
    ): JsonResponse {
        $dateStr = $request->query->get('date');
        
        if (!$dateStr) {
            return $this->json([
                'success' => false,
                'message' => 'Le paramètre "date" est requis (format: YYYY-MM-DD)'
            ], 400);
        }
        
        try {
            $date = new \DateTime($dateStr);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Format de date invalide. Utilisez YYYY-MM-DD'
            ], 400);
        }
        
        $availabilities = $availabilityRepo->findBy(['travelDate' => $date]);
        
        $data = [];
        
        if (empty($availabilities)) {
            // ✅ FILTRER : compagnies actives uniquement
            $qb = $tripRepo->createQueryBuilder('t')
                ->leftJoin('t.company', 'c')
                ->where('t.isActive = true')
                ->andWhere('c.isActive = true')  // ✅ AJOUTÉ
                ->orderBy('t.departureTime', 'ASC');
            
            $trips = $qb->getQuery()->getResult();
            
            foreach ($trips as $trip) {
                $data[] = [
                    'trip_id' => $trip->getId(),
                    'company_name' => $trip->getCompany()->getCompanyName(),
                    'departure_city' => $trip->getDepartureCity(),
                    'arrival_city' => $trip->getArrivalCity(),
                    'departure_time' => $trip->getDepartureTime()->format('H:i'),
                    'arrival_time' => $trip->getArrivalTime()->format('H:i'),
                    'travel_date' => $date->format('Y-m-d'),
                    'price' => $trip->getPrice(),
                    'total_seats' => $trip->getTotalSeats(),
                    'available_seats' => $trip->getAvailableSeats(),
                    'reserved_seats' => 0,
                ];
            }
        } else {
            foreach ($availabilities as $availability) {
                $trip = $availability->getTrip();

                if (!$trip->isActive() || !$trip->getCompany()->isActive()) {
                    continue;
                }
                
                $data[] = [
                    'availability_id' => $availability->getId(),
                    'trip_id' => $trip->getId(),
                    'company_name' => $trip->getCompany()->getCompanyName(),
                    'departure_city' => $trip->getDepartureCity(),
                    'arrival_city' => $trip->getArrivalCity(),
                    'departure_time' => $trip->getDepartureTime()->format('H:i'),
                    'arrival_time' => $trip->getArrivalTime()->format('H:i'),
                    'travel_date' => $availability->getTravelDate()->format('Y-m-d'),
                    'price' => $trip->getPrice(),
                    'total_seats' => $availability->getTotalSeats(),
                    'available_seats' => $availability->getAvailableSeats(),
                    'reserved_seats' => $availability->getReservedSeats(),
                ];
            }
        }
        
        return $this->json([
            'success' => true,
            'date' => $date->format('Y-m-d'),
            'count' => count($data),
            'data' => $data
        ]);
    }

    /**
     * POST /api/public/reservations
     */
    #[Route('/reservations', name: 'create_reservation', methods: ['POST'])]
    public function createReservation(
        Request $request,
        EntityManagerInterface $em,
        TripRepository $tripRepo,
        TripAvailabilityRepository $availabilityRepo
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        
        // Validation
        if (!isset($data['trip_id'], $data['travel_date'], $data['first_name'], $data['last_name'], $data['phone'], $data['num_passengers'])) {
            return $this->json([
                'success' => false,
                'message' => 'Données manquantes. Requis: trip_id, travel_date, first_name, last_name, phone, num_passengers'
            ], 400);
        }
        
        $numPassengers = (int) $data['num_passengers'];
        if ($numPassengers < 1 || $numPassengers > 10) {
            return $this->json([
                'success' => false,
                'message' => 'Le nombre de passagers doit être entre 1 et 10'
            ], 400);
        }
        
        if (!preg_match('/^[0-9]{8,15}$/', $data['phone'])) {
            return $this->json([
                'success' => false,
                'message' => 'Numéro de téléphone invalide'
            ], 400);
        }
        
        $trip = $tripRepo->find($data['trip_id']);
        if (!$trip) {
            return $this->json([
                'success' => false,
                'message' => 'Trajet non trouvé'
            ], 404);
        }
        
        // ✅ VÉRIFIER SI LA COMPAGNIE EST ACTIVE
        if (!$trip->getCompany()->isActive()) {
            return $this->json([
                'success' => false,
                'message' => 'Cette compagnie n\'accepte plus de réservations pour le moment.'
            ], 403);
        }
        
        // ✅ VÉRIFIER SI LE TRAJET EST ACTIF
        if (!$trip->isActive()) {
            return $this->json([
                'success' => false,
                'message' => 'Ce trajet n\'est plus disponible.'
            ], 403);
        }
        
        try {
            $travelDate = new \DateTime($data['travel_date']);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Format de date invalide. Utilisez YYYY-MM-DD'
            ], 400);
        }
        
        // Vérifier availability
        $availability = $availabilityRepo->findOneBy([
            'trip' => $trip,
            'travelDate' => $travelDate
        ]);
        
        if ($availability) {
            if ($availability->getAvailableSeats() < $numPassengers) {
                return $this->json([
                    'success' => false,
                    'message' => 'Pas assez de places disponibles. Il reste seulement ' . $availability->getAvailableSeats() . ' places'
                ], 400);
            }
        } else {
            $availability = new TripAvailability();
            $availability->setTrip($trip);
            $availability->setTravelDate($travelDate);
            $availability->setTotalSeats($trip->getTotalSeats());
            $availability->setReservedSeats(0);
            $availability->setAvailableSeats($trip->getTotalSeats());
            $em->persist($availability);
        }
        
        // Générer ID de réservation
        $reservationId = 'RES' . date('YmdHis') . rand(100, 999);
        
        // Calculer montants
        $totalAmount = $trip->getPrice() * $numPassengers;
        $commissionRate = 2.5;
        $commissionAmount = $totalAmount * ($commissionRate / 100);
        $companyAmount = $totalAmount - $commissionAmount;
        
        // Créer réservation
        $reservation = new Reservation();
        $reservation->setReservationId($reservationId);
        $reservation->setTravelDate($travelDate);
        $reservation->setPassengerFirstName($data['first_name']);
        $reservation->setPassengerLastName($data['last_name']);
        $reservation->setPassengerPhone($data['phone']);
        $reservation->setNumPassengers($numPassengers);
        $reservation->setCompany($trip->getCompany());
        $reservation->setTrip($trip);
        $reservation->setStatus('pending');
        
        // ✅ UTILISER LES BONNES MÉTHODES
        if (method_exists($reservation, 'setTotalPrice')) {
            $reservation->setTotalPrice($totalAmount);
        } elseif (method_exists($reservation, 'setTotalAmount')) {
            $reservation->setTotalAmount($totalAmount);
        }
        
        if (method_exists($reservation, 'setCommissionPrice')) {
            $reservation->setCommissionPrice($commissionAmount);
        } elseif (method_exists($reservation, 'setCommissionAmount')) {
            $reservation->setCommissionAmount($commissionAmount);
        }
        
        // ✅ AJOUTER COMPANY_AMOUNT
        if (method_exists($reservation, 'setCompanyPrice')) {
            $reservation->setCompanyPrice($companyAmount);
        } elseif (method_exists($reservation, 'setCompanyAmount')) {
            $reservation->setCompanyAmount($companyAmount);
        }
        
        // Mettre à jour availability
        $availability->setReservedSeats($availability->getReservedSeats() + $numPassengers);
        $availability->setAvailableSeats($availability->getAvailableSeats() - $numPassengers);
        
        $em->persist($reservation);
        $em->flush();
        
        return $this->json([
            'success' => true,
            'message' => 'Réservation créée avec succès',
            'data' => [
                'reservation_id' => $reservation->getReservationId(),
                'trip_id' => $trip->getId(),
                'travel_date' => $travelDate->format('Y-m-d'),
                'passenger_name' => $reservation->getPassengerFirstName() . ' ' . $reservation->getPassengerLastName(),
                'phone' => $reservation->getPassengerPhone(),
                'num_passengers' => $numPassengers,
                'total_amount' => $totalAmount,
                'commission_amount' => $commissionAmount,
                'company_amount' => $companyAmount,
                'status' => 'pending'
            ]
        ], 201);
    }

    /**
     * GET /api/public/reservation/{reservationId}
     */
    #[Route('/reservation/{reservationId}', name: 'get_reservation', methods: ['GET'])]
    public function getReservation(
        string $reservationId,
        ReservationRepository $reservationRepo
    ): JsonResponse {
        $reservation = $reservationRepo->findOneBy(['reservationId' => $reservationId]);
        
        if (!$reservation) {
            return $this->json([
                'success' => false,
                'message' => 'Réservation non trouvée'
            ], 404);
        }
        
        // Récupérer le montant avec les bonnes méthodes
        $totalAmount = null;
        if (method_exists($reservation, 'getTotalPrice')) {
            $totalAmount = $reservation->getTotalPrice();
        } elseif (method_exists($reservation, 'getTotalAmount')) {
            $totalAmount = $reservation->getTotalAmount();
        }
        
        $commissionAmount = null;
        if (method_exists($reservation, 'getCommissionPrice')) {
            $commissionAmount = $reservation->getCommissionPrice();
        } elseif (method_exists($reservation, 'getCommissionAmount')) {
            $commissionAmount = $reservation->getCommissionAmount();
        }
        
        return $this->json([
            'success' => true,
            'data' => [
                'reservation_id' => $reservation->getReservationId(),
                'company_name' => $reservation->getCompany()->getCompanyName(),
                'departure_city' => $reservation->getTrip()->getDepartureCity(),
                'arrival_city' => $reservation->getTrip()->getArrivalCity(),
                'travel_date' => $reservation->getTravelDate()->format('d/m/Y'),
                'passenger_first_name' => $reservation->getPassengerFirstName(),
                'passenger_last_name' => $reservation->getPassengerLastName(),
                'passenger_phone' => $reservation->getPassengerPhone(),
                'num_passengers' => $reservation->getNumPassengers(),
                'total_amount' => $totalAmount,
                'commission_amount' => $commissionAmount,
                'status' => $reservation->getStatus(),
                'created_at' => $reservation->getCreatedAt()->format('d/m/Y H:i'),
            ]
        ]);
    }
}