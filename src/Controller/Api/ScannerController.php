<?php

namespace App\Controller\Api;

use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/scanner', name: 'api_scanner_')]
class ScannerController extends AbstractController
{
    /**
     * POST /api/scanner/login
     * Body: { "email": "...", "password": "..." }
     * Retourne un apiToken pour la compagnie
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (empty($data['email']) || empty($data['password'])) {
            return $this->json(['success' => false, 'message' => 'Email et mot de passe requis'], 400);
        }

        $user = $userRepo->findOneBy(['email' => $data['email']]);

        if (!$user || !$hasher->isPasswordValid($user, $data['password'])) {
            return $this->json(['success' => false, 'message' => 'Identifiants incorrects'], 401);
        }

        if (!in_array('ROLE_COMPANY', $user->getRoles())) {
            return $this->json(['success' => false, 'message' => 'Accès réservé aux compagnies'], 403);
        }

        if (!$user->isActive()) {
            return $this->json(['success' => false, 'message' => 'Compte désactivé'], 403);
        }

        // Générer un token si inexistant
        if (!$user->getApiToken()) {
            $user->setApiToken(bin2hex(random_bytes(32)));
            $em->flush();
        }

        return $this->json([
            'success'      => true,
            'token'        => $user->getApiToken(),
            'company_id'   => $user->getId(),
            'company_name' => $user->getCompanyName(),
        ]);
    }

    /**
     * GET /api/scanner/sync?date=YYYY-MM-DD
     * Header: Authorization: Bearer <token>
     * Retourne toutes les réservations du jour pour la compagnie
     */
    #[Route('/sync', name: 'sync', methods: ['GET'])]
    public function sync(
        Request $request,
        UserRepository $userRepo,
        ReservationRepository $reservationRepo
    ): JsonResponse {
        $user = $this->getCompanyFromToken($request, $userRepo);
        if ($user instanceof JsonResponse) return $user;

        $dateStr = $request->query->get('date', date('Y-m-d'));
        try {
            $date = new \DateTime($dateStr);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Date invalide'], 400);
        }

        $reservations = $reservationRepo->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.travelDate = :date')
            ->andWhere('r.status != :cancelled')
            ->setParameter('company', $user)
            ->setParameter('date', $date)
            ->setParameter('cancelled', 'cancelled')
            ->getQuery()->getResult();

        $data = [];
        foreach ($reservations as $r) {
            $data[] = $this->serializeReservation($r);
        }

        return $this->json([
            'success' => true,
            'date'    => $dateStr,
            'count'   => count($data),
            'data'    => $data,
        ]);
    }

    /**
     * POST /api/scanner/scan
     * Header: Authorization: Bearer <token>
     * Body: { "reservation_id": "RES..." }
     * Vérifie et marque comme scanné
     */
    #[Route('/scan', name: 'scan', methods: ['POST'])]
    public function scan(
        Request $request,
        UserRepository $userRepo,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getCompanyFromToken($request, $userRepo);
        if ($user instanceof JsonResponse) return $user;

        $data = json_decode($request->getContent(), true);
        $reservationId = trim($data['reservation_id'] ?? '');

        if (!$reservationId) {
            return $this->json(['success' => false, 'message' => 'ID de réservation manquant'], 400);
        }

        $reservation = $reservationRepo->findOneBy(['reservationId' => $reservationId]);

        if (!$reservation) {
            return $this->json([
                'success' => false,
                'valid'   => false,
                'status'  => 'NOT_FOUND',
                'message' => 'Billet introuvable',
            ], 404);
        }

        // Vérifier que c'est bien la bonne compagnie
        if ($reservation->getCompany()->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'valid'   => false,
                'status'  => 'WRONG_COMPANY',
                'message' => 'Ce billet n\'appartient pas à votre compagnie',
            ], 403);
        }

        // Déjà annulé
        if ($reservation->getStatus() === 'cancelled') {
            return $this->json([
                'success'     => true,
                'valid'       => false,
                'status'      => 'CANCELLED',
                'message'     => 'Billet annulé',
                'reservation' => $this->serializeReservation($reservation),
            ]);
        }

        // Déjà scanné
        if ($reservation->isScanned()) {
            return $this->json([
                'success'     => true,
                'valid'       => false,
                'status'      => 'ALREADY_SCANNED',
                'message'     => 'Billet déjà scanné le ' . $reservation->getScannedAt()->format('d/m/Y à H:i'),
                'reservation' => $this->serializeReservation($reservation),
            ]);
        }

        // Valider et marquer comme scanné
        $reservation->setScannedAt(new \DateTime());
        $em->flush();

        return $this->json([
            'success'     => true,
            'valid'       => true,
            'status'      => 'OK',
            'message'     => 'Billet valide',
            'reservation' => $this->serializeReservation($reservation),
        ]);
    }

    /**
     * GET /api/scanner/verify/{reservationId}
     * Vérification rapide sans marquer (pour mode offline qui confirme ensuite)
     */
    #[Route('/verify/{reservationId}', name: 'verify', methods: ['GET'])]
    public function verify(
        string $reservationId,
        Request $request,
        UserRepository $userRepo,
        ReservationRepository $reservationRepo
    ): JsonResponse {
        $user = $this->getCompanyFromToken($request, $userRepo);
        if ($user instanceof JsonResponse) return $user;

        $reservation = $reservationRepo->findOneBy(['reservationId' => $reservationId]);

        if (!$reservation || $reservation->getCompany()->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'valid' => false, 'status' => 'NOT_FOUND']);
        }

        return $this->json([
            'success'     => true,
            'valid'       => $reservation->getStatus() !== 'cancelled' && !$reservation->isScanned(),
            'status'      => $reservation->isScanned() ? 'ALREADY_SCANNED' : strtoupper($reservation->getStatus()),
            'reservation' => $this->serializeReservation($reservation),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function getCompanyFromToken(Request $request, UserRepository $userRepo): mixed
    {
        $auth = $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return $this->json(['success' => false, 'message' => 'Token manquant'], 401);
        }

        $token = substr($auth, 7);
        $user  = $userRepo->findOneBy(['apiToken' => $token]);

        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Token invalide'], 401);
        }

        return $user;
    }

    private function serializeReservation($r): array
    {
        return [
            'id'             => $r->getId(),
            'reservation_id' => $r->getReservationId(),
            'passenger'      => $r->getPassengerFirstName() . ' ' . $r->getPassengerLastName(),
            'phone'          => $r->getPassengerPhone(),
            'num_passengers' => $r->getNumPassengers(),
            'trip'           => $r->getTrip()->getDepartureCity() . ' → ' . $r->getTrip()->getArrivalCity(),
            'departure_time' => $r->getTrip()->getDepartureTime()->format('H:i'),
            'travel_date'    => $r->getTravelDate()->format('Y-m-d'),
            'total_price'    => (float) $r->getTotalPrice(),
            'status'         => $r->getStatus(),
            'scanned_at'     => $r->getScannedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
