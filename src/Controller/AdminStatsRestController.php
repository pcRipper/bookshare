<?php

namespace App\Controller;

use App\Dto\StatsWindow;
use App\Security\AdminAccess;
use App\Service\Analytics\StatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
#[IsGranted(AdminAccess::ROLE, message: AdminAccess::DENIED_MESSAGE)]
class AdminStatsRestController extends AbstractController
{
    public function __construct(private readonly StatsProvider $stats) {}

    /**
     * One endpoint for all four sections rather than four.
     *
     * It is one dashboard, rendered in one paint, by one user, that always loads
     * whole. Splitting it would buy a partial-refresh capability nothing wants in
     * exchange for four round-trips, four authorization checks and four ways to
     * end up half-rendered. If a section later grows expensive, that section
     * splits off — there is no need to pre-split.
     *
     * No flush(): nothing here writes.
     */
    #[Route('', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        return $this->json($this->stats->dashboard(StatsWindow::fromRequest($request)));
    }
}
