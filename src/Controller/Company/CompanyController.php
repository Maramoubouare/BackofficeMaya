<?php

namespace App\Controller\Company;

use App\Entity\Message;
use App\Entity\Trip;
use App\Repository\MessageRepository;
use App\Repository\SettingsRepository;
use App\Repository\TripRepository;
use App\Repository\ReservationRepository;
use App\Repository\TripAvailabilityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/company')]
class CompanyController extends AbstractController
{
    #[Route('/', name: 'company_dashboard')]
    public function dashboard(
        ReservationRepository $reservationRepo,
        TripRepository $tripRepo,
        TripAvailabilityRepository $availabilityRepo,
        Request $request
    ): Response {
        $company = $this->getUser();
        $today   = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');

        $filterDate = $request->query->get('date');
        $selectedDate = $filterDate ? new \DateTime($filterDate) : $today;
        $filterTripId = $request->query->get('trip_id');
        $filterCity   = $request->query->get('city');
        $filterSearch = $request->query->get('search');

        // === STATS CARDS ===
        $qb1 = $reservationRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.company = :company')
            ->andWhere('r.createdAt >= :today')
            ->andWhere('r.createdAt < :tomorrow')
            ->andWhere('r.status = :status')
            ->setParameter('company', $company)
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('status', 'confirmed');
        if ($filterTripId) {
            $qb1->andWhere('r.trip = :tripId')->setParameter('tripId', $filterTripId);
        }
        $reservationsToday = $qb1->getQuery()->getSingleScalarResult() ?? 0;

        if ($filterTripId) {
            $selectedTripEntity = $tripRepo->find($filterTripId);
            $totalCapacity = $selectedTripEntity ? $selectedTripEntity->getTotalSeats() : 0;
            $seatsReservedForDate = $availabilityRepo->createQueryBuilder('ta')
                ->select('SUM(ta.reservedSeats)')
                ->where('ta.travelDate = :date')
                ->andWhere('ta.trip = :tripId')
                ->setParameter('date', $selectedDate->format('Y-m-d'))
                ->setParameter('tripId', $filterTripId)
                ->getQuery()->getSingleScalarResult() ?? 0;
        } else {
            $totalCapacity = $tripRepo->createQueryBuilder('t')
                ->select('SUM(t.totalSeats)')
                ->where('t.company = :company')
                ->andWhere('t.isActive = true')
                ->setParameter('company', $company)
                ->getQuery()->getSingleScalarResult() ?? 0;
            $seatsReservedForDate = $availabilityRepo->createQueryBuilder('ta')
                ->select('SUM(ta.reservedSeats)')
                ->join('ta.trip', 't')
                ->where('ta.travelDate = :date')
                ->andWhere('t.company = :company')
                ->setParameter('date', $selectedDate->format('Y-m-d'))
                ->setParameter('company', $company)
                ->getQuery()->getSingleScalarResult() ?? 0;
        }
        $seatsAvailableForDate = $totalCapacity - $seatsReservedForDate;

        $qb3 = $availabilityRepo->createQueryBuilder('ta')
            ->select('COUNT(ta.id)')
            ->join('ta.trip', 't')
            ->where('ta.travelDate = :date')
            ->andWhere('ta.availableSeats = 0')
            ->andWhere('t.company = :company')
            ->setParameter('date', $selectedDate->format('Y-m-d'))
            ->setParameter('company', $company);
        if ($filterTripId) {
            $qb3->andWhere('ta.trip = :tripId')->setParameter('tripId', $filterTripId);
        }
        $completedTripsForDate = $qb3->getQuery()->getSingleScalarResult() ?? 0;

        $totalRoutes = $filterTripId ? 1 : $tripRepo->count(['company' => $company, 'isActive' => true]);

        // === TABLE DÉPARTS ===
        $tripsQb = $tripRepo->createQueryBuilder('t')
            ->where('t.company = :company')
            ->andWhere('t.isActive = true')
            ->setParameter('company', $company);

        if ($filterTripId) {
            $tripsQb->andWhere('t.id = :tripId')->setParameter('tripId', $filterTripId);
        }
        if ($filterCity) {
            $tripsQb->andWhere('t.departureCity = :city OR t.arrivalCity = :city')->setParameter('city', $filterCity);
        }
        if ($filterSearch) {
            $tripsQb->andWhere('t.departureCity LIKE :s OR t.arrivalCity LIKE :s')->setParameter('s', '%'.$filterSearch.'%');
        }
        $tripsQb->orderBy('t.departureTime', 'ASC');
        $companyTrips = $tripsQb->getQuery()->getResult();

        $departures = [];
        foreach ($companyTrips as $trip) {
            $availability = $availabilityRepo->findOneBy(['trip' => $trip, 'travelDate' => $selectedDate]);
            $dep = new \stdClass();
            $dep->trip           = $trip;
            $dep->company        = $trip->getCompany();
            $dep->departureCity  = $trip->getDepartureCity();
            $dep->arrivalCity    = $trip->getArrivalCity();
            $dep->departureTime  = $trip->getDepartureTime();
            $dep->arrivalTime    = $trip->getArrivalTime();
            $dep->departureDate  = $selectedDate;
            $dep->seatsTotal     = $availability ? $availability->getTotalSeats()    : $trip->getTotalSeats();
            $dep->seatsSold      = $availability ? $availability->getReservedSeats() : 0;
            $dep->seatsAvailable = $availability ? $availability->getAvailableSeats(): $trip->getTotalSeats();
            $dep->status         = $dep->seatsAvailable == 0 ? 'complet' : 'actif';
            $departures[] = $dep;
        }

        // === STATS PAR TRAJET (colonne droite) ===
        $allCompanyTrips = $tripRepo->findBy(['company' => $company, 'isActive' => true], ['departureCity' => 'ASC']);
        $tripStatsToday = [];
        foreach ($allCompanyTrips as $trip) {
            $availability = $availabilityRepo->findOneBy(['trip' => $trip, 'travelDate' => $selectedDate]);
            $seatsSold      = $availability ? $availability->getReservedSeats() : 0;
            $seatsAvailable = $availability ? $availability->getAvailableSeats() : $trip->getTotalSeats();
            $seatsTotal     = $availability ? $availability->getTotalSeats()     : $trip->getTotalSeats();

            $dayReservations = $reservationRepo->createQueryBuilder('r')
                ->where('r.trip = :trip')
                ->andWhere('r.travelDate = :date')
                ->andWhere('r.status != :cancelled')
                ->setParameter('trip', $trip)
                ->setParameter('date', $selectedDate)
                ->setParameter('cancelled', 'cancelled')
                ->getQuery()->getResult();

            $dayRevenue = 0; $dayPassengers = 0;
            foreach ($dayReservations as $res) {
                $dayRevenue    += $res->getTotalPrice() ?? 0;
                $dayPassengers += $res->getNumPassengers();
            }

            $tripStatsToday[] = [
                'trip'            => $trip,
                'label'           => $trip->getDepartureCity() . ' → ' . $trip->getArrivalCity() . ' (' . $trip->getDepartureTime()->format('H:i') . ')',
                'seats_total'     => $seatsTotal,
                'seats_sold'      => $seatsSold,
                'seats_available' => $seatsAvailable,
                'reservations'    => count($dayReservations),
                'passengers'      => $dayPassengers,
                'revenue'         => $dayRevenue,
                'fill_rate'       => $seatsTotal > 0 ? round($seatsSold / $seatsTotal * 100) : 0,
            ];
        }

        $selectedTripStats = null;
        if ($filterTripId) {
            foreach ($tripStatsToday as $ts) {
                if ($ts['trip']->getId() == $filterTripId) { $selectedTripStats = $ts; break; }
            }
        }

        // === STATS GLOBALES ===
        $qbGlobal = $reservationRepo->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.status = :status')
            ->setParameter('company', $company)
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
            $averageRate = ($totalRoutes * 50) > 0 ? round(($totalSeats / ($totalRoutes * 50)) * 100) : 0;
        }

