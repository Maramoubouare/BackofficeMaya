<?php
// src/Service/PayDunyaService.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PayDunyaService
{
    private HttpClientInterface $httpClient;
    private string $masterKey;
    private string $privateKey;
    private string $publicKey;
    private string $token;
    private string $baseUrl;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        
        // Récupération des clés depuis .env
        $this->masterKey = $_ENV['PAYDUNYA_MASTER_KEY'] ?? '';
        $this->privateKey = $_ENV['PAYDUNYA_PRIVATE_KEY'] ?? '';
        $this->publicKey = $_ENV['PAYDUNYA_PUBLIC_KEY'] ?? '';
        $this->token = $_ENV['PAYDUNYA_TOKEN'] ?? '';
        
        // URL de l'API (test ou live)
        $mode = $_ENV['PAYDUNYA_MODE'] ?? 'test';
        $this->baseUrl = $mode === 'test' 
            ? 'https://app.paydunya.com/sandbox-api/v1'
            : 'https://app.paydunya.com/api/v1';
    }

    /**
     * Créer une facture de paiement
     * 
     * @param array $data
     * @return array
     */
    public function createInvoice(array $data): array
    {
        try {
            $payload = [
                'invoice' => [
                    'total_amount' => $data['amount'],
                    'description' => $data['description'] ?? 'Paiement Maya BilletExpress',
                ],
                'store' => [
                    'name' => 'Maya BilletExpress',
                    'tagline' => 'Transport de voyageurs',
                    'phone' => $_ENV['PAYDUNYA_STORE_PHONE'] ?? '',
                    'postal_address' => $_ENV['PAYDUNYA_STORE_ADDRESS'] ?? '',
                    'logo_url' => $_ENV['PAYDUNYA_STORE_LOGO'] ?? '',
                ],
                'actions' => [
                    'cancel_url' => $data['cancel_url'] ?? '',
                    'return_url' => $data['return_url'] ?? '',
                    'callback_url' => $data['callback_url'] ?? '',
                ],
                'custom_data' => $data['custom_data'] ?? [],
            ];

            // Ajouter les items si présents
            if (isset($data['items']) && is_array($data['items'])) {
                $payload['invoice']['items'] = [];
                foreach ($data['items'] as $item) {
                    $payload['invoice']['items'][] = [
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                        'description' => $item['description'] ?? '',
                    ];
                }
            }

            $response   = $this->httpClient->request('POST', $this->baseUrl . '/checkout-invoice/create', [
                'headers' => [
                    'Content-Type'         => 'application/json',
                    'PAYDUNYA-MASTER-KEY'  => $this->masterKey,
                    'PAYDUNYA-PRIVATE-KEY' => $this->privateKey,
                    'PAYDUNYA-TOKEN'       => $this->token,
                ],
                'json' => $payload,
            ]);

            $rawContent = $response->getContent(false);
            $result     = json_decode($rawContent, true);

            if (!is_array($result)) {
                return [
                    'success'       => false,
                    'response_code' => 'INVALID_RESPONSE',
                    'response_text' => 'Réponse PayDunya invalide : ' . substr($rawContent, 0, 300),
                ];
            }

            if (isset($result['response_code']) && $result['response_code'] === '00') {
                return [
                    'success'       => true,
                    'token'         => $result['token'],
                    'response_code' => $result['response_code'],
                    'response_text' => $result['response_text'] ?? 'Facture créée',
                    'invoice_url'   => $result['response_text'],
                ];
            }

            return [
                'success'       => false,
                'response_code' => $result['response_code'] ?? 'ERROR',
                'response_text' => $result['response_text'] ?? 'Erreur lors de la création de la facture',
                'debug'         => $result,
            ];
        } catch (\Exception $e) {
            return [
                'success'       => false,
                'response_code' => 'EXCEPTION',
                'response_text' => $e->getMessage(),
            ];
        }
    }

    /**
     * Déclencher un paiement direct (Softpay / USSD push).
     * Doit être appelé APRÈS createInvoice(), avec le token obtenu.
     *
     * @param string $token   Token de la facture PayDunya
     * @param string $phone   Numéro du client (ex: "771234567" ou "221771234567")
     * @return array
     */
    public function directCharge(string $token, string $phone): array
    {
        // Normaliser le numéro : ajouter l'indicatif Sénégal si absent
        $phone = preg_replace('/\s+/', '', $phone);
        if (!str_starts_with($phone, '221') && strlen($phone) === 9) {
            $phone = '221' . $phone;
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/softpay/' . $token, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'PAYDUNYA-MASTER-KEY' => $this->masterKey,
                    'PAYDUNYA-PRIVATE-KEY' => $this->privateKey,
                    'PAYDUNYA-TOKEN' => $this->token,
                ],
                'json' => [
                    'account_alias' => $phone,
                ],
            ]);

            $rawContent = $response->getContent(false);
            $result = json_decode($rawContent, true);

            if (!is_array($result)) {
                return [
                    'success' => false,
                    'response_code' => 'INVALID_RESPONSE',
                    'response_text' => 'Réponse PayDunya invalide : ' . substr($rawContent, 0, 200),
                ];
            }

            if (isset($result['response_code']) && $result['response_code'] === '00') {
                return [
                    'success' => true,
                    'response_text' => $result['response_text'] ?? 'Demande envoyée',
                ];
            }

            return [
                'success' => false,
                'response_code' => $result['response_code'] ?? 'ERROR',
                'response_text' => $result['response_text'] ?? 'Échec du déclenchement',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'response_code' => 'EXCEPTION',
                'response_text' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifier le statut d'une transaction
     *
     * @param string $token
     * @return array
     */
    public function checkTransactionStatus(string $token): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/checkout-invoice/confirm/' . $token, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'PAYDUNYA-MASTER-KEY' => $this->masterKey,
                    'PAYDUNYA-PRIVATE-KEY' => $this->privateKey,
                    'PAYDUNYA-TOKEN' => $this->token,
                ],
            ]);

            $result = $response->toArray();

            if (isset($result['response_code']) && $result['response_code'] === '00') {
                return [
                    'success' => true,
                    'status' => $result['status'] ?? 'pending', // 'completed', 'pending', 'cancelled'
                    'response_text' => $result['response_text'] ?? '',
                    'customer' => $result['customer'] ?? null,
                    'receipt_url' => $result['receipt_url'] ?? null,
                ];
            } else {
                return [
                    'success' => false,
                    'response_text' => $result['response_text'] ?? 'Transaction introuvable',
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'response_text' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifier la signature d'un callback IPN
     * 
     * @param array $data
     * @return bool
     */
    public function verifyIPNSignature(array $data): bool
    {
        // PayDunya envoie une signature pour vérifier l'authenticité
        if (!isset($data['hash'])) {
            return false;
        }

        $hash = $data['hash'];
        unset($data['hash']);

        // Reconstruire le hash
        $computedHash = hash_hmac('sha512', json_encode($data), $this->masterKey);

        return hash_equals($computedHash, $hash);
    }
}