<?php

namespace App\Entity;

use App\Repository\TripRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Table(name: 'trips')]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $company = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville de départ est obligatoire')]
    private ?string $departureCity = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville d\'arrivée est obligatoire')]
    private ?string $arrivalCity = null;

    #[ORM\Column(type: 'time')]
    #[Assert\NotBlank(message: 'L\'heure de départ est obligatoire')]
    private ?\DateTimeInterface $departureTime = null;

    #[ORM\Column(type: 'time')]
    #[Assert\NotBlank(message: 'L\'heure d\'arrivée est obligatoire')]
    private ?\DateTimeInterface $arrivalTime = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $duration = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix est obligatoire')]
    #[Assert\Positive(message: 'Le prix doit être positif')]
    private ?string $price = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le nombre de places est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de places doit être positif')]
    private ?int $totalSeats = 50;

    #[ORM\Column]
    private ?int $availableSeats = 50;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $daysOfWeek = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $vehicleType = 'Bus';

    #[ORM\Column]
    private ?bool $hasAC = true;

    #[ORM\Column]
    private ?bool $hasBreak = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $breakLocation = null;

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->availableSeats = $this->totalSeats;
        $this->daysOfWeek = ['0','1','2','3','4','5','6'];
    }

    // Getters et Setters...
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?User
    {
        return $this->company;
    }

    public function setCompany(?User $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getDepartureCity(): ?string
    {
        return $this->departureCity;
    }

    public function setDepartureCity(string $departureCity): static
    {
        $this->departureCity = $departureCity;
        return $this;
    }

    public function getArrivalCity(): ?string
    {
        return $this->arrivalCity;
    }

    public function setArrivalCity(string $arrivalCity): static
    {
        $this->arrivalCity = $arrivalCity;
        return $this;
    }

    public function getDepartureTime(): ?\DateTimeInterface
    {
        return $this->departureTime;
    }

    public function setDepartureTime(\DateTimeInterface $departureTime): static
    {
        $this->departureTime = $departureTime;
        return $this;
    }

    public function getArrivalTime(): ?\DateTimeInterface
    {
        return $this->arrivalTime;
    }

    public function setArrivalTime(\DateTimeInterface $arrivalTime): static
    {
        $this->arrivalTime = $arrivalTime;
        return $this;
    }

    public function getDuration(): ?string
    {
        return $this->duration;
    }

    public function setDuration(?string $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
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

    public function getAvailableSeats(): ?int
    {
        return $this->availableSeats;
    }

    public function setAvailableSeats(int $availableSeats): static
    {
        $this->availableSeats = $availableSeats;
        return $this;
    }

    public function getDaysOfWeek(): array
    {
        // Protection contre propriété non initialisée
        if (!isset($this->daysOfWeek) || $this->daysOfWeek === null) {
            return ['0','1','2','3','4','5','6'];
        }
        return !empty($this->daysOfWeek) ? $this->daysOfWeek : ['0','1','2','3','4','5','6'];
    }

    public function setDaysOfWeek(?array $daysOfWeek): static
    {
        $this->daysOfWeek = $daysOfWeek ?? ['0','1','2','3','4','5','6'];
        return $this;
    }

    public function getVehicleType(): ?string
    {
        return $this->vehicleType;
    }

    public function setVehicleType(?string $vehicleType): static
    {
        $this->vehicleType = $vehicleType;
        return $this;
    }

    public function hasAC(): ?bool
    {
        return $this->hasAC;
    }

    public function setHasAC(bool $hasAC): static
    {
        $this->hasAC = $hasAC;
        return $this;
    }

    public function hasBreak(): ?bool
    {
        return $this->hasBreak;
    }

    public function setHasBreak(bool $hasBreak): static
    {
        $this->hasBreak = $hasBreak;
        return $this;
    }

    public function getBreakLocation(): ?string
    {
        return $this->breakLocation;
    }

    public function setBreakLocation(?string $breakLocation): static
    {
        $this->breakLocation = $breakLocation;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getOccupancyRate(): float
    {
        if ($this->totalSeats === 0) {
            return 0;
        }
        return (($this->totalSeats - $this->availableSeats) / $this->totalSeats) * 100;
    }

    public function __toString(): string
    {
        return $this->departureCity . ' → ' . $this->arrivalCity . ' (' . $this->departureTime->format('H:i') . ')';
    }
}