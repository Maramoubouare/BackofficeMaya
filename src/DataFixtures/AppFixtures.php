<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Trip;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // 1. Créer un Admin MAYA
        $admin = new User();
        $admin->setEmail('admin@maya.ml');
        $admin->setFirstName('Admin');
        $admin->setLastName('MAYA');
        $admin->setPhone('76000000');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);

        // 2. Créer un Agent Service Client
        $agent = new User();
        $agent->setEmail('agent@maya.ml');
        $agent->setFirstName('Marie');
        $agent->setLastName('TRAORE');
        $agent->setPhone('76111111');
        $agent->setRoles(['ROLE_AGENT']);
        $agent->setPassword($this->passwordHasher->hashPassword($agent, 'agent123'));
        $manager->persist($agent);

        // 3. Créer des Compagnies
        $companies = [
            ['name' => 'Bani Transport', 'email' => 'bani@maya.ml', 'phone' => '76222222'],
            ['name' => 'Somatra', 'email' => 'somatra@maya.ml', 'phone' => '76333333'],
            ['name' => 'Binké Transport', 'email' => 'binke@maya.ml', 'phone' => '76444444'],
        ];

        $companyEntities = [];
        foreach ($companies as $companyData) {
            $company = new User();
            $company->setEmail($companyData['email']);
            $company->setFirstName('Gérant');
            $company->setLastName($companyData['name']);
            $company->setPhone($companyData['phone']);
            $company->setCompanyName($companyData['name']);
            $company->setRoles(['ROLE_COMPANY']);
            $company->setPassword($this->passwordHasher->hashPassword($company, 'company123'));
            $manager->persist($company);
            $companyEntities[] = $company;
        }

        // 4. Créer des trajets pour chaque compagnie
        $routes = [
            ['from' => 'Bamako', 'to' => 'Sikasso', 'time' => '08:00', 'price' => 15000],
            ['from' => 'Bamako', 'to' => 'Mopti', 'time' => '07:00', 'price' => 12000],
            ['from' => 'Bamako', 'to' => 'Kayes', 'time' => '09:00', 'price' => 10000],
            ['from' => 'Sikasso', 'to' => 'Bamako', 'time' => '14:00', 'price' => 15000],
        ];

        foreach ($companyEntities as $company) {
            foreach ($routes as $routeData) {
                $trip = new Trip();
                $trip->setCompany($company);
                $trip->setDepartureCity($routeData['from']);
                $trip->setArrivalCity($routeData['to']);
                $trip->setDepartureTime(new \DateTime($routeData['time']));
                $trip->setArrivalTime((new \DateTime($routeData['time']))->modify('+6 hours'));
                $trip->setPrice($routeData['price']);
                $trip->setTotalSeats(50);
                $trip->setAvailableSeats(50);
                $trip->setDaysOfWeek([1, 2, 3, 4, 5, 6]); // Lun-Sam
                $trip->setIsActive(true);
                $manager->persist($trip);
            }
        }

        $manager->flush();

        echo "\n✅ Fixtures chargées avec succès !\n";
        echo "   - 1 Admin : admin@maya.ml / admin123\n";
        echo "   - 1 Agent : agent@maya.ml / agent123\n";
        echo "   - 3 Compagnies : {name}@maya.ml / company123\n";
        echo "   - 12 Trajets créés\n\n";
    }
}