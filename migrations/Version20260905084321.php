<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ban and soft-delete state on `user`.
 *
 * Hand-trimmed: doctrine:migrations:diff also emitted DROP INDEX for every
 * partial/expression index in the schema (it cannot see their WHERE clauses, so
 * it reads them as drift) plus the usual two phantom `user` statements — the
 * identity re-declaration and the created_at comment. All of those are stripped;
 * only the three new columns are real.
 */
final class Version20260905084321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ban and soft-delete state to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD banned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD ban_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP banned_at');
        $this->addSql('ALTER TABLE "user" DROP ban_reason');
        $this->addSql('ALTER TABLE "user" DROP deleted_at');
    }
}
