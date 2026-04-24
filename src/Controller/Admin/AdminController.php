<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Trip;
use App\Entity\Reservation;
use App\Entity\Message;
use App\Repository\SettingsRepository;
use App\Entity\Settings;
use App\Repository\TripRepository;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use App\Repository\TripAvailabilityRepository;
use App\Repository\TransactionRepository;
use App\Repository\MessageRepository;
use App\Service\AvailabilityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/', name: 'admin_dashboard')]
    public function dashboard(
        TripRepository $tripRepo,
        ReservationRepository $reservationRepo,
        UserRepository $userRepo,
        TripAvailabilityRepository $availabilityRepo,
        Request $request
    ): Response {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        
        $filterDate = $request->query->get('date');
        if ($filterDate) {
            try {
                $selectedDate = new \DateTime($filterDate);
            } catch (\Exception $e) {
                $selectedDate = $today;
            }
        } else {
            $selectedDate = $today;
        }
        
        $filterCity = $request->query->get('city');
        $filterSearch = $request->query->get('search');
        $filterTripId = $request->query->get('trip_id');
        
        // ========== STATS CARDS - CALCULS CORRECTS ==========

        // Card 1 : Réservations CRÉÉES aujourd'hui (date de création)
        $qb1 = $reservationRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt >= :today')
            ->andWhere('r.createdAt < :tomorrow')
            ->andWhere('r.status = :status')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('status', 'confirmed');
        if ($filterTripId) {
            $qb1->andWhere('r.trip = :tripId')->setParameter('tripId', $filterTripId);
        }
        $reservationsToday = $qb1->getQuery()->getSingleScalarResult() ?? 0;

        // Card 2 : Places disponibles
        if ($filterTripId) {
            $selectedTripEntity = $tripRepo->find($filterTripId);
            $totalCapacity = $selectedTripEntity ? $selectedTripEntity->getTotalSeats() : 0;
            $seatsReservedForDate = $availabilityRepo->createQueryBuilder('ta')
                ->select('SUM(ta.reservedSeats)')
                ->where('ta.travelDate = :selected')
                ->andWhere('ta.trip = :tripId')
                ->setParameter('selected', $selectedDate->format('Y-m-d'))
                ->setParameter('tripId', $filterTripId)
                ->getQuery()->getSingleScalarResult() ?? 0;
        } else {
            $totalCapacity = $tripRepo->createQueryBuilder('t')
                ->select('SUM(t.totalSeats)')
                ->where('t.isActive = true')
                ->getQuery()->getSingleScalarResult() ?? 0;
            $seatsReservedForDate = $availabilityRepo->createQueryBuilder('ta')
                ->select('SUM(ta.reservedSeats)')
                ->where('ta.travelDate = :selected')
                ->setParameter('selected', $selectedDate->format('Y-m-d'))
                ->getQuery()->getSingleScalarResult() ?? 0;
        }
        $seatsAvailableForDate = $totalCapacity - $seatsReservedForDate;

        // Card 3 : Nombre de départs COMPLETS pour la date sélectionnée
        $qb3 = $availabilityRepo->createQueryBuilder('ta')
            ->select('COUNT(ta.id)')
            ->where('ta.travelDate = :selected')
            ->andWhere('ta.availableSeats = 0')
            ->setParameter('selected', $selectedDate->format('Y-m-d'));
        if ($filterTripId) {
            $qb3->andWhere('ta.trip = :tripId')->setParameter('tripId', $filterTripId);
        }
        $completedTripsForDate = $qb3->getQuery()->getSingleScalarResult() ?? 0;

        // Card 4 : Nombre de trajets (routes) configurés
        $totalRoutes = $filterTripId ? 1 : $tripRepo->count(['isActive' => true]);
        
        // ========== TABLE DÉPARTS POUR LA DATE SÉLECTIONNÉE ==========
        // Récupérer TOUS les trips actifs d'abord
        $tripsQb = $tripRepo->createQueryBuilder('t')
            ->leftJoin('t.company', 'c')
            ->where('t.isActive = true');

        if ($filterTripId) {
            $tripsQb->andWhere('t.id = :tripId')
                    ->setParameter('tripId', $filterTripId);
        }

        if ($filterCity) {
            $tripsQb->andWhere('t.departureCity = :city OR t.arrivalCity = :city')
                    ->setParameter('city', $filterCity);
        }

        if ($filterSearch) {
            $tripsQb->andWhere(
                't.departureCity LIKE :search OR t.arrivalCity LIKE :search OR c.companyName LIKE :search'
            )->setParameter('search', '%' . $filterSearch . '%');
        }

        $tripsQb->orderBy('t.departureTime', 'ASC');
        $allTrips = $tripsQb->getQuery()->getResult();

        $departures = [];
        foreach ($allTrips as $trip) {
            // Chercher si une TripAvailability existe pour ce trip et cette date
            $availability = $availabilityRepo->findOneBy([
                'trip' => $trip,
                'travelDate' => $selectedDate
            ]);

            $departure = new \stdClass();
            $departure->id = $trip->getId();
            $departure->trip = $trip;
            $departure->company = $trip->getCompany();
            $departure->departureCity = $trip->getDepartureCity();
            $departure->arrivalCity = $trip->getArrivalCity();
            $departure->departureTime = $trip->getDepartureTime();
            $departure->arrivalTime = $trip->getArrivalTime();
            $departure->departureDate = $selectedDate;

            if ($availability) {
                // ✅ Données RÉELLES depuis TripAvailability
                $departure->seatsTotal = $availability->getTotalSeats();
                $departure->seatsSold = $availability->getReservedSeats();
                $departure->seatsAvailable = $availability->getAvailableSeats();
            } else {
                // ✅ Données par défaut depuis Trip (aucune réservation encore)
                $departure->seatsTotal = $trip->getTotalSeats();
                $departure->seatsSold = 0;
                $departure->seatsAvailable = $trip->getTotalSeats();
            }

            $departure->status = $departure->seatsAvailable == 0 ? 'complet' : 'actif';

            $departures[] = $departure;
        }
        
        // ========== STATS TRAJETS DU JOUR ==========

        // Tous les trajets actifs pour le filtre
        $allActiveTrips = $tripRepo->findBy(['isActive' => true], ['departureCity' => 'ASC']);

        // Calculer stats par trajet pour la date sélectionnée
        $tripStatsToday = [];
        foreach ($allActiveTrips as $trip) {
            $availability = $availabilityRepo->findOneBy([
                'trip' => $trip,
                'travelDate' => $selectedDate
            ]);

            $seatsSold      = $availability ? $availability->getReservedSeats() : 0;
            $seatsAvailable = $availability ? $availability->getAvailableSeats() : $trip->getTotalSeats();
            $seatsTotal     = $availability ? $availability->getTotalSeats() : $trip->getTotalSeats();

            // Réservations actives du jour pour ce trajet (non annulées)
            $dayReservations = $reservationRepo->createQueryBuilder('r')
                ->where('r.trip = :trip')
                ->andWhere('r.travelDate = :date')
                ->andWhere('r.status != :cancelled')
                ->setParameter('trip', $trip)
                ->setParameter('date', $selectedDate)
                ->setParameter('cancelled', 'cancelled')
                ->getQuery()->getResult();

            $dayRevenue = 0;
            $dayCount   = 0;
            foreach ($dayReservations as $res) {
                $dayRevenue += $res->getTotalPrice() ?? 0;
                $dayCount   += $res->getNumPassengers();
            }

            $tripStatsToday[] = [
                'trip'           => $trip,
                'label'          => $trip->getDepartureCity() . ' → ' . $trip->getArrivalCity() . ' (' . $trip->getDepartureTime()->format('H:i') . ')',
                'seats_total'    => $seatsTotal,
                'seats_sold'     => $seatsSold,
                'seats_available'=> $seatsAvailable,
                'reservations'   => count($dayReservations),
                'passengers'     => $dayCount,
                'revenue'        => $dayRevenue,
                'fill_rate'      => $seatsTotal > 0 ? round($seatsSold / $seatsTotal * 100) : 0,
            ];
        }

        // Trajet sélectionné pour les détails
        $selectedTrip = null;
        $selectedTripStats = null;
        if ($filterTripId) {
            foreach ($tripStatsToday as $ts) {
                if ($ts['trip']->getId() == $filterTripId) {
                    $selectedTripStats = $ts;
                    $selectedTrip = $ts['trip'];
                    break;
                }
            }
        }

        // Compagnies (gardé pour compatibilité mais non utilisé dans le template)
        $companyStats = [];
        
        // ========== STATS GLOBALES ==========

        $qbGlobal = $reservationRepo->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', 'confirmed');
        if ($filterTripId) {
            $qbGlobal->andWhere('r.trip = :tripId')->setParameter('tripId', $filterTripId);
        }
        $totalReservations = (clone $qbGlobal)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult() ?? 0;
        $totalSeats = (clone $qbGlobal)->select('SUM(r.numPassengers)')->getQuery()->getSingleScalarResult() ?? 0;

        if ($filterTripId) {
            $tripCapacity = isset($selectedTripEntity) ? $selectedTripEntity->getTotalSeats() : 50;
            $averageRate = $tripCapacity > 0 ? round(($totalSeats / $tripCapacity) * 100) : 0;
        } else {
            $totalCapacityGlobal = $totalRoutes * 50;
            $averageRate = $totalCapacityGlobal > 0 ? round(($totalSeats / $totalCapacityGlobal) * 100) : 0;
        }
        
        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'reservations_today' => $reservationsToday,
                'seats_available' => $seatsAvailableForDate,
                'tickets_completed' => $completedTripsForDate,
                'active_departures' => $totalRoutes,
                'active_departures_today' => count($departures),
                'total_routes' => $totalRoutes,
            ],
            'departures' => $departures,
            'company_stats' => array_slice($companyStats, 0, 3),
            'global_stats' => [
                'total_departures' => $totalRoutes,
                'total_reservations' => $totalReservations,
                'total_seats_sold' => $totalSeats,
                'average_rate' => $averageRate
            ],
            'selected_date'      => $selectedDate->format('Y-m-d'),
            'today_date'         => $today->format('Y-m-d'),
            'filter_city'        => $filterCity,
            'filter_search'      => $filterSearch,
            'filter_trip_id'     => $filterTripId,
            'all_active_trips'   => $allActiveTrips,
            'trip_stats_today'   => $tripStatsToday,
            'selected_trip_stats'=> $selectedTripStats,
        ]);
    }

    #[Route('/billets', name: 'admin_billets')]
    public function billets(
        Request $request,
        ReservationRepository $reservationRepo,
        TripRepository $tripRepo,
        TripAvailabilityRepository $availabilityRepo
    ): Response {
        // Filtres
        $search = $request->query->get('search', '');
        $date = $request->query->get('date', '');
        $tripId = $request->query->get('trip_id', '');
        $status = $request->query->get('status', '');
        
        // Query builder pour les réservations
        $qb = $reservationRepo->createQueryBuilder('r')
            ->leftJoin('r.trip', 't')
            ->leftJoin('r.company', 'c');
        
        // Appliquer les filtres
        if ($search) {
            $qb->andWhere('r.reservationId LIKE :search OR r.passengerFirstName LIKE :search OR r.passengerLastName LIKE :search OR r.passengerPhone LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        if ($date) {
            $qb->andWhere('r.travelDate = :date')
               ->setParameter('date', new \DateTime($date));
        }
        
        if ($tripId) {
            $qb->andWhere('r.trip = :tripId')
               ->setParameter('tripId', $tripId);
        }
        
        if ($status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }
        
        $qb->orderBy('r.createdAt', 'DESC');
        $reservations = $qb->getQuery()->getResult();
        
        // Liste des trajets pour le filtre
        $trips = $tripRepo->findBy(['isActive' => true], ['departureCity' => 'ASC']);
        
        // ========== STATS CORRIGÉES ==========
        
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        
        // Stat 1 : Réservations créées aujourd'hui
        $ticketsToday = $reservationRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt >= :today')
            ->andWhere('r.createdAt < :tomorrow')
            ->andWhere('r.status = :status')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('status', 'confirmed')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
        
        // Stat 2 : Places disponibles AUJOURD'HUI uniquement
        // Capacité totale de tous les trips actifs
        $totalCapacity = $tripRepo->createQueryBuilder('t')
            ->select('SUM(t.totalSeats)')
            ->where('t.isActive = true')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Places réservées AUJOURD'HUI seulement
        $seatsReservedToday = $availabilityRepo->createQueryBuilder('ta')
            ->select('SUM(ta.reservedSeats)')
            ->where('ta.travelDate = :today')
            ->setParameter('today', $today->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Places disponibles aujourd'hui = Capacité - Réservations d'aujourd'hui
        $seatsAvailable = $totalCapacity - $seatsReservedToday;
        
        // Stat 3 : Trajets COMPLETS (available_seats = 0)
        $ticketsCompleted = $availabilityRepo->createQueryBuilder('ta')
            ->select('COUNT(ta.id)')
            ->where('ta.availableSeats = 0')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
        
        // ========== FIN STATS ==========
        
        return $this->render('admin/billets.html.twig', [
            'reservations' => $reservations,
            'trips' => $trips,
            'search' => $search,
            'date' => $date,
            'trip_id' => $tripId,
            'status' => $status,
            'stats' => [
                'tickets_today' => $ticketsToday,
                'seats_available' => $seatsAvailable,
                'tickets_completed' => $ticketsCompleted
            ]
        ]);
    }

    #[Route('/billets/{id}/edit', name: 'admin_billet_edit', methods: ['POST'])]
    public function editBillet(
        int $id,
        Request $request,
        ReservationRepository $reservationRepo,
        TripAvailabilityRepository $availabilityRepo,
        AvailabilityManager $availabilityManager,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        
        $reservation = $reservationRepo->find($id);
        
        if (!$reservation) {
            return $this->json(['success' => false, 'message' => 'Réservation introuvable']);
        }
        
        $oldDate = $reservation->getTravelDate();
        $oldNumPassengers = $reservation->getNumPassengers();
        
        $newDate = new \DateTime($data['travel_date']);
        $newNumPassengers = (int) $data['num_passengers'];
        
        // Si la date ou le nombre de passagers a changé, mettre à jour les disponibilités
        if ($oldDate->format('Y-m-d') !== $newDate->format('Y-m-d') || $oldNumPassengers !== $newNumPassengers) {
            // Libérer les places de l'ancienne date
            $availabilityManager->releaseSeats(
                $reservation->getTrip(),
                $oldDate,
                $oldNumPassengers
            );
            
            // Réserver les places pour la nouvelle date
            $availabilityManager->reserveSeats(
                $reservation->getTrip(),
                $newDate,
                $newNumPassengers
            );
        }
        
        // Mettre à jour la réservation
        $reservation->setPassengerFirstName($data['first_name']);
        $reservation->setPassengerLastName($data['last_name']);
        $reservation->setPassengerPhone($data['phone']);
        $reservation->setNumPassengers($newNumPassengers);
        $reservation->setTravelDate($newDate);
        
        // Recalculer le prix si le nombre de passagers a changé
        if ($oldNumPassengers !== $newNumPassengers) {
            $trip = $reservation->getTrip();
            $totalPrice = $trip->getPrice() * $newNumPassengers;
            $commissionAmount = $totalPrice * 0.025;
            $companyAmount = $totalPrice - $commissionAmount;
            
            if (method_exists($reservation, 'setTotalPrice')) {
                $reservation->setTotalPrice($totalPrice);
            }
            if (method_exists($reservation, 'setCommissionAmount')) {
                $reservation->setCommissionAmount($commissionAmount);
            }
            if (method_exists($reservation, 'setCompanyAmount')) {
                $reservation->setCompanyAmount($companyAmount);
            }
        }
        
        $em->flush();
        
        return $this->json(['success' => true, 'message' => 'Réservation modifiée']);
    }

    #[Route('/trajets', name: 'admin_trajets')]
    public function trajets(
        TripRepository $tripRepo,
        UserRepository $userRepo,
        Request $request
    ): Response {
        $qb = $tripRepo->createQueryBuilder('t')
            ->leftJoin('t.company', 'c')
            ->orderBy('t.departureTime', 'ASC');

        $search = $request->query->get('search');
        if ($search) {
            $qb->andWhere('t.departureCity LIKE :search OR t.arrivalCity LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $companyId = $request->query->get('company_id');
        if ($companyId) {
            $qb->andWhere('t.company = :company')
               ->setParameter('company', $companyId);
        }

        $isActive = $request->query->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $qb->andWhere('t.isActive = :active')
               ->setParameter('active', $isActive === '1');
        }

        $trips = $qb->getQuery()->getResult();

        $companies = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_COMPANY%')
            ->orderBy('u.companyName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/trajets.html.twig', [
            'trips' => $trips,
            'companies' => $companies,
            'search' => $search,
            'company_id' => $companyId,
            'is_active' => $isActive
        ]);
    }

   #[Route('/companies', name: 'admin_companies')]
#[Route('/companies', name: 'admin_companies')]
public function companies(
    UserRepository $userRepo,
    ReservationRepository $reservationRepo,
    TripRepository $tripRepo
): Response {
    $companies = $userRepo->createQueryBuilder('u')
        ->where('u.roles LIKE :role')
        ->setParameter('role', '%ROLE_COMPANY%')
        ->orderBy('u.companyName', 'ASC')
        ->getQuery()
        ->getResult();

    $companiesData = [];
    
    foreach ($companies as $company) {
        // Créer un objet stdClass pour chaque compagnie
        $companyData = new \stdClass();
        
        // Copier les données de base
        $companyData->id = $company->getId();
        $companyData->companyName = $company->getCompanyName();
        $companyData->firstName = $company->getFirstName();
        $companyData->lastName = $company->getLastName();
        $companyData->phone = $company->getPhone();
        $companyData->email = $company->getEmail();
        $companyData->isActive = $company->isActive(); 
   
        
        // Nombre de trajets
        $companyData->nbTrips = $tripRepo->count(['company' => $company]);
        
        // Chiffre d'affaires
        $reservations = $reservationRepo->findBy([
            'company' => $company,
            'status' => 'confirmed'
        ]);
        
        $revenue = 0;
        foreach ($reservations as $res) {
            $total = null;
            $commission = null;
            
            if (method_exists($res, 'getTotalPrice')) {
                $total = $res->getTotalPrice();
            } elseif (method_exists($res, 'getTotalAmount')) {
                $total = $res->getTotalAmount();
            }
            
            if (method_exists($res, 'getCommissionAmount')) {
                $commission = $res->getCommissionAmount();
            } elseif (method_exists($res, 'getCommissionPrice')) {
                $commission = $res->getCommissionPrice();
            }
            
            if ($total !== null) {
                $revenue += $total - ($commission ?? 0);
            }
        }
        
        $companyData->revenue = $revenue;
        
        // Commission rate
        if (method_exists($company, 'getCommissionRate')) {
            $companyData->commissionRate = $company->getCommissionRate() ?? '2.00';
        } else {
            $companyData->commissionRate = '2.00';
        }
        
        $companiesData[] = $companyData;
    }

    return $this->render('admin/companies.html.twig', [
        'companies' => $companiesData
    ]);
}
    #[Route('/users', name: 'admin_users')]
    public function users(
        UserRepository $userRepo,
        Request $request
    ): Response {
        $qb = $userRepo->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        $role = $request->query->get('role');
        if ($role) {
            $qb->andWhere('u.roles LIKE :role')
               ->setParameter('role', '%' . $role . '%');
        }

        $search = $request->query->get('search');
        if ($search) {
            $qb->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search OR u.companyName LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $users = $qb->getQuery()->getResult();

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'role' => $role,
            'search' => $search
        ]);
    }

   #[Route('/statistics', name: 'admin_statistics')]
public function statistics(
    Request $request,
    ReservationRepository $reservationRepo,
    UserRepository $userRepo,
    TripRepository $tripRepo
): Response {
    // FILTRES
    $companyId = $request->query->get('company_id');
    $tripId    = $request->query->get('trip_id');
    $period    = $request->query->get('period', 'month');
    $startDate = $request->query->get('start_date');
    $endDate   = $request->query->get('end_date');
    
    // Dates par défaut selon la période
    if (!$startDate || !$endDate) {
        $endDate = new \DateTime();
        
        switch ($period) {
            case 'day':
                $startDate = (new \DateTime())->modify('-7 days');
                break;
            case 'week':
                $startDate = (new \DateTime())->modify('-8 weeks');
                break;
            case 'year':
                $startDate = (new \DateTime())->modify('-5 years');
                break;
            case 'month':
            default:
                $startDate = (new \DateTime())->modify('-12 months');
                break;
        }
    } else {
        $startDate = new \DateTime($startDate);
        $endDate = new \DateTime($endDate);
    }
    
    // QUERY DE BASE
    $qb = $reservationRepo->createQueryBuilder('r')
        ->leftJoin('r.company', 'c')
        ->where('r.status = :status')
        ->andWhere('r.createdAt BETWEEN :start AND :end')
        ->setParameter('status', 'confirmed')
        ->setParameter('start', $startDate)
        ->setParameter('end', $endDate);

    if ($companyId) {
        $qb->andWhere('r.company = :company')->setParameter('company', $companyId);
    }
    if ($tripId) {
        $qb->andWhere('r.trip = :tripId')->setParameter('tripId', $tripId);
    }

    $reservations = $qb->getQuery()->getResult();
    
    // CALCUL STATS GLOBALES
    $totalRevenue = 0;
    $totalCommissions = 0;
    
    foreach ($reservations as $res) {
        $total = method_exists($res, 'getTotalPrice') ? $res->getTotalPrice() : 
                (method_exists($res, 'getTotalAmount') ? $res->getTotalAmount() : 0);
        $commission = method_exists($res, 'getCommissionAmount') ? $res->getCommissionAmount() : 
                     (method_exists($res, 'getCommissionPrice') ? $res->getCommissionPrice() : 0);
        
        if ($total) {
            $totalRevenue += $total;
            $totalCommissions += $commission ?? 0;
        }
    }
    
    $commissionRate = $totalRevenue > 0 ? ($totalCommissions / $totalRevenue) * 100 : 0;
    
    // CROISSANCE (comparer avec période précédente)
    $previousStart = (clone $startDate)->modify('-' . $startDate->diff($endDate)->days . ' days');
    $previousEnd = clone $startDate;
    
    $prevQb = $reservationRepo->createQueryBuilder('r')
        ->select('SUM(r.totalPrice) as total')
        ->where('r.status = :status')
        ->andWhere('r.createdAt BETWEEN :start AND :end')
        ->setParameter('status', 'confirmed')
        ->setParameter('start', $previousStart)
        ->setParameter('end', $previousEnd);
    if ($companyId) { $prevQb->andWhere('r.company = :company')->setParameter('company', $companyId); }
    if ($tripId)    { $prevQb->andWhere('r.trip = :tripId')->setParameter('tripId', $tripId); }
    $previousReservations = $prevQb->getQuery()->getSingleScalarResult() ?? 0;
    
    $growth = $previousReservations > 0 ? 
        (($totalRevenue - $previousReservations) / $previousReservations) * 100 : 0;
    
    // DONNÉES POUR GRAPHIQUES
    $chartData = [
        'labels' => [],
        'revenue' => [],
        'commissions' => [],
        'company_names' => [],
        'company_revenues' => []
    ];
    
    // GÉNÉRER LES LABELS SELON LA PÉRIODE
    $current = clone $startDate;
    $dataByPeriod = [];
    
    while ($current <= $endDate) {
        $key = '';
        
        switch ($period) {
            case 'day':
                $key = $current->format('d/m');
                $current->modify('+1 day');
                break;
            case 'week':
                $key = 'S' . $current->format('W');
                $current->modify('+1 week');
                break;
            case 'year':
                $key = $current->format('Y');
                $current->modify('+1 year');
                break;
            case 'month':
            default:
                $key = $current->format('M Y');
                $current->modify('+1 month');
                break;
        }
        
        $chartData['labels'][] = $key;
        $dataByPeriod[$key] = ['revenue' => 0, 'commission' => 0];
    }
    
    // REMPLIR LES DONNÉES
    foreach ($reservations as $res) {
        $date = $res->getCreatedAt();
        $key = '';
        
        switch ($period) {
            case 'day':
                $key = $date->format('d/m');
                break;
            case 'week':
                $key = 'S' . $date->format('W');
                break;
            case 'year':
                $key = $date->format('Y');
                break;
            case 'month':
            default:
                $key = $date->format('M Y');
                break;
        }
        
        if (isset($dataByPeriod[$key])) {
            $total = method_exists($res, 'getTotalPrice') ? $res->getTotalPrice() : 
                    (method_exists($res, 'getTotalAmount') ? $res->getTotalAmount() : 0);
            $commission = method_exists($res, 'getCommissionAmount') ? $res->getCommissionAmount() : 
                         (method_exists($res, 'getCommissionPrice') ? $res->getCommissionPrice() : 0);
            
            $dataByPeriod[$key]['revenue'] += $total ?? 0;
            $dataByPeriod[$key]['commission'] += $commission ?? 0;
        }
    }
    
    foreach ($dataByPeriod as $data) {
        $chartData['revenue'][] = $data['revenue'];
        $chartData['commissions'][] = $data['commission'];
    }
    
    // STATS PAR COMPAGNIE
    $companies = $userRepo->createQueryBuilder('u')
        ->where('u.roles LIKE :role')
        ->setParameter('role', '%ROLE_COMPANY%')
        ->getQuery()
        ->getResult();
    
    $companyStats = [];
    
    foreach ($companies as $company) {
        $companyReservations = $reservationRepo->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.status = :status')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('status', 'confirmed')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();
        
        $revenue = 0;
        $commission = 0;
        
        foreach ($companyReservations as $res) {
            $total = method_exists($res, 'getTotalPrice') ? $res->getTotalPrice() : 
                    (method_exists($res, 'getTotalAmount') ? $res->getTotalAmount() : 0);
            $comm = method_exists($res, 'getCommissionAmount') ? $res->getCommissionAmount() : 
                   (method_exists($res, 'getCommissionPrice') ? $res->getCommissionPrice() : 0);
            
            $revenue += $total ?? 0;
            $commission += $comm ?? 0;
        }
        
        if ($revenue > 0) {
            // Croissance compagnie
            $previousCompanyRevenue = $reservationRepo->createQueryBuilder('r')
                ->select('SUM(r.totalPrice) as total')
                ->where('r.company = :company')
                ->andWhere('r.status = :status')
                ->andWhere('r.createdAt BETWEEN :start AND :end')
                ->setParameter('company', $company)
                ->setParameter('status', 'confirmed')
                ->setParameter('start', $previousStart)
                ->setParameter('end', $previousEnd)
                ->getQuery()
                ->getSingleScalarResult() ?? 0;
            
            $companyGrowth = $previousCompanyRevenue > 0 ? 
                (($revenue - $previousCompanyRevenue) / $previousCompanyRevenue) * 100 : 0;
            
            $companyStats[] = [
                'name' => $company->getCompanyName(),
                'reservations' => count($companyReservations),
                'revenue' => $revenue,
                'commission' => $commission,
                'rate' => $revenue > 0 ? ($commission / $revenue) * 100 : 0,
                'growth' => $companyGrowth
            ];
            
            $chartData['company_names'][] = $company->getCompanyName();
            $chartData['company_revenues'][] = $revenue;
        }
    }
    
    usort($companyStats, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    
    // Récupérer la compagnie et le trajet sélectionnés
    $selectedCompany = $companyId ? $userRepo->find($companyId) : null;
    $allTrips = $tripRepo->findBy(['isActive' => true], ['departureCity' => 'ASC']);
    $selectedTrip = $tripId ? $tripRepo->find($tripId) : null;

    return $this->render('admin/statistics.html.twig', [
        'companies'        => $companies,
        'company_id'       => $companyId,
        'selected_company' => $selectedCompany,
        'all_trips'        => $allTrips,
        'trip_id'          => $tripId,
        'selected_trip'    => $selectedTrip,
        'period'           => $period,
        'start_date'       => $startDate->format('Y-m-d'),
        'end_date'         => $endDate->format('Y-m-d'),
        'stats' => [
            'total_revenue'     => $totalRevenue,
            'total_commissions' => $totalCommissions,
            'commission_rate'   => $commissionRate,
            'growth'            => $growth
        ],
        'chart_data'    => $chartData,
        'company_stats' => $companyStats
    ]);
}
    #[Route('/revenus', name: 'admin_revenus')]
    public function revenus(
        TransactionRepository $transactionRepo,
        Request $request
    ): Response {
        $qb = $transactionRepo->createQueryBuilder('t')
            ->leftJoin('t.company', 'c')
            ->where('t.paymentStatus = :status')
            ->setParameter('status', 'confirmed')
            ->orderBy('t.createdAt', 'DESC');

        $companyId = $request->query->get('company_id');
        if ($companyId) {
            $qb->andWhere('t.company = :company')
               ->setParameter('company', $companyId);
        }

        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');
        if ($startDate && $endDate) {
            $qb->andWhere('t.createdAt BETWEEN :start AND :end')
               ->setParameter('start', new \DateTime($startDate))
               ->setParameter('end', new \DateTime($endDate . ' 23:59:59'));
        }

        $transactions = $qb->getQuery()->getResult();

        $totalRevenue = array_sum(array_map(fn($t) => $t->getTotalAmount(), $transactions));
        $totalCommissions = array_sum(array_map(fn($t) => $t->getCommissionAmount(), $transactions));
        $companyRevenue = $totalRevenue - $totalCommissions;

        return $this->render('admin/revenus.html.twig', [
            'transactions' => $transactions,
            'total_revenue' => $totalRevenue,
            'total_commissions' => $totalCommissions,
            'company_revenue' => $companyRevenue,
            'company_id' => $companyId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
    }

   /**
 * Page messagerie
 */
#[Route('/messages', name: 'admin_messages')]
public function messages(
    MessageRepository $messageRepo,
    UserRepository $userRepo
): Response {
    $currentUser = $this->getUser();

    // Messages reçus
    $received = $messageRepo->createQueryBuilder('m')
        ->where('m.receiver = :user')
        ->setParameter('user', $currentUser)
        ->orderBy('m.createdAt', 'DESC')
        ->getQuery()
        ->getResult();

    // Messages envoyés
    $sent = $messageRepo->createQueryBuilder('m')
        ->where('m.sender = :user')
        ->setParameter('user', $currentUser)
        ->orderBy('m.createdAt', 'DESC')
        ->getQuery()
        ->getResult();

    // Nombre de non lus
    $unreadCount = $messageRepo->count([
        'receiver' => $currentUser,
        'isRead' => false
    ]);
    
    // Tous les utilisateurs (pour destinataires)
    $allUsers = $userRepo->findAll();

    return $this->render('admin/messages.html.twig', [
        'received' => $received,
        'sent' => $sent,
        'unread_count' => $unreadCount,
        'all_users' => $allUsers
    ]);
}

/**
 * Envoyer un message
 */
#[Route('/message/send', name: 'admin_message_send', methods: ['POST'])]
public function sendMessage(
    Request $request,
    UserRepository $userRepo,
    EntityManagerInterface $em
): JsonResponse {
    $data = json_decode($request->getContent(), true);
    
    $receiver = $userRepo->find($data['receiver_id']);
    
    if (!$receiver) {
        return $this->json(['success' => false, 'message' => 'Destinataire introuvable']);
    }
    
    $message = new Message();
    $message->setSender($this->getUser());
    $message->setReceiver($receiver);
    $message->setSubject($data['subject']);
    $message->setContent($data['content']);
    $message->setIsRead(false);
    $message->setCreatedAt(new \DateTime());
    
    $em->persist($message);
    $em->flush();
    
    return $this->json(['success' => true, 'message' => 'Message envoyé avec succès']);
}

/**
 * Voir un message
 */
#[Route('/message/{id}/view', name: 'admin_message_view', methods: ['GET'])]
public function viewMessage(
    int $id,
    MessageRepository $messageRepo,
    EntityManagerInterface $em
): JsonResponse {
    $message = $messageRepo->find($id);
    
    if (!$message) {
        return $this->json(['error' => 'Message introuvable'], 404);
    }
    
    // Marquer comme lu si c'est le destinataire
    if ($message->getReceiver() === $this->getUser() && !$message->getIsRead()) {
        $message->setIsRead(true);
        $em->flush();
    }
    
    $from = $message->getSender()->getFirstName() . ' ' . $message->getSender()->getLastName();
    if ($message->getSender()->getCompanyName()) {
        $from .= ' (' . $message->getSender()->getCompanyName() . ')';
    }
    
    return $this->json([
        'from' => $from,
        'subject' => $message->getSubject(),
        'content' => $message->getContent(),
        'date' => $message->getCreatedAt()->format('d/m/Y à H:i'),
        'isRead' => $message->getIsRead()
    ]);
}

/**
 * Info pour répondre
 */
#[Route('/message/{id}/reply-info', name: 'admin_message_reply_info', methods: ['GET'])]
public function replyInfo(
    int $id,
    MessageRepository $messageRepo
): JsonResponse {
    $message = $messageRepo->find($id);
    
    if (!$message) {
        return $this->json(['error' => 'Message introuvable'], 404);
    }
    
    return $this->json([
        'sender_id' => $message->getSender()->getId(),
        'subject' => $message->getSubject()
    ]);
}
    #[Route('/billets/{id}/cancel', name: 'admin_billet_cancel', methods: ['POST'])]
    public function cancelBillet(
        int $id,
        ReservationRepository $reservationRepo,
        AvailabilityManager $availabilityManager,
        EntityManagerInterface $em
    ): JsonResponse {
        $reservation = $reservationRepo->find($id);
        
        if (!$reservation) {
            return $this->json(['success' => false, 'message' => 'Réservation introuvable']);
        }
        
        if ($reservation->getStatus() === 'cancelled') {
            return $this->json(['success' => false, 'message' => 'Déjà annulée']);
        }
        
        $availabilityManager->releaseSeats(
            $reservation->getTrip(),
            $reservation->getTravelDate(),
            $reservation->getNumPassengers()
        );
        
        $reservation->setStatus('cancelled');
        $reservation->setCancelledAt(new \DateTime());
        
        $em->flush();
        
        return $this->json(['success' => true, 'message' => 'Réservation annulée']);
    }

    #[Route('/trip/{id}/delete', name: 'admin_trip_delete', methods: ['POST'])]
    public function deleteTrip(
        int $id,
        TripRepository $tripRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $trip = $tripRepo->find($id);
        
        if (!$trip) {
            return $this->json(['success' => false, 'message' => 'Trajet introuvable']);
        }
        
        $em->remove($trip);
        $em->flush();
        
        return $this->json(['success' => true, 'message' => 'Trajet supprimé']);
    }

    #[Route('/trip/{id}/toggle', name: 'admin_trip_toggle', methods: ['POST'])]
    public function toggleTrip(
        int $id,
        TripRepository $tripRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $trip = $tripRepo->find($id);
        
        if (!$trip) {
            return $this->json(['success' => false, 'message' => 'Trajet introuvable']);
        }
        
        $trip->setIsActive(!$trip->isActive());
        $em->flush();
        
        $status = $trip->isActive() ? 'activé' : 'désactivé';
        return $this->json(['success' => true, 'message' => "Trajet $status", 'is_active' => $trip->isActive()]);
    }

    #[Route('/company/create', name: 'admin_company_create', methods: ['GET', 'POST'])]
    public function createCompany(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($request->isMethod('POST')) {
            $company = new User();
            $company->setEmail($request->request->get('email'));
            $company->setFirstName($request->request->get('first_name'));
            $company->setLastName($request->request->get('last_name'));
            $company->setPhone($request->request->get('phone'));
            $company->setCompanyName($request->request->get('company_name'));
            $company->setRoles(['ROLE_COMPANY']);
            
            $password = $request->request->get('password');
            $company->setPassword($passwordHasher->hashPassword($company, $password));
            
            $company->setCommissionRate($request->request->get('commission_rate') ?? '2.00');
            $company->setCommissionMin($request->request->get('commission_min') ?? '300.00');
            
            $em->persist($company);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Compagnie "%s" créée ! Email : %s — Mot de passe temporaire : %s',
                $company->getCompanyName(),
                $company->getEmail(),
                $password
            ));
            return $this->redirectToRoute('admin_companies');
        }

        return $this->render('admin/company_create.html.twig');
    }

    #[Route('/company/{id}/reset-password', name: 'admin_company_reset_password', methods: ['POST'])]
    public function resetCompanyPassword(
        int $id,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $company = $em->getRepository(\App\Entity\User::class)->find($id);
        if (!$company || !in_array('ROLE_COMPANY', $company->getRoles())) {
            throw $this->createNotFoundException();
        }

        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $company->setPassword($passwordHasher->hashPassword($company, $password));
        $em->flush();

        $this->addFlash('success', sprintf(
            'Mot de passe réinitialisé pour "%s" — Nouveau mot de passe : %s',
            $company->getCompanyName(),
            $password
        ));

        return $this->redirectToRoute('admin_companies');
    }

    #[Route('/reservations', name: 'admin_reservations')]
    public function reservations(ReservationRepository $reservationRepo, Request $request): Response
    {
        $search = $request->query->get('search');
        $status = $request->query->get('status');

        $qb = $reservationRepo->createQueryBuilder('r')
            ->leftJoin('r.trip', 't')
            ->leftJoin('r.company', 'c')
            ->orderBy('r.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('r.reservationId LIKE :search OR r.passengerPhone LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }

        $reservations = $qb->getQuery()->getResult();

        return $this->render('admin/reservations.html.twig', [
            'reservations' => $reservations,
            'search' => $search,
            'status' => $status,
        ]);
    }

    #[Route('/trips', name: 'admin_trips')]
    public function trips(TripRepository $tripRepo): Response
    {
        $trips = $tripRepo->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/trips.html.twig', [
            'trips' => $trips,
        ]);
    }

    #[Route('/billets/export/excel', name: 'admin_billets_export_excel')]
    public function exportExcel(
        ReservationRepository $reservationRepo,
        Request $request
    ): Response {
        $search = $request->query->get('search', '');
        $date = $request->query->get('date', '');
        $tripId = $request->query->get('trip_id', '');
        $status = $request->query->get('status', '');
        
        $qb = $reservationRepo->createQueryBuilder('r')
            ->leftJoin('r.trip', 't')
            ->leftJoin('r.company', 'c');
        
        if ($search) {
            $qb->andWhere('r.reservationId LIKE :search OR r.passengerFirstName LIKE :search OR r.passengerLastName LIKE :search OR r.passengerPhone LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        if ($date) {
            $qb->andWhere('r.travelDate = :date')
               ->setParameter('date', new \DateTime($date));
        }
        
        if ($tripId) {
            $qb->andWhere('r.trip = :tripId')
               ->setParameter('tripId', $tripId);
        }
        
        if ($status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }
        
        $qb->orderBy('r.createdAt', 'DESC');
        $reservations = $qb->getQuery()->getResult();
        
        $csv = [];
        
        $csv[] = [
            'N° RÉSERVATION',
            'AGENCE',
            'DÉPART',
            'ARRIVÉE',
            'DATE VOYAGE',
            'PASSAGER',
            'TÉLÉPHONE',
            'PLACES',
            'PRIX TOTAL',
            'COMMISSION',
            'STATUT',
            'DATE CRÉATION'
        ];
        
        foreach ($reservations as $reservation) {
            $totalPrice = method_exists($reservation, 'getTotalPrice') 
                ? $reservation->getTotalPrice() 
                : (method_exists($reservation, 'getTotalAmount') ? $reservation->getTotalAmount() : 0);
            
            $commissionAmount = method_exists($reservation, 'getCommissionAmount')
                ? $reservation->getCommissionAmount()
                : (method_exists($reservation, 'getCommissionPrice') ? $reservation->getCommissionPrice() : 0);
            
            $csv[] = [
                $reservation->getReservationId(),
                $reservation->getCompany()->getCompanyName(),
                $reservation->getTrip()->getDepartureCity(),
                $reservation->getTrip()->getArrivalCity(),
                $reservation->getTravelDate()->format('d/m/Y'),
                $reservation->getPassengerFirstName() . ' ' . $reservation->getPassengerLastName(),
                $reservation->getPassengerPhone(),
                $reservation->getNumPassengers(),
                $totalPrice . ' FCFA',
                $commissionAmount . ' FCFA',
                $reservation->getStatus(),
                $reservation->getCreatedAt()->format('d/m/Y H:i')
            ];
        }
        
        $filename = 'reservations_' . date('YmdHis') . '.csv';
        $handle = fopen('php://temp', 'r+');
        
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
        
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';');
        }
        
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        
        return $response;
    }
