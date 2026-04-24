<?php
namespace App\Command;

use App\Repository\TripRepository;
use App\Service\AvailabilityManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'maya:generate-availabilities',
    description: 'Génère les disponibilités pour tous les trajets actifs',
)]
class GenerateAvailabilitiesCommand extends Command
{
    private TripRepository $tripRepo;
    private AvailabilityManager $availabilityManager;

    public function __construct(TripRepository $tripRepo, AvailabilityManager $availabilityManager)
    {
        parent::__construct();
        $this->tripRepo = $tripRepo;
        $this->availabilityManager = $availabilityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $trips = $this->tripRepo->findBy(['isActive' => true]);
        
        $io->title('Génération des disponibilités');
        $io->progressStart(count($trips));
        
        foreach ($trips as $trip) {
            $this->availabilityManager->generateAvailabilities($trip, 90);
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        $io->success(sprintf('Disponibilités générées pour %d trajets', count($trips)));
        
        return Command::SUCCESS;
    }
}