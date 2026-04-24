<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservations')]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $reservationId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'company_id', nullable: false)]
    private ?User $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'trip_id', nullable: false, onDelete: 'CASCADE')]  // ✅ CASCADE AJOUTÉ
    private ?Trip $trip = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $travelDate = null;

    #[ORM\Column(length: 100)]
    private ?string $passengerFirstName = null;

    #[ORM\Column(length: 100)]
    private ?string $passengerLastName = null;

    #[ORM\Column(length: 20)]
    private ?string $passengerPhone = null;

    #[ORM\Column]
    private ?int $numPassengers = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $commissionAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $companyAmount = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $cancelledAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $scannedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = 'pending';
    }

    // GETTERS ET SETTERS

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservationId(): ?string
    {
        return $this->reservationId;
    }

    public function setReservationId(string $reservationId): self
    {
        $this->reservationId = $reservationId;
        return $this;
    }

    public function getCompany(): ?User
    {
        return $this->company;
    }

    public function setCompany(?User $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): self
    {
        $this->trip = $trip;
        return $this;
    }

    public function getTravelDate(): ?\DateTimeInterface
    {
        return $this->travelDate;
    }

    public function setTravelDate(\DateTimeInterface $travelDate): self
    {
        $this->travelDate = $travelDate;
        return $this;
    }

    public function getPassengerFirstName(): ?string
    {
        return $this->passengerFirstName;
    }

    public function setPassengerFirstName(string $passengerFirstName): self
    {
        $this->passengerFirstName = $passengerFirstName;
        return $this;
    }

    public function getPassengerLastName(): ?string
    {
        return $this->passengerLastName;
    }

    public function setPassengerLastName(string $passengerLastName): self
    {
        $this->passengerLastName = $passengerLastName;
        return $this;
    }

    public function getPassengerPhone(): ?string
    {
        return $this->passengerPhone;
    }

    public function setPassengerPhone(string $passengerPhone): self
    {
        $this->passengerPhone = $passengerPhone;
        return $this;
    }

    public function getNumPassengers(): ?int
    {
        return $this->numPassengers;
    }

    public function setNumPassengers(int $numPassengers): self
    {
        $this->numPassengers = $numPassengers;
        return $this;
    }

    public function getTotalPrice(): ?string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): self
    {
        $this->totalPrice = $totalPrice;
        return $this;
    }

    public function getCommissionAmount(): ?string
    {
        return $this->commissionAmount;
    }

    public function setCommissionAmount(string $commissionAmount): self
    {
        $this->commissionAmount = $commissionAmount;
        return $this;
    }

    public function getCompanyAmount(): ?string
    {
        return $this->companyAmount;
    }

    public function setCompanyAmount(?string $companyAmount): self
    {
        $this->companyAmount = $companyAmount;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeInterface $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getScannedAt(): ?\DateTimeInterface { return $this->scannedAt; }
    public function setScannedAt(?\DateTimeInterface $scannedAt): self { $this->scannedAt = $scannedAt; return $this; }
    public function isScanned(): bool { return $this->scannedAt !== null; }
}