/**
 * Afficher le formulaire de création de trajet
 */

// AJOUTEZ/REMPLACEZ CES ROUTES DANS AdminController.php

/**
 * Créer un nouveau trajet
 */
#[Route('/trip/create', name: 'admin_trip_create', methods: ['GET', 'POST'])]
public function createTrip(
    Request $request,
    UserRepository $userRepo,
    EntityManagerInterface $em
): Response {
    if ($request->isMethod('POST')) {
        $trip = new Trip();
        
        // Récupérer la compagnie
        $company = $userRepo->find($request->request->get('company_id'));
        if (!$company) {
            $this->addFlash('error', 'Compagnie introuvable');
            return $this->redirectToRoute('admin_trajets');
        }
        
        $trip->setCompany($company);
        $trip->setDepartureCity($request->request->get('departure_city'));
        $trip->setArrivalCity($request->request->get('arrival_city'));
        $trip->setPrice($request->request->get('price'));
        $trip->setDuration($request->request->get('duration'));
        $trip->setTotalSeats((int) $request->request->get('total_seats'));
        $trip->setAvailableSeats((int) $request->request->get('total_seats'));
        $trip->setIsActive(true);
        
        // Heures
        $departureTime = \DateTime::createFromFormat('H:i', $request->request->get('departure_time'));
        $arrivalTime = \DateTime::createFromFormat('H:i', $request->request->get('arrival_time'));
        $trip->setDepartureTime($departureTime);
        $trip->setArrivalTime($arrivalTime);
        
        // Jours de la semaine (array JSON)
        $days = $request->request->all()['days'] ?? ['0','1','2','3','4','5','6'];
        $trip->setDaysOfWeek($days); // Stocké comme array JSON
        
        $em->persist($trip);
        $em->flush();
        
        $this->addFlash('success', 'Trajet créé avec succès !');
        return $this->redirectToRoute('admin_trajets');
    }
    
    return $this->redirectToRoute('admin_trajets');
}

