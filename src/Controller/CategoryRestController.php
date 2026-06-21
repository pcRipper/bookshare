<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\CategoryInput;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/categories')]
class CategoryRestController extends AbstractController
{
    public function __construct(
        private readonly ResponseMapper $mapper,
        private readonly CategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Lists categories. With `?q=` it returns only matches — the search powering
     * the "search or create" picker. An empty result tells the UI to offer
     * creating a new category under that name.
     */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $results = $q === ''
            ? $this->categories->findAllOrdered()
            : $this->categories->search($q);

        return $this->json(array_map(
            fn (Category $c) => $this->mapper->category($c),
            $results,
        ));
    }

    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] CategoryInput $input): JsonResponse
    {
        $name = trim($input->name);
        if ($name === '') {
            return $this->json(['error' => 'Category name is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Categories are a shared, global vocabulary — names are unique.
        if ($this->categories->findOneByNameInsensitive($name)) {
            return $this->json(['error' => 'A category with this name already exists.'], Response::HTTP_CONFLICT);
        }

        $category = (new Category())
            ->setName($name)
            ->setColorHex($input->colorHex);

        $this->em->persist($category);
        $this->em->flush();

        return $this->json($this->mapper->category($category), Response::HTTP_CREATED);
    }
}
