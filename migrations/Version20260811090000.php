<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the User.roles grant list.
 *
 * Access to the operator dashboard (/api/admin/*) is the first role-based rule
 * in the app; until now getRoles() returned a hardcoded ['ROLE_USER'] and there
 * was nothing to grant. ROLE_USER stays implied rather than stored, so an
 * ordinary member's column is `[]` and no backfill can drift out of step.
 *
 * The column is deliberately plain JSON, not JSONB: it is never queried into
 * (users are always loaded whole), and Doctrine's `json` type maps to JSON on
 * PostgreSQL — a hand-written JSONB column would make every future
 * migrations:diff emit a phantom ALTER ... TYPE JSON.
 *
 * NOT NULL DEFAULT '[]' covers every existing row: PostgreSQL 11+ fills an added
 * column from its default without rewriting the table, so no UPDATE is needed.
 *
 * user_audit needs no change — it stores a generic `diffs JSON` column, so
 * grants and revocations are recorded with their acting user for free.
 */
final class Version20260811090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.roles (JSON grant list) — the first role-based access rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD roles JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP roles');
    }
}
