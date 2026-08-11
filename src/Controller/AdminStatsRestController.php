<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The site operator's analytics dashboard: growth, engagement, traffic and
 * library health for the whole community, as opposed to the per-member counters
 * UserStatsProvider serves.
 *
 * Gated twice, on purpose. The access_control rule in security.yaml is the
 * coarse net — it covers any /api/admin controller added later, whether or not
 * whoever adds it remembers the attribute. The attribute is what supplies a
 * denial message we control: the firewall's own is Symfony's "Access Denied.",
 * which matches no catalog entry and would render English in all five languages.
 */
#[Route('/admin/stats')]
#[IsGranted('ROLE_ADMIN', message: 'Administrator access is required.')]
class AdminStatsRestController extends AbstractController
{
    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json([]);
    }
}