/**
 * Modifier un trajet existant
 */
#[Route('/trip/{id}/edit', name: 'admin_trip_edit', methods: ['GET', 'POST'])]
public function editTrip(
    int $id,
    Request $request,
    TripRepository $tripRepo,
    UserRepository $userRepo,
    EntityManagerInterface $em
): Response {
    $trip = $tripRepo->find($id);
    
    if (!$trip) {
        $this->addFlash('error', 'Trajet introuvable');
        return $this->redirectToRoute('admin_trajets');
    }
    
    if ($request->isMethod('POST')) {
        // Récupérer la compagnie
        $company = $userRepo->find($request->request->get('company_id'));
        if ($company) {
            $trip->setCompany($company);
        }
        
        $trip->setDepartureCity($request->request->get('departure_city'));
        $trip->setArrivalCity($request->request->get('arrival_city'));
        $trip->setPrice($request->request->get('price'));
        $trip->setDuration($request->request->get('duration'));
        $trip->setTotalSeats((int) $request->request->get('total_seats'));
        
        // Heures
        $departureTime = \DateTime::createFromFormat('H:i', $request->request->get('departure_time'));
        $arrivalTime = \DateTime::createFromFormat('H:i', $request->request->get('arrival_time'));
        $trip->setDepartureTime($departureTime);
        $trip->setArrivalTime($arrivalTime);
        
        // Jours de la semaine (array JSON)
        $days = $request->request->all()['days'] ?? [];
        $trip->setDaysOfWeek($days); // Stocké comme array JSON
        
        $trip->setUpdatedAt(new \DateTime());
        
        $em->flush();
        
        $this->addFlash('success', 'Trajet modifié avec succès !');
        return $this->redirectToRoute('admin_trajets');
    }
    
    // GET - Afficher le formulaire
    $companies = $userRepo->createQueryBuilder('u')
        ->where('u.roles LIKE :role')
        ->setParameter('role', '%ROLE_COMPANY%')
        ->orderBy('u.companyName', 'ASC')
        ->getQuery()
        ->getResult();
    
    return $this->render('admin/trip_edit.html.twig', [
        'trip' => $trip,
        'companies' => $companies
    ]);
}
    #[Route('/billets/export/pdf', name: 'admin_billets_export_pdf')]
    public function exportPdf(
        ReservationRepository $reservationRepo,
        Request $request
    ): Response {
        $search = $request->query->get('search', '');
        $date = $request->query->get('date', '');
        $tripId = $request->query->get('trip_id', '');
        $status = $request->query->get('status', '');
        
        $qb = $reservationRepo->createQueryBuilder('r')
            ->leftJoin('r.trip', 't')
            ->leftJoin('r.company', 'c');
        
        if ($search) {
            $qb->andWhere('r.reservationId LIKE :search OR r.passengerFirstName LIKE :search OR r.passengerLastName LIKE :search OR r.passengerPhone LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        if ($date) {
            $qb->andWhere('r.travelDate = :date')
               ->setParameter('date', new \DateTime($date));
        }
        
        if ($tripId) {
            $qb->andWhere('r.trip = :tripId')
               ->setParameter('tripId', $tripId);
        }
        
        if ($status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }
        
        $qb->orderBy('r.createdAt', 'DESC');
        $reservations = $qb->getQuery()->getResult();

        // Générer les QR codes en base64 pour chaque réservation
        $qrCodes = [];
        foreach ($reservations as $reservation) {
            $qrCodes[$reservation->getReservationId()] = $this->generateQrBase64($reservation->getReservationId());
        }

        $html = $this->renderView('admin/pdf/billets.html.twig', [
            'reservations' => $reservations,
            'qrCodes' => $qrCodes,
            'date' => $date ? new \DateTime($date) : null,
            'generated_at' => new \DateTime()
        ]);

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');

        return $response;
    }

    private function generateQrBase64(string $data): string
    {
        $qr = \Endroid\QrCode\QrCode::create($data)
            ->setSize(80)
            ->setMargin(4);
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qr);
        return 'data:image/png;base64,' . base64_encode($result->getString());
    }
     /**
     * Récupérer les données d'une compagnie (pour le modal)
     */
    #[Route('/company/{id}/data', name: 'admin_company_data', methods: ['GET'])]
    public function getCompanyData(
        int $id,
        UserRepository $userRepo
    ): JsonResponse {
        $company = $userRepo->find($id);
        
        if (!$company) {
            return $this->json(['error' => 'Compagnie introuvable'], 404);
        }
        
        return $this->json([
            'id' => $company->getId(),
            'companyName' => $company->getCompanyName(),
            'firstName' => $company->getFirstName(),
            'lastName' => $company->getLastName(),
            'email' => $company->getEmail(),
            'phone' => $company->getPhone(),
            'commissionRate' => method_exists($company, 'getCommissionRate') ? $company->getCommissionRate() : '2.00',
            'commissionMin' => method_exists($company, 'getCommissionMin') ? $company->getCommissionMin() : '300'
        ]);
    }

    /**
     * Modifier une compagnie
     */
    #[Route('/company/{id}/edit', name: 'admin_company_edit_ajax', methods: ['POST'])]
    public function editCompanyAjax(
        int $id,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $company = $userRepo->find($id);
        
        if (!$company) {
            return $this->json(['success' => false, 'message' => 'Compagnie introuvable']);
        }
        
        $data = json_decode($request->getContent(), true);
        
        $company->setCompanyName($data['company_name']);
        $company->setFirstName($data['first_name']);
        $company->setLastName($data['last_name']);
        $company->setEmail($data['email']);
        $company->setPhone($data['phone']);
        
        if (method_exists($company, 'setCommissionRate')) {
            $company->setCommissionRate($data['commission_rate'] ?? '2.00');
        }
        
        if (method_exists($company, 'setCommissionMin')) {
            $company->setCommissionMin($data['commission_min'] ?? '300');
        }
        
        $em->flush();
        
        return $this->json(['success' => true, 'message' => 'Compagnie modifiée avec succès']);
    }

    /**
     * Activer/Désactiver une compagnie
     */
    #[Route('/company/{id}/toggle', name: 'admin_company_toggle', methods: ['POST'])]
    public function toggleCompany(
        int $id,
        UserRepository $userRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $company = $userRepo->find($id);
        
        if (!$company) {
            return $this->json(['success' => false, 'message' => 'Compagnie introuvable']);
        }
        
        // Si la compagnie a une méthode isActive
        if (method_exists($company, 'isActive') && method_exists($company, 'setIsActive')) {
            $company->setIsActive(!$company->isActive());
            $em->flush();
            $status = $company->isActive() ? 'activée' : 'désactivée';
            return $this->json(['success' => true, 'message' => "Compagnie $status"]);
        }
        
        return $this->json(['success' => false, 'message' => 'Action non supportée']);
    }
    /**
 * Récupérer les données d'un utilisateur
 */
