<?php

namespace App\Controller;

use App\Dto\PageViewInput;
use App\Entity\User;
use App\Service\Analytics\PageViewRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Traffic ingest for signed-in members. The signed-out twin lives on
 * PublicRestController, which inherits the `security: false` firewall so a share
 * page visitor — or a member whose token went stale — is counted rather than
 * 401-ed off the page.
 *
 * Named /pageviews rather than /analytics/... on purpose: content blockers match
 * `analytics` in a URL path, so that name would systematically drop the most
 * technical slice of the audience from the numbers while looking like it worked.
 * The `analytics` vocabulary lives in the PHP namespaces, where no blocklist
 * sees it.
 *
 * Answers 204: there is nothing to say, and it keeps the beacon's response off
 * the wire. No flush() — PageViewRecorder writes its two counters itself, for
 * the reasons documented there.
 */
#[Route('/pageviews')]
class PageViewRestController extends AbstractController
{
    #[Route('', methods: ['POST'])]
    public function record(
        #[MapRequestPayload] PageViewInput $input,
        Request $request,
        PageViewRecorder $recorder,
    ): Response {
        $user = $this->getUser();
        $recorder->record(
            $input->route,
            $request,
            $user instanceof User ? $user->getId() : null,
        );

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
