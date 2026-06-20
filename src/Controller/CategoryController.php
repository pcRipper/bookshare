<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/categories')]
class CategoryController extends AbstractController
{
    public function __construct(private readonly ResponseMapper $mapper) {}

    #[Route('', methods: ['GET'])]
    public function list(CategoryRepository $categories): JsonResponse
    {
        return $this->json(array_map(
            fn ($c) => $this->mapper->category($c),
            $categories->findAllOrdered(),
        ));
    }
}