#[Route('/user/{id}/data', name: 'admin_user_data', methods: ['GET'])]
public function getUserData(
    int $id,
    UserRepository $userRepo
): JsonResponse {
    $user = $userRepo->find($id);
    
    if (!$user) {
        return $this->json(['error' => 'Utilisateur introuvable'], 404);
    }
    
    return $this->json([
        'id' => $user->getId(),
        'firstName' => $user->getFirstName(),
        'lastName' => $user->getLastName(),
        'email' => $user->getEmail(),
        'phone' => $user->getPhone(),
        'companyName' => $user->getCompanyName(),
        'roles' => $user->getRoles()
    ]);
}

/**
 * Modifier un utilisateur
 */
#[Route('/user/{id}/edit', name: 'admin_user_edit', methods: ['POST'])]
public function editUser(
    int $id,
    Request $request,
    UserRepository $userRepo,
    EntityManagerInterface $em
): JsonResponse {
    $user = $userRepo->find($id);
    
    if (!$user) {
        return $this->json(['success' => false, 'message' => 'Utilisateur introuvable']);
    }
    
    $data = json_decode($request->getContent(), true);
    
    $user->setFirstName($data['first_name']);
    $user->setLastName($data['last_name']);
    $user->setEmail($data['email']);
    $user->setPhone($data['phone'] ?? null);
    
    if (isset($data['company_name']) && !empty($data['company_name'])) {
        $user->setCompanyName($data['company_name']);
    }
    
    $em->flush();
    
    return $this->json(['success' => true, 'message' => 'Utilisateur modifié avec succès']);
}

