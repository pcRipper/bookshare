<?php

namespace App\Controller;

use App\Api\ResponseMapper;
use App\Dto\IntercomLetterInput;
use App\Entity\IntercomLetter;
use App\Entity\User;
use App\Security\AdminAccess;
use App\Service\Admin\IntercomService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Intercom — the letters an operator sends by hand.
 *
 * POST /send is the one route here that writes — it records the letter — and it
 * flushes once, at the controller boundary, as everything else does. What it
 * mainly changes is mail on its way out, which is why it is the most carefully
 * guarded route in the application — it is the only one whose blast
 * radius is other people's inboxes. It carries its own rate limiter
 * (`admin_intercom`, see RateLimitSubscriber) on top of the admin gate.
 *
 * Gated like every /api/admin controller; see App\Security\AdminAccess.
 */
#[Route('/admin/intercom')]
#[IsGranted(AdminAccess::ROLE, message: AdminAccess::DENIED_MESSAGE)]
class AdminIntercomRestController extends AbstractController
{
    public function __construct(
        private readonly IntercomService $intercom,
        private readonly ResponseMapper $mapper,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * How many members a letter would reach right now.
     *
     * Its own endpoint because the number is the single most important thing on
     * the compose screen: nobody has opted in by default, so an operator who
     * cannot see the count would compose a letter for an audience of nobody.
     */
    #[Route('/audience', methods: ['GET'])]
    public function audience(): JsonResponse
    {
        return $this->json(['recipients' => $this->intercom->audienceSize()]);
    }

    /** Send the composed letter to the operator alone, before the real thing. */
    #[Route('/test', methods: ['POST'])]
    public function test(#[MapRequestPayload] IntercomLetterInput $letter): JsonResponse
    {
        /** @var User $operator */
        $operator = $this->getUser();

        return $this->json(['queued' => $this->intercom->sendTest($operator, $letter)]);
    }

    /** Send it to everyone who opted in, and record that it went out. */
    #[Route('/send', methods: ['POST'])]
    public function send(#[MapRequestPayload] IntercomLetterInput $letter): JsonResponse
    {
        /** @var User $operator */
        $operator = $this->getUser();

        $summary = $this->intercom->send($letter, $operator);
        $this->em->flush();

        return $this->json($summary);
    }

    /**
     * What has already been sent — the panel's memory, and the only thing that
     * stops the same round-up going out twice.
     */
    #[Route('/letters', methods: ['GET'])]
    public function letters(): JsonResponse
    {
        return $this->json([
            'items' => array_map(
                fn (IntercomLetter $l) => $this->mapper->intercomLetter($l),
                $this->intercom->history(),
            ),
        ]);
    }
}
