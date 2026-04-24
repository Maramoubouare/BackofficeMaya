<?php
// src/Entity/TripAvailability.php

namespace App\Entity;

use App\Repository\TripAvailabilityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TripAvailabilityRepository::class)]
#[ORM\Table(name: 'trip_availability')]
#[ORM\Index(columns: ['trip_id', 'travel_date'], name: 'idx_trip_date')]
class TripAvailability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Trip::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]  // ✅ AJOUTÉ
    private ?Trip $trip = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $travelDate = null;

    #[ORM\Column]
    private ?int $availableSeats = null;

    #[ORM\Column]
    private ?int $totalSeats = null;

    #[ORM\Column]
    private ?int $reservedSeats = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->reservedSeats = 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): static
    {
        $this->trip = $trip;
        return $this;
    }

    public function getTravelDate(): ?\DateTimeInterface
    {
        return $this->travelDate;
    }

    public function setTravelDate(\DateTimeInterface $travelDate): static
    {
        $this->travelDate = $travelDate;
        return $this;
    }

    public function getAvailableSeats(): ?int
    {
        return $this->availableSeats;
    }

    public function setAvailableSeats(int $availableSeats): static
    {
        $this->availableSeats = $availableSeats;
        return $this;
    }

    public function getTotalSeats(): ?int
    {
        return $this->totalSeats;
    }

    public function setTotalSeats(int $totalSeats): static
    {
        $this->totalSeats = $totalSeats;
        return $this;
    }

    public function getReservedSeats(): ?int
    {
        return $this->reservedSeats;
    }

    public function setReservedSeats(int $reservedSeats): static
    {
        $this->reservedSeats = $reservedSeats;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Réserve des places pour cette disponibilité
     */
    public function reserveSeats(int $numSeats): bool
    {
        if ($this->availableSeats >= $numSeats) {
            $this->availableSeats -= $numSeats;
            $this->reservedSeats += $numSeats;
            $this->updatedAt = new \DateTime();
            return true;
        }
        return false;
    }

    /**
     * Libère des places (en cas d'annulation)
     */
    public function releaseSeats(int $numSeats): void
    {
        $this->availableSeats += $numSeats;
        $this->reservedSeats -= $numSeats;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Vérifie si assez de places disponibles
     */
    public function hasAvailableSeats(int $numSeats): bool
    {
        return $this->availableSeats >= $numSeats;
    }
}