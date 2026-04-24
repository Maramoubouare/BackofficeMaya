<?php
// src/Entity/Transaction.php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $transactionId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $reservation = null;

    #[ORM\Column]
    private ?float $amount = null;

    #[ORM\Column(length: 50)]
    private ?string $paymentMethod = null; // ORANGE_MONEY, MOOV_MONEY, MTN_MONEY

    #[ORM\Column(length: 20)]
    private ?string $status = null; // PENDING, COMPLETED, FAILED, CANCELLED

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paydunyaToken = null; // Token de paiement PayDunya

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null; // Données supplémentaires PayDunya

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = 'PENDING';
    }

    // Getters & Setters...
    public function getId(): ?int { return $this->id; }
    
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function setTransactionId(string $transactionId): self {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(?Reservation $reservation): self {
        $this->reservation = $reservation;
        return $this;
    }

    public function getAmount(): ?float { return $this->amount; }
    public function setAmount(float $amount): self {
        $this->amount = $amount;
        return $this;
    }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(string $paymentMethod): self {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): self {
        $this->status = $status;
        return $this;
    }

    public function getPaydunyaToken(): ?string { return $this->paydunyaToken; }
    public function setPaydunyaToken(?string $paydunyaToken): self {
        $this->paydunyaToken = $paydunyaToken;
        return $this;
    }

    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $phoneNumber): self {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): self {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $metadata): self {
        $this->metadata = $metadata;
        return $this;
    }
}