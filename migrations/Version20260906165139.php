<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The owner's star rating on `book`.
 *
 * Hand-trimmed: doctrine:migrations:diff also emitted DROP INDEX for every
 * partial/expression index in the schema (it cannot see their WHERE clauses, so
 * it reads them as drift) plus the usual two phantom `user` statements — the
 * identity re-declaration and the created_at comment. Only the column is real.
 *
 * No `book_audit` change: that table stores a generic `diffs JSON`, so a new
 * column is recorded without a schema of its own.
 */
final class Version20260906165139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the owner rating to book';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book ADD rating SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book DROP rating');
    }
}
