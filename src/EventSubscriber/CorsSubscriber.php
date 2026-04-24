<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gère les headers CORS pour les routes /api/public/*.
 * Permet au frontend (React, Vue, etc.) d'appeler l'API depuis un autre domaine/port.
 */
class CorsSubscriber implements EventSubscriberInterface
{
    // Origines autorisées — adapte selon ton front (ex: http://localhost:3000)
    private const ALLOWED_ORIGINS = [
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost:4200',
        'http://localhost:8080',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:8080',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 9999],
            KernelEvents::RESPONSE => ['onKernelResponse', 9999],
        ];
    }

    /**
     * Répond immédiatement aux requêtes OPTIONS (preflight CORS).
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isApiRoute($request->getPathInfo())) {
            return;
        }

        if ($request->getMethod() !== 'OPTIONS') {
            return;
        }

        $response = new Response('', 204);
        $this->addCorsHeaders($request->headers->get('Origin'), $response);
        $event->setResponse($response);
    }

    /**
     * Ajoute les headers CORS sur toutes les réponses des routes API.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isApiRoute($request->getPathInfo())) {
            return;
        }

        $this->addCorsHeaders($request->headers->get('Origin'), $event->getResponse());
    }

    private function addCorsHeaders(?string $origin, Response $response): void
    {
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
        $response->headers->set('Access-Control-Max-Age', '3600');

        // Allow-Credentials ne peut pas être combiné avec '*'
        if ($allowedOrigin !== '*') {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
    }

    private function resolveAllowedOrigin(?string $origin): string
    {
        if ($origin && in_array($origin, self::ALLOWED_ORIGINS, true)) {
            return $origin;
        }

        // React Native (appareil physique) n'envoie pas d'Origin — on autorise tout
        return '*';
    }

    private function isApiRoute(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }
}
