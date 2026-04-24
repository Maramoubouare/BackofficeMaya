<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CinetPayService
{
    private const BASE_URL = 'https://api-checkout.cinetpay.com/v2';

    private string $apiKey;
    private string $siteId;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        $this->apiKey  = $_ENV['CINETPAY_API_KEY']  ?? '';
        $this->siteId  = $_ENV['CINETPAY_SITE_ID']  ?? '';
    }

    /**
     * Créer une session de paiement CinetPay.
     *
     * @param array{
     *   transaction_id: string,
     *   amount: float,
     *   description: string,
     *   notify_url: string,
     *   return_url: string,
     *   customer_name: string,
     *   customer_surname: string,
     *   customer_phone: string,
     *   customer_email?: string,
     *   channels?: string,
     * } $data
     */
    public function createPayment(array $data): array
    {
        $payload = [
            'apikey'                => $this->apiKey,
            'site_id'               => $this->siteId,
            'transaction_id'        => $data['transaction_id'],
            'amount'                => (int) $data['amount'],
            'currency'              => 'XOF',
            'description'           => $data['description'],
            'notify_url'            => $data['notify_url'],
            'return_url'            => $data['return_url'],
            'channels'              => $data['channels'] ?? 'MOBILE_MONEY',
            'customer_name'         => $data['customer_name'],
            'customer_surname'      => $data['customer_surname'],
            'customer_phone_number' => $this->normalizePhone($data['customer_phone']),
            'customer_email'        => $data['customer_email'] ?? 'client@maya.ml',
            'customer_address'      => 'Bamako',
            'customer_city'         => 'Bamako',
            'customer_country'      => 'ML',
            'customer_state'        => 'ML',
            'customer_zip_code'     => '00000',
        ];

        try {
            $response   = $this->httpClient->request('POST', self::BASE_URL . '/payment', ['json' => $payload]);
            $rawContent = $response->getContent(false);
            $result     = json_decode($rawContent, true);

            if (!is_array($result)) {
                return ['success' => false, 'message' => 'Réponse CinetPay invalide : ' . substr($rawContent, 0, 200)];
            }

            // CinetPay retourne "201" (string) pour une création réussie
            if (isset($result['code']) && $result['code'] === '201') {
                return [
                    'success'       => true,
                    'payment_token' => $result['data']['payment_token'],
                    'payment_url'   => $result['data']['payment_url'],
                ];
            }

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Erreur CinetPay inconnue',
                'code'    => $result['code'] ?? 'ERROR',
                'debug'   => $result, // ← retirez cette ligne en production
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Vérifier le statut d'une transaction par notre transaction_id.
     */
    public function checkStatus(string $transactionId): array
    {
        $payload = [
            'apikey'         => $this->apiKey,
            'site_id'        => $this->siteId,
            'transaction_id' => $transactionId,
        ];

        try {
            $response   = $this->httpClient->request('POST', self::BASE_URL . '/payment/check', ['json' => $payload]);
            $rawContent = $response->getContent(false);
            $result     = json_decode($rawContent, true);

            if (!is_array($result)) {
                return ['success' => false, 'message' => 'Réponse invalide'];
            }

            if (isset($result['code']) && $result['code'] === '00') {
                $status = match ($result['data']['status'] ?? '') {
                    'ACCEPTED'  => 'COMPLETED',
                    'REFUSED'   => 'FAILED',
                    'CANCELLED' => 'CANCELLED',
                    default     => 'PENDING',
                };

                return [
                    'success'        => true,
                    'status'         => $status,
                    'payment_method' => $result['data']['payment_method'] ?? null,
                    'operator_id'    => $result['data']['operator_id'] ?? null,
                    'payment_date'   => $result['data']['payment_date'] ?? null,
                ];
            }

            return ['success' => false, 'message' => $result['message'] ?? 'Transaction introuvable'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Valider et extraire le transaction_id depuis la notification IPN de CinetPay.
     * CinetPay envoie : cpm_trans_id, cpm_result ("00" = succès)
     */
    public function parseIPN(array $data): array
    {
        $transactionId = $data['cpm_trans_id'] ?? null;
        $result        = $data['cpm_result']   ?? null;

        if (!$transactionId) {
            return ['valid' => false, 'message' => 'cpm_trans_id manquant'];
        }

        return [
            'valid'          => true,
            'transaction_id' => $transactionId,
            'success'        => $result === '00',
            'error_message'  => $data['cpm_error_message'] ?? null,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (!str_starts_with($phone, '+') && !str_starts_with($phone, '00') && strlen($phone) === 8) {
            $phone = '+223' . $phone; // Mali : numéros à 8 chiffres
        }
        return $phone;
    }
}