/**
 * Activer/Désactiver un utilisateur
 */
#[Route('/user/{id}/toggle', name: 'admin_user_toggle', methods: ['POST'])]
public function toggleUser(
    int $id,
    UserRepository $userRepo,
    EntityManagerInterface $em
): JsonResponse {
    $user = $userRepo->find($id);
    
    if (!$user) {
        return $this->json(['success' => false, 'message' => 'Utilisateur introuvable']);
    }
    
    $user->setIsActive(!$user->isActive());
    $em->flush();
    
    $status = $user->isActive() ? 'activé' : 'désactivé';
    return $this->json(['success' => true, 'message' => "Utilisateur $status"]);
}

/**
 * Créer un agent service client
 */
#[Route('/user/create-service-client', name: 'admin_create_service_client', methods: ['POST'])]
public function createServiceClient(
    Request $request,
    EntityManagerInterface $em,
    UserPasswordHasherInterface $passwordHasher
): JsonResponse {
    $data = json_decode($request->getContent(), true);
    
    // Validation
    if (!isset($data['first_name'], $data['last_name'], $data['email'], $data['password'])) {
        return $this->json([
            'success' => false,
            'message' => 'Données manquantes'
        ], 400);
    }
    
    // Créer l'utilisateur
    $user = new User();
    $user->setFirstName($data['first_name']);
    $user->setLastName($data['last_name']);
    $user->setEmail($data['email']);
    $user->setPhone($data['phone'] ?? null);
    $user->setRoles(['ROLE_SERVICE_CLIENT']);
    $user->setIsActive(true);
    
    // Hasher le mot de passe
    $hashedPassword = $passwordHasher->hashPassword($user, $data['password']);
    $user->setPassword($hashedPassword);
    
    $em->persist($user);
    $em->flush();
    
    return $this->json([
        'success' => true,
        'message' => 'Agent service client créé avec succès'
    ], 201);
}

