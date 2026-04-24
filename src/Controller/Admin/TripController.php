<?php

namespace App\Controller\Admin;

use App\Entity\Trip;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/trip')]
class TripController extends AbstractController
{
    #[Route('/create', name: 'admin_trip_create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo
    ): Response {
        if ($request->isMethod('POST')) {
            $trip = new Trip();
            
            $company = $userRepo->find($request->request->get('company_id'));
            $trip->setCompany($company);
            $trip->setPrice($request->request->get('price'));
            $trip->setDepartureCity($request->request->get('departure_city'));
            $trip->setArrivalCity($request->request->get('arrival_city'));
            
            $departureTime = \DateTime::createFromFormat('H:i', $request->request->get('departure_time'));
            $arrivalTime = \DateTime::createFromFormat('H:i', $request->request->get('arrival_time'));
            
            $trip->setDepartureTime($departureTime);
            $trip->setArrivalTime($arrivalTime);
            $trip->setDuration($request->request->get('duration'));
            $trip->setTotalSeats((int)$request->request->get('total_seats'));
            $trip->setAvailableSeats((int)$request->request->get('total_seats'));
            $trip->setIsActive(true);
            
            $em->persist($trip);
            $em->flush();

            $this->addFlash('success', 'Trajet créé avec succès !');
            return $this->redirectToRoute('admin_trajets');
        }

        $companies = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_COMPANY%')
            ->orderBy('u.companyName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/trip_create.html.twig', [
            'companies' => $companies
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_trip_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        TripRepository $tripRepo,
        UserRepository $userRepo,
        EntityManagerInterface $em
    ): Response {
        $trip = $tripRepo->find($id);
        
        if (!$trip) {
            throw $this->createNotFoundException('Trajet introuvable');
        }

        if ($request->isMethod('POST')) {
            $company = $userRepo->find($request->request->get('company_id'));
            $trip->setCompany($company);
            $trip->setPrice($request->request->get('price'));
            $trip->setDepartureCity($request->request->get('departure_city'));
            $trip->setArrivalCity($request->request->get('arrival_city'));
            
            $departureTime = \DateTime::createFromFormat('H:i', $request->request->get('departure_time'));
            $arrivalTime = \DateTime::createFromFormat('H:i', $request->request->get('arrival_time'));
            
            $trip->setDepartureTime($departureTime);
            $trip->setArrivalTime($arrivalTime);
            $trip->setDuration($request->request->get('duration'));
            $trip->setTotalSeats((int)$request->request->get('total_seats'));
            
            $em->flush();

            $this->addFlash('success', 'Trajet modifié avec succès !');
            return $this->redirectToRoute('admin_trajets');
        }

        $companies = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_COMPANY%')
            ->orderBy('u.companyName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/trip_edit.html.twig', [
            'trip' => $trip,
            'companies' => $companies
        ]);
    }
}