<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grants or revokes ROLE_ADMIN, which is what opens the operator dashboard at
 * /api/admin/*. This is the only way to hand out the role: there is deliberately
 * no API endpoint for it.
 *
 * Deliberately goes through the ORM rather than raw SQL. Two reasons: `User` is
 * on the auditor whitelist, so every grant and revocation lands in `user_audit`
 * with its acting context; and a hand-written JSON literal that is subtly
 * malformed would break getRoles() for that user on their next request.
 *
 * Idempotent — granting twice is a no-op, and so is revoking from a member who
 * never had it. Roles are read from the row on every request (the firewall
 * reloads the user through the entity provider), so a change takes effect on the
 * user's next request with no re-login and no token refresh.
 */
#[AsCommand(
    name: 'app:grant-admin',
    description: 'Grant or revoke the administrator role for a user',
)]
class GrantAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the user to grant or revoke.');
        $this->addOption('revoke', null, InputOption::VALUE_NONE, 'Remove the administrator role instead of granting it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $revoke = (bool) $input->getOption('revoke');

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No user with the email %s', $email));

            return Command::FAILURE;
        }

        if ($user->isAdmin() === !$revoke) {
            $io->note(sprintf('%s is already %s', $email, $revoke ? 'not an administrator' : 'an administrator'));

            return Command::SUCCESS;
        }

        // getRoles() merges ROLE_USER in; filtering it back out here keeps the
        // stored column meaning "extra grants only".
        $roles = array_filter(
            $user->getRoles(),
            static fn (string $role) => $role !== 'ROLE_USER' && $role !== User::ROLE_ADMIN,
        );
        if (!$revoke) {
            $roles[] = User::ROLE_ADMIN;
        }

        $user->setRoles($roles);
        $this->em->flush();

        $io->success(sprintf(
            '%s %s administrator access',
            $email,
            $revoke ? 'no longer has' : 'now has',
        ));

        return Command::SUCCESS;
    }
}
