<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function findPendingByReservation(Reservation $reservation): ?Transaction
    {
        return $this->findOneBy([
            'reservation' => $reservation,
            'status' => 'PENDING',
        ]);
    }

    public function findCompletedByReservation(Reservation $reservation): ?Transaction
    {
        return $this->findOneBy([
            'reservation' => $reservation,
            'status' => 'COMPLETED',
        ]);
    }

    /**
     * Total des paiements complétés (toutes compagnies ou par compagnie via la réservation)
     */
    public function getTotalCompleted(): float
    {
        return (float) $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->andWhere('t.status = :status')
            ->setParameter('status', 'COMPLETED')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