        return $this->render('company/dashboard.html.twig', [
            'stats' => [
                'reservations_today'       => $reservationsToday,
                'seats_available'          => $seatsAvailableForDate,
                'tickets_completed'        => $completedTripsForDate,
                'active_departures'        => $totalRoutes,
                'active_departures_today'  => count($departures),
                'total_routes'             => $totalRoutes,
            ],
            'departures'          => $departures,
            'trip_stats_today'    => $tripStatsToday,
            'selected_trip_stats' => $selectedTripStats,
            'global_stats' => [
                'total_departures'   => $totalRoutes,
                'total_reservations' => $totalReservations,
                'total_seats_sold'   => $totalSeats,
                'average_rate'       => $averageRate,
            ],
            'selected_date'   => $selectedDate->format('Y-m-d'),
            'today_date'      => $today->format('Y-m-d'),
            'filter_city'     => $filterCity,
            'filter_search'   => $filterSearch,
            'filter_trip_id'  => $filterTripId,
        ]);
    }

    #[Route('/trips', name: 'company_trips')]
    public function trips(TripRepository $tripRepo): Response
    {
        $trips = $tripRepo->findBy(['company' => $this->getUser()], ['createdAt' => 'DESC']);
        return $this->render('company/trips.html.twig', ['trips' => $trips]);
    }

    #[Route('/trips/create', name: 'company_trip_create', methods: ['GET', 'POST'])]
    public function createTrip(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $trip = new Trip();
            $trip->setCompany($this->getUser());
            $trip->setDepartureCity($request->request->get('departure_city'));
            $trip->setArrivalCity($request->request->get('arrival_city'));
            $trip->setDepartureTime(new \DateTime($request->request->get('departure_time')));
            $trip->setArrivalTime(new \DateTime($request->request->get('arrival_time')));
            $trip->setDuration($request->request->get('duration') ?? '');
            $trip->setPrice($request->request->get('price'));
            $trip->setTotalSeats((int) $request->request->get('total_seats'));
            $trip->setAvailableSeats((int) $request->request->get('total_seats'));
            $daysOfWeek = $request->request->all('days_of_week') ?? [];
            $trip->setDaysOfWeek(array_map('intval', $daysOfWeek));
            $trip->setVehicleType($request->request->get('vehicle_type') ?? 'Bus');
            $trip->setHasAC($request->request->get('has_ac') === '1');
            $trip->setHasBreak($request->request->get('has_break') === '1');
            $trip->setBreakLocation($request->request->get('break_location') ?? '');
            $trip->setIsActive(true);
            $em->persist($trip);
            $em->flush();
            $this->addFlash('success', 'Trajet créé avec succès !');
            return $this->redirectToRoute('company_trips');
        }
        return $this->render('company/trip_create.html.twig');
    }

    #[Route('/trips/{id}/edit', name: 'company_trip_edit', methods: ['GET', 'POST'])]
    public function editTrip(Trip $trip, Request $request, EntityManagerInterface $em): Response
    {
        if ($trip->getCompany() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')) {
            $trip->setDepartureCity($request->request->get('departure_city'));
            $trip->setArrivalCity($request->request->get('arrival_city'));
            $trip->setDepartureTime(new \DateTime($request->request->get('departure_time')));
            $trip->setArrivalTime(new \DateTime($request->request->get('arrival_time')));
            $trip->setDuration($request->request->get('duration') ?? '');
            $trip->setPrice($request->request->get('price'));
            $trip->setTotalSeats((int) $request->request->get('total_seats'));
            $daysOfWeek = $request->request->all('days_of_week') ?? [];
            $trip->setDaysOfWeek(array_map('intval', $daysOfWeek));
            $trip->setVehicleType($request->request->get('vehicle_type') ?? 'Bus');
            $trip->setHasAC($request->request->get('has_ac') === '1');
            $trip->setHasBreak($request->request->get('has_break') === '1');
            $trip->setBreakLocation($request->request->get('break_location') ?? '');
            $em->flush();
            $this->addFlash('success', 'Trajet modifié avec succès !');
            return $this->redirectToRoute('company_trips');
        }
        return $this->render('company/trip_edit.html.twig', ['trip' => $trip]);
    }

    #[Route('/trips/{id}/toggle', name: 'company_trip_toggle', methods: ['POST'])]
    public function toggleTrip(Trip $trip, EntityManagerInterface $em): Response
    {
        if ($trip->getCompany() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $trip->setIsActive(!$trip->isActive());
        $em->flush();
        $this->addFlash('success', $trip->isActive() ? 'Trajet activé.' : 'Trajet désactivé.');
        return $this->redirectToRoute('company_trips');
    }

    #[Route('/reservations', name: 'company_reservations')]
    public function reservations(ReservationRepository $reservationRepo, Request $request): Response
    {
        $company    = $this->getUser();
        $search     = $request->query->get('search');
        $status     = $request->query->get('status');
        $travelDate = $request->query->get('travel_date');

        $qb = $reservationRepo->createQueryBuilder('r')
            ->where('r.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('r.reservationId LIKE :s OR r.passengerLastName LIKE :s OR r.passengerPhone LIKE :s')
               ->setParameter('s', '%' . $search . '%');
        }
        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ($travelDate) {
            $date = new \DateTime($travelDate);
            $qb->andWhere('r.travelDate = :travelDate')->setParameter('travelDate', $date);
        }

        return $this->render('company/reservations.html.twig', [
            'reservations' => $qb->getQuery()->getResult(),
            'search'       => $search,
            'status'       => $status,
            'travel_date'  => $travelDate ?? '',
        ]);
    }

    #[Route('/statistics', name: 'company_statistics')]
    public function statistics(ReservationRepository $reservationRepo, TripRepository $tripRepo, Request $request): Response
    {
        $company = $this->getUser();

        // FILTRES
        $period    = $request->query->get('period', 'month');
        $startDate = $request->query->get('start_date');
        $endDate   = $request->query->get('end_date');
        $tripId    = $request->query->get('trip_id');

        if (!$startDate || !$endDate) {
            $endDate = new \DateTime();
            switch ($period) {
                case 'day':  $startDate = (new \DateTime())->modify('-7 days'); break;
                case 'week': $startDate = (new \DateTime())->modify('-8 weeks'); break;
                case 'year': $startDate = (new \DateTime())->modify('-5 years'); break;
                default:     $startDate = (new \DateTime())->modify('-12 months'); break;
            }
        } else {
            $startDate = new \DateTime($startDate);
            $endDate   = new \DateTime($endDate);
        }

        // Réservations de la période
        $qb = $reservationRepo->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.status = :status')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('status', 'confirmed')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);
        if ($tripId) {
            $qb->andWhere('r.trip = :tripId')->setParameter('tripId', $tripId);
        }
        $reservations = $qb->getQuery()->getResult();

        // Stats globales
        $totalRevenue = 0; $totalCommissions = 0;
        foreach ($reservations as $res) {
            $totalRevenue     += $res->getTotalPrice() ?? 0;
            $totalCommissions += $res->getCommissionAmount() ?? 0;
        }
        $commissionRate = $totalRevenue > 0 ? ($totalCommissions / $totalRevenue) * 100 : 0;

        // Croissance vs période précédente
        $diffDays      = $startDate->diff($endDate)->days;
        $previousStart = (clone $startDate)->modify("-{$diffDays} days");
        $previousEnd   = clone $startDate;
        $prevQb = $reservationRepo->createQueryBuilder('r')
            ->select('SUM(r.totalPrice)')
            ->where('r.company = :company')
            ->andWhere('r.status = :status')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('status', 'confirmed')
            ->setParameter('start', $previousStart)
            ->setParameter('end', $previousEnd);
        if ($tripId) { $prevQb->andWhere('r.trip = :tripId')->setParameter('tripId', $tripId); }
        $previousRevenue = $prevQb->getQuery()->getSingleScalarResult() ?? 0;
        $growth = $previousRevenue > 0 ? (($totalRevenue - $previousRevenue) / $previousRevenue) * 100 : 0;

        // Chart data par période
        $chartData = ['labels' => [], 'revenue' => [], 'commissions' => [], 'trip_names' => [], 'trip_revenues' => []];
        $current = clone $startDate;
        $dataByPeriod = [];
        while ($current <= $endDate) {
            switch ($period) {
                case 'day':  $key = $current->format('d/m'); $current->modify('+1 day'); break;
                case 'week': $key = 'S' . $current->format('W'); $current->modify('+1 week'); break;
                case 'year': $key = $current->format('Y'); $current->modify('+1 year'); break;
                default:     $key = $current->format('M Y'); $current->modify('+1 month'); break;
            }
            $chartData['labels'][] = $key;
            $dataByPeriod[$key] = ['revenue' => 0, 'commission' => 0];
        }
        foreach ($reservations as $res) {
            $date = $res->getCreatedAt();
            switch ($period) {
                case 'day':  $key = $date->format('d/m'); break;
                case 'week': $key = 'S' . $date->format('W'); break;
                case 'year': $key = $date->format('Y'); break;
                default:     $key = $date->format('M Y'); break;
            }
            if (isset($dataByPeriod[$key])) {
                $dataByPeriod[$key]['revenue']    += $res->getTotalPrice() ?? 0;
                $dataByPeriod[$key]['commission'] += $res->getCommissionAmount() ?? 0;
            }
        }
        foreach ($dataByPeriod as $d) {
            $chartData['revenue'][]     = $d['revenue'];
            $chartData['commissions'][] = $d['commission'];
        }

        // Camembert + tableau : répartition par trajet
        $tsQb = $reservationRepo->createQueryBuilder('r')
            ->select('t.departureCity, t.arrivalCity, COUNT(r.id) as nb, SUM(r.totalPrice) as revenue, SUM(r.commissionAmount) as commission')
            ->join('r.trip', 't')
            ->where('r.company = :company')
            ->andWhere('r.status = :status')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('status', 'confirmed')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('t.id')
            ->orderBy('revenue', 'DESC');
        if ($tripId) {
            $tsQb->andWhere('r.trip = :tripId')->setParameter('tripId', $tripId);
        }
        $tripStats = $tsQb->getQuery()->getResult();

        foreach ($tripStats as $ts) {
            $chartData['trip_names'][]    = $ts['departureCity'] . ' → ' . $ts['arrivalCity'];
            $chartData['trip_revenues'][] = (float)$ts['revenue'];
        }

        $allCompanyTrips = $tripRepo->findBy(['company' => $company, 'isActive' => true], ['departureCity' => 'ASC']);
        $selectedTrip = $tripId ? $tripRepo->find($tripId) : null;

        return $this->render('company/statistics.html.twig', [
            'period'        => $period,
            'start_date'    => $startDate->format('Y-m-d'),
            'end_date'      => $endDate->format('Y-m-d'),
            'trip_id'       => $tripId,
            'selected_trip' => $selectedTrip,
            'all_trips'     => $allCompanyTrips,
            'stats'         => [
                'total_revenue'     => $totalRevenue,
                'total_commissions' => $totalCommissions,
                'commission_rate'   => $commissionRate,
                'growth'            => $growth,
            ],
            'chart_data' => $chartData,
            'trip_stats' => $tripStats,
        ]);
    }

    #[Route('/profile', name: 'company_profile')]
    public function profile(): Response
    {
        return $this->render('company/profile.html.twig');
    }

    #[Route('/profile/password', name: 'company_change_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user        = $this->getUser();
        $currentPwd  = $request->request->get('current_password');
        $newPwd      = $request->request->get('new_password');
        $confirmPwd  = $request->request->get('confirm_password');

        if (!$passwordHasher->isPasswordValid($user, $currentPwd)) {
            $this->addFlash('error', 'Mot de passe actuel incorrect.');
            return $this->redirectToRoute('company_profile');
        }
        if (strlen($newPwd) < 8) {
            $this->addFlash('error', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
            return $this->redirectToRoute('company_profile');
        }
        if ($newPwd !== $confirmPwd) {
            $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
            return $this->redirectToRoute('company_profile');
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPwd));
        $em->flush();

        $this->addFlash('success', 'Mot de passe modifié avec succès !');
        return $this->redirectToRoute('company_profile');
    }

    // ===================== MESSAGERIE =====================

    #[Route('/messages', name: 'company_messages')]
    public function messages(MessageRepository $messageRepo, UserRepository $userRepo): Response
    {
        $currentUser = $this->getUser();

        $received = $messageRepo->createQueryBuilder('m')
            ->where('m.receiver = :user')
            ->setParameter('user', $currentUser)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()->getResult();

        $sent = $messageRepo->createQueryBuilder('m')
            ->where('m.sender = :user')
            ->setParameter('user', $currentUser)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()->getResult();

        $unreadCount = $messageRepo->count(['receiver' => $currentUser, 'isRead' => false]);

        // Uniquement admin et service client (pas les autres compagnies)
        $allUsers = $userRepo->createQueryBuilder('u')
            ->where('u.companyName IS NULL')
            ->andWhere('u.id != :me')
            ->setParameter('me', $currentUser->getId())
            ->getQuery()->getResult();

        return $this->render('company/messages.html.twig', [
            'received'     => $received,
            'sent'         => $sent,
            'unread_count' => $unreadCount,
            'all_users'    => $allUsers,
        ]);
    }

    #[Route('/message/send', name: 'company_message_send', methods: ['POST'])]
    public function sendMessage(Request $request, UserRepository $userRepo, EntityManagerInterface $em): JsonResponse
    {
        $data     = json_decode($request->getContent(), true);
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
        $message->setCreatedAt(new \DateTimeImmutable());

        $em->persist($message);
        $em->flush();

        return $this->json(['success' => true, 'message' => 'Message envoyé avec succès']);
    }

    #[Route('/message/{id}/view', name: 'company_message_view', methods: ['GET'])]
    public function viewMessage(int $id, MessageRepository $messageRepo, EntityManagerInterface $em): JsonResponse
    {
        $message = $messageRepo->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message introuvable'], 404);
        }

        if ($message->getReceiver() === $this->getUser() && !$message->getIsRead()) {
            $message->setIsRead(true);
            $em->flush();
        }

        $from = $message->getSender()->getFirstName() . ' ' . $message->getSender()->getLastName();
        if ($message->getSender()->getCompanyName()) {
            $from .= ' (' . $message->getSender()->getCompanyName() . ')';
        }

        return $this->json([
            'from'    => $from,
            'subject' => $message->getSubject(),
            'date'    => $message->getCreatedAt()->format('d/m/Y H:i'),
            'content' => $message->getContent(),
            'isRead'  => $message->getIsRead(),
        ]);
    }

    #[Route('/message/{id}/reply-info', name: 'company_message_reply_info', methods: ['GET'])]
    public function replyInfo(int $id, MessageRepository $messageRepo): JsonResponse
    {
        $message = $messageRepo->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message introuvable'], 404);
        }

        return $this->json([
            'sender_id' => $message->getSender()->getId(),
            'subject'   => $message->getSubject(),
        ]);
    }

    // ===================== PARAMÈTRES =====================

    #[Route('/settings', name: 'company_settings')]
    public function settings(SettingsRepository $settingsRepo): Response
    {
        $allSettings = $settingsRepo->findAll();
        $settings = [];
        foreach ($allSettings as $s) {
            $settings[$s->getSettingKey()] = $s->getSettingValue();
        }

        return $this->render('company/settings.html.twig', ['settings' => $settings]);
    }

    #[Route('/settings/save', name: 'company_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request, SettingsRepository $settingsRepo, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        foreach ($data as $key => $value) {
            $setting = $settingsRepo->findOneBy(['settingKey' => $key]);
            if (!$setting) {
                $setting = new \App\Entity\Settings();
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
