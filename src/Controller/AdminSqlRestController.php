<?php

namespace App\Controller;

use App\Api\ApiError;
use App\Api\ResponseMapper;
use App\Dto\Pagination;
use App\Dto\SqlSandboxInput;
use App\Entity\SqlSandboxEntry;
use App\Entity\User;
use App\Enum\SqlKind;
use App\Repository\SqlSandboxEntryRepository;
use App\Security\AdminAccess;
use App\Service\Admin\Sql\SandboxUnavailable;
use App\Service\Admin\Sql\SqlFailure;
use App\Service\Admin\Sql\SqlSandbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin SQL sandbox and its shared query log.
 *
 * Flushes exactly once per run, in `finally`: the SqlSandboxEntry is persisted
 * by SqlSandbox on every path — success, refusal, database error — and the
 * flush has to happen on all of them, or a rejected or failed attempt would
 * leave no trace. That flush is on the application's connection; the statement
 * itself ran, and was committed or rolled back, on the sandbox's own.
 *
 * Gated like every /api/admin controller; see App\Security\AdminAccess.
 * Running a statement is rate-limited (`admin_sql`), reading the log is not.
 */
#[Route('/admin/sql')]
#[IsGranted(AdminAccess::ROLE, message: AdminAccess::DENIED_MESSAGE)]
class AdminSqlRestController extends AbstractController
{
    private const LOG_PER_PAGE = 20;

    public function __construct(
        private readonly SqlSandbox $sandbox,
        private readonly SqlSandboxEntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly ResponseMapper $mapper,
        private readonly ApiError $errors,
    ) {}

    /**
     * Whether this server can run the sandbox, and the limits it runs under —
     * emitted so the panel states them rather than holding copies that drift.
     */
    #[Route('', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json([
            'capabilities' => ['sandbox' => $this->sandbox->isAvailable()],
            'limits' => [
                'maxRows' => SqlSandbox::MAX_ROWS,
                'maxAffected' => SqlSandbox::MAX_AFFECTED,
                'timeoutMs' => SqlSandbox::TIMEOUT_MS,
                'maxLength' => SqlSandboxInput::MAX_LENGTH,
            ],
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function run(#[MapRequestPayload] SqlSandboxInput $input): JsonResponse
    {
        /** @var User $operator */
        $operator = $this->getUser();

        try {
            $result = $this->sandbox->run($operator, $input->sql, $input->commit, $input->expectedRows);
        } catch (\DomainException $e) {
            return $this->errors->fromDomain($e, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SqlFailure $e) {
            // The database's own words, untranslated: they are about the SQL
            // the operator wrote, and only make sense next to it.
            return $this->json(['error' => $e->getMessage(), 'sqlState' => $e->sqlState], Response::HTTP_BAD_REQUEST);
        } catch (SandboxUnavailable) {
            return $this->errors->response(SandboxUnavailable::MESSAGE, Response::HTTP_SERVICE_UNAVAILABLE);
        } finally {
            $this->em->flush();
        }

        if ($result->kind === SqlKind::Read) {
            return $this->json([
                'kind' => $result->kind->value,
                'columns' => $result->columns,
                'rows' => $result->rows,
                'rowCount' => $result->rowCount,
                'truncated' => $result->truncated,
                'durationMs' => $result->durationMs,
            ]);
        }

        return $this->json([
            'kind' => $result->kind->value,
            'rowCount' => $result->rowCount,
            'committed' => $result->committed,
            'durationMs' => $result->durationMs,
        ]);
    }

    /** The shared log: every operator's attempts, newest first. */
    #[Route('/log', methods: ['GET'])]
    public function log(Request $request): JsonResponse
    {
        $pagination = Pagination::fromRequest($request, self::LOG_PER_PAGE);
        $page = $this->entries->findPaginated($pagination);

        return $this->json($this->mapper->paginated(
            $page->items,
            $page->total,
            $pagination,
            fn (SqlSandboxEntry $entry) => $this->mapper->sqlSandboxEntry($entry),
        ));
    }
}
