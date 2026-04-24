<?php

namespace App\Controller\Api;

use App\Entity\Reservation;
use App\Entity\Transaction;
use App\Entity\TripAvailability;
use App\Repository\ReservationRepository;
use App\Repository\TransactionRepository;
use App\Repository\TripRepository;
use App\Repository\TripAvailabilityRepository;
use App\Service\CinetPayService;
use App\Service\PayDunyaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/public/payment', name: 'api_payment_')]
class PaymentController extends AbstractController
{
    /**
     * POST /api/public/payment/initiate
     *
     * Corps JSON :
     * {
     *   "reservation_id": "RES...",
     *   "phone": "771234567",
     *   "return_url": "myapp://payment/return"   (optionnel, deep link front)
     * }
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "transaction_id": "TXN...",
     *     "payment_token": "xxx",
     *     "payment_url": "https://checkout.cinetpay.com/payment/xxx",
     *     "amount": 6000,
     *     "reservation_id": "RES..."
     *   }
     * }
     */
    #[Route('/initiate', name: 'initiate', methods: ['POST'])]
    public function initiate(
        Request $request,
        ReservationRepository $reservationRepo,
        TransactionRepository $transactionRepo,
        EntityManagerInterface $em,
        CinetPayService $cinetPay
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['reservation_id'])) {
            return $this->json(['success' => false, 'message' => 'reservation_id est requis'], 400);
        }

        $reservation = $reservationRepo->findOneBy(['reservationId' => $data['reservation_id']]);
        if (!$reservation) {
            return $this->json(['success' => false, 'message' => 'Réservation non trouvée'], 404);
        }

        if ($reservation->getStatus() === 'confirmed') {
            return $this->json(['success' => false, 'message' => 'Cette réservation est déjà payée'], 400);
        }

        if ($reservation->getStatus() === 'cancelled') {
            return $this->json(['success' => false, 'message' => 'Cette réservation est annulée'], 400);
        }

        // Si une transaction PENDING existe, renvoyer directement
        $existing = $transactionRepo->findPendingByReservation($reservation);
        if ($existing) {
            return $this->json([
                'success' => true,
                'message' => 'Paiement déjà en cours',
                'data' => [
                    'transaction_id' => $existing->getTransactionId(),
                    'payment_token'  => $existing->getPaydunyaToken(),
                    'payment_url'    => $this->buildCheckoutUrl($existing->getPaydunyaToken()),
                    'amount'         => $existing->getAmount(),
                    'reservation_id' => $reservation->getReservationId(),
                ],
            ]);
        }

        $transactionId = 'TXN' . date('YmdHis') . rand(1000, 9999);
        $baseUrl       = $request->getSchemeAndHttpHost();

        $firstName = $reservation->getPassengerFirstName();
        $lastName  = $reservation->getPassengerLastName();

        $result = $cinetPay->createPayment([
            'transaction_id'   => $transactionId,
            'amount'           => (float) $reservation->getTotalPrice(),
            'description'      => sprintf(
                'Réservation %s – %s → %s le %s',
                $reservation->getReservationId(),
                $reservation->getTrip()->getDepartureCity(),
                $reservation->getTrip()->getArrivalCity(),
                $reservation->getTravelDate()->format('d/m/Y')
            ),
            'notify_url'       => $baseUrl . '/api/public/payment/ipn',
            'return_url'       => $data['return_url'] ?? $baseUrl . '/api/public/payment/return',
            'customer_name'    => $lastName,
            'customer_surname' => $firstName,
            'customer_phone'   => $data['phone'] ?? $reservation->getPassengerPhone(),
            'channels'         => 'MOBILE_MONEY',
        ]);

        if (!$result['success']) {
            return $this->json(['success' => false, 'message' => $result['message'] ?? 'Erreur CinetPay'], 502);
        }

        $transaction = new Transaction();
        $transaction->setTransactionId($transactionId);
        $transaction->setReservation($reservation);
        $transaction->setAmount((float) $reservation->getTotalPrice());
        $transaction->setPaymentMethod('MOBILE_MONEY');
        $transaction->setStatus('PENDING');
        $transaction->setPaydunyaToken($result['payment_token']);
        $transaction->setPhoneNumber($data['phone'] ?? $reservation->getPassengerPhone());
        $transaction->setMetadata(['provider' => 'cinetpay', 'payment_token' => $result['payment_token']]);

        $em->persist($transaction);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Paiement initié avec succès',
            'data'    => [
                'transaction_id' => $transactionId,
                'payment_token'  => $result['payment_token'],
                'payment_url'    => $result['payment_url'],
                'amount'         => (float) $reservation->getTotalPrice(),
                'reservation_id' => $reservation->getReservationId(),
            ],
        ], 201);
    }

    /**
     * POST /api/public/payment/ipn
     * Webhook PayDunya — crée la réservation si le paiement est confirmé.
     */
    #[Route('/ipn', name: 'ipn', methods: ['POST'])]
    public function ipn(
        Request $request,
        TransactionRepository $transactionRepo,
        TripRepository $tripRepo,
        TripAvailabilityRepository $availabilityRepo,
        EntityManagerInterface $em,
        PayDunyaService $payDunya
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        // PayDunya envoie le token dans invoice.token (racine du payload)
        $paydunyaToken = $data['invoice']['token'] ?? $data['data']['invoice']['token'] ?? null;
        $status        = $data['invoice']['status'] ?? $data['data']['invoice']['status'] ?? null;

        if (!$paydunyaToken) {
            return $this->json(['success' => false, 'message' => 'Token manquant'], 400);
        }

        $transaction = $transactionRepo->findOneBy(['paydunyaToken' => $paydunyaToken]);
        if (!$transaction) {
            return $this->json(['success' => false, 'message' => 'Transaction non trouvée'], 404);
        }

        if ($transaction->getStatus() !== 'PENDING') {
            return $this->json(['success' => true, 'message' => 'Déjà traité']);
        }

        $meta    = $transaction->getMetadata() ?? [];
        $success = $status === 'completed';

        if ($success && $transaction->getReservation() === null && isset($meta['booking'])) {
            $booking       = $meta['booking'];
            $trip          = $tripRepo->find($booking['trip_id']);
            $travelDate    = new \DateTime($booking['travel_date']);
            $numPassengers = (int) $booking['num_passengers'];

            $availability = $availabilityRepo->findOneBy(['trip' => $trip, 'travelDate' => $travelDate]);
            if (!$availability) {
                $availability = new TripAvailability();
                $availability->setTrip($trip);
                $availability->setTravelDate($travelDate);
                $availability->setTotalSeats($trip->getTotalSeats());
                $availability->setReservedSeats(0);
                $availability->setAvailableSeats($trip->getTotalSeats());
                $em->persist($availability);
            }
            $availability->setReservedSeats($availability->getReservedSeats() + $numPassengers);
            $availability->setAvailableSeats($availability->getAvailableSeats() - $numPassengers);

            $totalAmount      = $trip->getPrice() * $numPassengers;
            $commissionAmount = $totalAmount * 0.025;

            $reservation = new Reservation();
            $reservation->setReservationId('RES' . date('YmdHis') . rand(100, 999));
            $reservation->setTrip($trip);
            $reservation->setCompany($trip->getCompany());
            $reservation->setTravelDate($travelDate);
            $reservation->setPassengerFirstName($booking['first_name']);
            $reservation->setPassengerLastName($booking['last_name']);
            $reservation->setPassengerPhone($booking['phone']);
            $reservation->setNumPassengers($numPassengers);
            $reservation->setTotalPrice((string) $totalAmount);
            $reservation->setCommissionAmount((string) $commissionAmount);
            $reservation->setCompanyAmount((string) ($totalAmount - $commissionAmount));
            $reservation->setStatus('confirmed');
            $em->persist($reservation);

            $transaction->setReservation($reservation);
            $transaction->setStatus('COMPLETED');
            $transaction->setCompletedAt(new \DateTimeImmutable());
        } elseif (!$success) {
            $transaction->setStatus('FAILED');
        }

        $transaction->setMetadata(array_merge($meta, ['ipn_received_at' => date('Y-m-d H:i:s')]));
        $em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * GET /api/public/payment/status/{transactionId}
     */
    #[Route('/status/{transactionId}', name: 'status', methods: ['GET'])]
    public function status(
        string $transactionId,
        TransactionRepository $transactionRepo,
        TripRepository $tripRepo,
        TripAvailabilityRepository $availabilityRepo,
        EntityManagerInterface $em,
        PayDunyaService $payDunya
    ): JsonResponse {
        $transaction = $transactionRepo->findOneBy(['transactionId' => $transactionId]);
        if (!$transaction) {
            return $this->json(['success' => false, 'message' => 'Transaction non trouvée'], 404);
        }

        // Si encore PENDING, vérifier auprès de PayDunya et créer la réservation si confirmé
        if ($transaction->getStatus() === 'PENDING' && $transaction->getPaydunyaToken()) {
            $check = $payDunya->checkTransactionStatus($transaction->getPaydunyaToken());

            if (($check['status'] ?? '') === 'completed' && $transaction->getReservation() === null) {
                $meta    = $transaction->getMetadata() ?? [];
                $booking = $meta['booking'] ?? null;

                if ($booking) {
                    $trip          = $tripRepo->find($booking['trip_id']);
                    $travelDate    = new \DateTime($booking['travel_date']);
                    $numPassengers = (int) $booking['num_passengers'];

                    $availability = $availabilityRepo->findOneBy(['trip' => $trip, 'travelDate' => $travelDate]);
                    if (!$availability) {
                        $availability = new TripAvailability();
                        $availability->setTrip($trip);
                        $availability->setTravelDate($travelDate);
                        $availability->setTotalSeats($trip->getTotalSeats());
                        $availability->setReservedSeats(0);
                        $availability->setAvailableSeats($trip->getTotalSeats());
                        $em->persist($availability);
                    }
                    $availability->setReservedSeats($availability->getReservedSeats() + $numPassengers);
                    $availability->setAvailableSeats($availability->getAvailableSeats() - $numPassengers);

                    $totalAmount      = $trip->getPrice() * $numPassengers;
                    $commissionAmount = $totalAmount * 0.025;

                    $reservation = new Reservation();
                    $reservation->setReservationId('RES' . date('YmdHis') . rand(100, 999));
                    $reservation->setTrip($trip);
                    $reservation->setCompany($trip->getCompany());
                    $reservation->setTravelDate($travelDate);
                    $reservation->setPassengerFirstName($booking['first_name']);
                    $reservation->setPassengerLastName($booking['last_name']);
                    $reservation->setPassengerPhone($booking['phone']);
                    $reservation->setNumPassengers($numPassengers);
                    $reservation->setTotalPrice((string) $totalAmount);
                    $reservation->setCommissionAmount((string) $commissionAmount);
                    $reservation->setCompanyAmount((string) ($totalAmount - $commissionAmount));
                    $reservation->setStatus('confirmed');
                    $em->persist($reservation);

                    $transaction->setReservation($reservation);
                    $transaction->setStatus('COMPLETED');
                    $transaction->setCompletedAt(new \DateTimeImmutable());
                    $transaction->setMetadata(array_merge($meta, ['confirmed_via' => 'polling', 'confirmed_at' => date('Y-m-d H:i:s')]));
                    $em->flush();
                }
            }
        }

        $responseData = [
            'transaction_id' => $transaction->getTransactionId(),
            'status'         => $transaction->getStatus(),
            'amount'         => $transaction->getAmount(),
            'created_at'     => $transaction->getCreatedAt()->format('d/m/Y H:i'),
            'completed_at'   => $transaction->getCompletedAt()?->format('d/m/Y H:i'),
        ];

        $reservation = $transaction->getReservation();
        if ($reservation && $transaction->getStatus() === 'COMPLETED') {
            $trip = $reservation->getTrip();
            $responseData['ticket'] = [
                'reservation_id' => $reservation->getReservationId(),
                'company'        => $trip->getCompany()->getCompanyName(),
                'departure_city' => $trip->getDepartureCity(),
                'arrival_city'   => $trip->getArrivalCity(),
                'departure_time' => $trip->getDepartureTime()->format('H:i'),
                'arrival_time'   => $trip->getArrivalTime()->format('H:i'),
                'duration'       => $trip->getDuration(),
                'travel_date'    => $reservation->getTravelDate()->format('Y-m-d'),
                'first_name'     => $reservation->getPassengerFirstName(),
                'last_name'      => $reservation->getPassengerLastName(),
                'num_passengers' => $reservation->getNumPassengers(),
                'amount'         => $transaction->getAmount(),
            ];
        }

        return $this->json(['success' => true, 'data' => $responseData]);
    }

    /**
     * GET /api/public/payment/reservation/{reservationId}
     * Récupère le dernier paiement lié à une réservation.
     */
    #[Route('/reservation/{reservationId}', name: 'status_by_reservation', methods: ['GET'])]
    public function statusByReservation(
        string $reservationId,
        ReservationRepository $reservationRepo,
        TransactionRepository $transactionRepo
    ): JsonResponse {
        $reservation = $reservationRepo->findOneBy(['reservationId' => $reservationId]);
        if (!$reservation) {
            return $this->json(['success' => false, 'message' => 'Réservation non trouvée'], 404);
        }

        $transaction = $transactionRepo->findOneBy(
            ['reservation' => $reservation],
            ['createdAt' => 'DESC']
        );

        return $this->json([
            'success' => true,
            'data'    => [
                'reservation_id'     => $reservation->getReservationId(),
                'reservation_status' => $reservation->getStatus(),
                'payment'            => $transaction ? [
                    'transaction_id' => $transaction->getTransactionId(),
                    'payment_token'  => $transaction->getPaydunyaToken(),
                    'payment_url'    => $this->buildCheckoutUrl($transaction->getPaydunyaToken()),
                    'status'         => $transaction->getStatus(),
                    'amount'         => $transaction->getAmount(),
                    'created_at'     => $transaction->getCreatedAt()->format('d/m/Y H:i'),
                    'completed_at'   => $transaction->getCompletedAt()?->format('d/m/Y H:i'),
                ] : null,
            ],
        ]);
    }

    /**
     * GET /api/public/payment/return
     * Redirigé ici après paiement sur la page CinetPay.
     */
    #[Route('/return', name: 'return', methods: ['GET'])]
    public function return(Request $request): JsonResponse
    {
        $transactionId = $request->query->get('transaction_id');

        return $this->json([
            'success'        => true,
            'message'        => 'Retour après paiement. Vérifiez via /api/public/payment/status/' . $transactionId,
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * POST /api/public/payment/direct-charge
     * Paiement PayDunya SoftPay (push USSD direct, sans redirection).
     * La réservation est créée uniquement après confirmation du paiement.
     *
     * Corps JSON :
     * {
     *   "trip_id": 1,
     *   "travel_date": "2026-03-20",
     *   "first_name": "Amadou",
     *   "last_name": "Diallo",
     *   "phone": "76123456",
     *   "num_passengers": 2,
     *   "payment_phone": "76123456"
     * }
     */
    #[Route('/direct-charge', name: 'direct_charge', methods: ['POST'])]
    public function directCharge(
        Request $request,
        TripRepository $tripRepo,
        EntityManagerInterface $em,
        PayDunyaService $payDunya
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $required = ['trip_id', 'travel_date', 'first_name', 'last_name', 'phone', 'num_passengers', 'payment_phone'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->json(['success' => false, 'message' => "Champ requis : $field"], 400);
            }
        }

        $trip = $tripRepo->find($data['trip_id']);
        if (!$trip || !$trip->isActive()) {
            return $this->json(['success' => false, 'message' => 'Trajet non trouvé ou inactif'], 404);
        }

        $numPassengers = (int) $data['num_passengers'];
        $totalAmount   = (float) $trip->getPrice() * $numPassengers;
        $transactionId = 'TXN' . date('YmdHis') . rand(1000, 9999);
        // Utilise l'URL ngrok si définie, sinon l'URL locale
        $baseUrl = rtrim($_ENV['PUBLIC_BASE_URL'] ?? $request->getSchemeAndHttpHost(), '/');

        // Étape 1 : Créer la facture PayDunya
        $invoiceResult = $payDunya->createInvoice([
            'amount'       => $totalAmount,
            'description'  => sprintf('%s-%s %s (%dp)',
                $trip->getDepartureCity(), $trip->getArrivalCity(),
                $data['travel_date'], $numPassengers
            ),
            'cancel_url'   => $baseUrl . '/api/public/payment/return',
            'return_url'   => $baseUrl . '/api/public/payment/return',
            'callback_url' => $baseUrl . '/api/public/payment/ipn',
            'custom_data'  => ['transaction_id' => $transactionId],
        ]);

        if (!$invoiceResult['success']) {
            return $this->json(['success' => false, 'message' => $invoiceResult['response_text'] ?? 'Erreur création facture PayDunya'], 502);
        }

        $paydunyaToken = $invoiceResult['token'];

        // Étape 2 : Déclencher le push USSD sur le téléphone (optionnel — SoftPay peut ne pas être actif en sandbox)
        $chargeResult  = $payDunya->directCharge($paydunyaToken, $data['payment_phone']);
        $softpayPushed = $chargeResult['success'];

        // Étape 3 : Sauvegarder la transaction SANS réservation
        $transaction = new Transaction();
        $transaction->setTransactionId($transactionId);
        $transaction->setAmount($totalAmount);
        $transaction->setPaymentMethod('MOBILE_MONEY');
        $transaction->setStatus('PENDING');
        $transaction->setPaydunyaToken($paydunyaToken);
        $transaction->setPhoneNumber($data['payment_phone']);
        $transaction->setMetadata([
            'provider' => 'paydunya',
            'booking'  => [
                'trip_id'        => (int) $data['trip_id'],
                'travel_date'    => $data['travel_date'],
                'first_name'     => $data['first_name'],
                'last_name'      => $data['last_name'],
                'phone'          => $data['phone'],
                'num_passengers' => $numPassengers,
            ],
        ]);

        $em->persist($transaction);
        $em->flush();

        $message = $softpayPushed
            ? 'Code USSD envoyé sur votre téléphone. Confirmez le paiement.'
            : 'Transaction créée. Confirmez le paiement sur votre téléphone mobile money.';

        return $this->json([
            'success'        => true,
            'message'        => $message,
            'softpay_pushed' => $softpayPushed,
            'data'           => [
                'transaction_id' => $transactionId,
                'amount'         => $totalAmount,
                'invoice_url'    => $invoiceResult['invoice_url'] ?? null,
            ],
        ], 201);
    }

    /**
     * POST /api/public/payment/test-confirm/{transactionId}
     * SANDBOX UNIQUEMENT — simule la confirmation d'un paiement pour les tests depuis la France.
     */
    #[Route('/test-confirm/{transactionId}', name: 'test_confirm', methods: ['POST'])]
    public function testConfirm(
        string $transactionId,
        TransactionRepository $transactionRepo,
        TripRepository $tripRepo,
        TripAvailabilityRepository $availabilityRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        if ($_ENV['APP_ENV'] !== 'dev' && $_ENV['APP_ENV'] !== 'test') {
            return $this->json(['success' => false, 'message' => 'Endpoint disponible en mode dev/test uniquement'], 403);
        }

        $transaction = $transactionRepo->findOneBy(['transactionId' => $transactionId]);
        if (!$transaction) {
            return $this->json(['success' => false, 'message' => 'Transaction non trouvée'], 404);
        }

        if ($transaction->getStatus() !== 'PENDING') {
            return $this->json(['success' => false, 'message' => 'Transaction déjà traitée : ' . $transaction->getStatus()], 400);
        }

        $meta    = $transaction->getMetadata() ?? [];
        $booking = $meta['booking'] ?? null;
        if (!$booking) {
            return $this->json(['success' => false, 'message' => 'Données de réservation manquantes'], 400);
        }

        $trip       = $tripRepo->find($booking['trip_id']);
        $travelDate = new \DateTime($booking['travel_date']);
        $numPassengers = (int) $booking['num_passengers'];

        $availability = $availabilityRepo->findOneBy(['trip' => $trip, 'travelDate' => $travelDate]);
        if (!$availability) {
            $availability = new TripAvailability();
            $availability->setTrip($trip);
            $availability->setTravelDate($travelDate);
            $availability->setTotalSeats($trip->getTotalSeats());
            $availability->setReservedSeats(0);
            $availability->setAvailableSeats($trip->getTotalSeats());
            $em->persist($availability);
        }
        $availability->setReservedSeats($availability->getReservedSeats() + $numPassengers);
        $availability->setAvailableSeats($availability->getAvailableSeats() - $numPassengers);

        $totalAmount      = $trip->getPrice() * $numPassengers;
        $commissionAmount = $totalAmount * 0.025;

        $reservation = new Reservation();
        $reservation->setReservationId('RES' . date('YmdHis') . rand(100, 999));
        $reservation->setTrip($trip);
        $reservation->setCompany($trip->getCompany());
        $reservation->setTravelDate($travelDate);
        $reservation->setPassengerFirstName($booking['first_name']);
        $reservation->setPassengerLastName($booking['last_name']);
        $reservation->setPassengerPhone($booking['phone']);
        $reservation->setNumPassengers($numPassengers);
        $reservation->setTotalPrice((string) $totalAmount);
        $reservation->setCommissionAmount((string) $commissionAmount);
        $reservation->setCompanyAmount((string) ($totalAmount - $commissionAmount));
        $reservation->setStatus('confirmed');
        $em->persist($reservation);

        $transaction->setReservation($reservation);
        $transaction->setStatus('COMPLETED');
        $transaction->setCompletedAt(new \DateTimeImmutable());
        $transaction->setMetadata(array_merge($meta, ['test_confirmed_at' => date('Y-m-d H:i:s')]));
        $em->flush();

        return $this->json([
            'success'        => true,
            'message'        => 'Paiement simulé avec succès',
            'reservation_id' => $reservation->getReservationId(),
        ]);
    }

    private function buildCheckoutUrl(?string $token): ?string
    {
        if (!$token) {
            return null;
        }
        return 'https://checkout.cinetpay.com/payment/' . $token;
    }
}
