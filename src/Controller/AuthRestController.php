<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\AvatarLocalizer;
use App\Service\GoogleAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;

#[Route('/auth')]
class AuthRestController extends AbstractController
{
    #[Required]
    public EntityManagerInterface $entityManager;

    #[Route('/google', methods: ['GET'])]
    public function googleAuth(GoogleAuthService $google): JsonResponse
    {
        return $this->json(['url' => $google->getAuthorizationUrl()]);
    }

    #[Route('/google/callback', methods: ['POST'])]
    public function googleCallback(
        Request $request,
        GoogleAuthService $google,
        UserRepository $users,
        AvatarLocalizer $avatars,
        JWTTokenManagerInterface $jwt,
    ): JsonResponse {
        $code = $request->toArray()['code'] ?? null;
        if (!$code) {
            return $this->json(['error' => 'Missing authorization code'], 400);
        }

        try {
            $info = $google->fetchUserInfo($code);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Google authentication failed'], 400);
        }

        $user = $users->findOrCreateFromGoogle(
            googleId: $info['sub'],
            email: $info['email'],
            fullName: $info['name'],
        );

        // Re-sync the avatar from Google, downloading it to our own origin so the
        // browser never hotlinks (and gets 429'd by) Google's CDN. We only touch
        // avatars we own — a fresh user (null), one we previously localized, or a
        // legacy Google URL — never an external URL the user pasted in Settings.
        $current = $user->getAvatarUrl();
        $isOurs = $current === null || $current === ''
            || str_starts_with($current, AvatarLocalizer::PUBLIC_PREFIX)
            || str_contains($current, 'googleusercontent.com');
        if ($isOurs && ($picture = $info['picture'] ?? null)) {
            $user->setAvatarUrl($avatars->localize($picture));
        }

        $this->entityManager->flush();

        return $this->json([
            'token' => $jwt->create($user),
            'user'  => [
                'id'        => $user->getId(),
                'email'     => $user->getEmail(),
                'fullName'  => $user->getFullName(),
                'avatarUrl' => $user->getAvatarUrl(),
            ],
        ]);
    }
}
