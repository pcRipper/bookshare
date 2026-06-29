<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mints the Mercure subscribe-cookie for the current user. EventSource cannot send
 * the JWT Bearer header, so the SPA calls this (Bearer-authenticated, behind the
 * /api firewall) on startup; we reply with a signed `mercureAuthorization` cookie
 * scoped to the caller's own `user/{id}` topic. The hub then accepts that browser's
 * private subscription to its own topic only — no cross-user eavesdropping.
 */
#[Route('/mercure')]
class MercureRestController extends AbstractController
{
    #[Route('/token', methods: ['GET'])]
    public function token(Request $request, Authorization $authorization): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // The cookie is path-scoped to the hub endpoint and grants subscription to
        // this user's topic alone. Attaching it to the response is all that's needed.
        $cookie = $authorization->createCookie($request, [sprintf('user/%d', $user->getId())]);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