/**
 * Page paramètres
 */
#[Route('/settings', name: 'admin_settings')]
public function settings(SettingsRepository $settingsRepo): Response
{
    // Récupérer tous les paramètres
    $allSettings = $settingsRepo->findAll();
    
    // Convertir en array associatif
    $settings = [];
    foreach ($allSettings as $setting) {
        $settings[$setting->getSettingKey()] = $setting->getSettingValue();
    }
    
    return $this->render('admin/settings.html.twig', [
        'settings' => $settings
    ]);
}

/**
 * Enregistrer les paramètres
 */
#[Route('/settings/save', name: 'admin_settings_save', methods: ['POST'])]
public function saveSettings(
    Request $request,
    SettingsRepository $settingsRepo,
    EntityManagerInterface $em
): JsonResponse {
    $data = json_decode($request->getContent(), true);
    
    foreach ($data as $key => $value) {
        $setting = $settingsRepo->findOneBy(['settingKey' => $key]);
        
        if (!$setting) {
            $setting = new Settings();
            $setting->setSettingKey($key);
        }
        
        $setting->setSettingValue($value);
        $setting->setUpdatedAt(new \DateTime());
        
        $em->persist($setting);
    }
    
    $em->flush();
    
    return $this->json(['success' => true, 'message' => 'Paramètres enregistrés']);
}

}