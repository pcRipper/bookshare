<?php

namespace App\Controller;

use App\Api\ApiError;
use App\Dto\DumpFile;
use App\Enum\DumpKind;
use App\Security\AdminAccess;
use App\Service\Admin\DumpService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manual database dumps.
 *
 * No flush anywhere in here — nothing on this controller writes to the ORM. The
 * single-flush convention is about the transaction boundary, and there is no
 * transaction: the state this endpoint changes is files on disk.
 *
 * Gated like every /api/admin controller; see App\Security\AdminAccess.
 */
#[Route('/admin/dumps')]
#[IsGranted(AdminAccess::ROLE, message: AdminAccess::DENIED_MESSAGE)]
class AdminDumpRestController extends AbstractController
{
    public function __construct(
        private readonly DumpService $dumps,
        private readonly ApiError $errors,
    ) {}

    /**
     * The dumps that exist, plus which kinds this server can actually produce.
     *
     * `capabilities` is not decoration: `pg_dump` is absent outside the
     * container (a dev machine running PHP natively is the normal case), and a
     * button that always 503s is worse than a disabled one that says why.
     */
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json([
            'items' => array_map($this->shape(...), $this->dumps->list()),
            'capabilities' => [
                DumpKind::Sql->value => $this->dumps->supports(DumpKind::Sql),
                DumpKind::Json->value => $this->dumps->supports(DumpKind::Json),
            ],
        ]);
    }

    /**
     * Makes a dump. Rate-limited hard (`admin_dump`, 5/hour) — it is the most
     * expensive thing an authenticated request can ask this application to do.
     */
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $kind = DumpKind::tryFrom((string) ($request->toArray()['kind'] ?? ''));
        if ($kind === null) {
            return $this->errors->response('Unknown dump kind.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->dumps->supports($kind)) {
            return $this->errors->response(
                'This server cannot produce that kind of dump.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $dump = $this->dumps->create($kind);
        } catch (\Throwable) {
            // The reason is on the `error` log with the subprocess's stderr;
            // what reaches the operator is deliberately generic, since the
            // detail is a database connection string's worth of hints.
            return $this->errors->response('The dump could not be created.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json($this->shape($dump), Response::HTTP_CREATED);
    }

    /**
     * Serves one dump as a download.
     *
     * `$name` comes from the URL, so DumpService::path() is what makes this safe
     * — it validates the shape *and* confines the resolved path to the dump
     * directory. The route requirement below is a first filter only; it must not
     * be mistaken for the guard.
     */
    #[Route('/{name}', methods: ['GET'], requirements: ['name' => '[A-Za-z0-9.\-]+'])]
    public function download(string $name): Response
    {
        $path = $this->dumps->path($name);
        if ($path === null) {
            return $this->errors->response('Dump not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Type', 'application/octet-stream');
        // Never cached: the file is one URL per dump and carries every member's
        // personal data — it has no business in a shared proxy.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{name}', methods: ['DELETE'], requirements: ['name' => '[A-Za-z0-9.\-]+'])]
    public function delete(string $name): JsonResponse
    {
        if (!$this->dumps->delete($name)) {
            return $this->errors->response('Dump not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{name: string, kind: string, restorable: bool, bytes: int, createdAt: string}
     */
    private function shape(DumpFile $dump): array
    {
        return [
            'name' => $dump->name,
            'kind' => $dump->kind->value,
            // Emitted rather than re-derived in the SPA: whether a file can be
            // restored from is a property of the format, and the one thing a
            // reader must not have to guess about a backup.
            'restorable' => $dump->kind->isRestorable(),
            'bytes' => $dump->bytes,
            'createdAt' => $dump->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
