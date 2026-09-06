<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The owner's written review on `book`, alongside the rating it accompanies.
 *
 * Hand-trimmed the way Version20260906165139 was: diff also emits DROP INDEX for
 * every partial index (it cannot see their WHERE clauses) plus the two phantom
 * `user` statements. Only the column is real, and `book_audit` needs nothing —
 * it stores a generic `diffs JSON`.
 */
final class Version20260906172505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the owner review to book';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book ADD review TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book DROP review');
    }
}
