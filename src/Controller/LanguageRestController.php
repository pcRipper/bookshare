<?php

namespace App\Controller;

use App\Language\LanguageCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the language vocabulary (ISO 639-1 code + English name) so the SPA's
 * book-language dropdown and Discover filter share one canonical list instead
 * of duplicating it client-side.
 */
#[Route('/languages')]
class LanguageRestController extends AbstractController
{
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(LanguageCatalog::all());
    }
}
