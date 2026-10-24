<?php

namespace App\Command;

use App\Service\Admin\Sql\SandboxConnection;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lets the `bookshare_sandbox` role log in, with the password from
 * SANDBOX_DATABASE_URL — or, with --disable, takes that away again.
 *
 * The migration creates the role NOLOGIN on purpose: a password does not belong
 * in a checked-in file, and the role must exist (with its grants) on every
 * database whether or not anyone means to use the sandbox there. Runs on the
 * application's own connection, which is the one allowed to ALTER ROLE.
 *
 * Idempotent: re-running it just re-sets the same password.
 */
#[AsCommand(
    name: 'app:sql-sandbox:provision',
    description: 'Enable (or disable) login for the restricted SQL sandbox database role',
)]
class ProvisionSqlSandboxCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SandboxConnection $sandbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('disable', null, InputOption::VALUE_NONE, 'Revoke the role\'s ability to log in.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = SandboxConnection::ROLE;

        $exists = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]);
        if (!$exists) {
            $io->error(sprintf('Role %s does not exist. Run the migrations first.', $role));

            return Command::FAILURE;
        }

        if ($input->getOption('disable')) {
            $this->connection->executeStatement(sprintf('ALTER ROLE %s NOLOGIN PASSWORD NULL', $role));
            $io->success(sprintf('%s can no longer log in.', $role));

            return Command::SUCCESS;
        }

        $password = $this->sandbox->password();
        if ($password === null) {
            $io->error(sprintf(
                'SANDBOX_DATABASE_URL is blank or does not log in as %s with a password.',
                $role,
            ));

            return Command::FAILURE;
        }

        // ALTER ROLE takes no bind parameters; quote() is the driver's literal
        // escaping, and the value is the operator's own configuration anyway.
        $this->connection->executeStatement(sprintf(
            'ALTER ROLE %s LOGIN PASSWORD %s',
            $role,
            $this->connection->quote($password),
        ));

        // Prove the URL actually works, so a typo surfaces here rather than as a
        // disabled panel later.
        $this->sandbox->get()->fetchOne('SELECT 1');

        $io->success(sprintf('%s can log in; the SQL sandbox is available.', $role));

        return Command::SUCCESS;
    }
}
