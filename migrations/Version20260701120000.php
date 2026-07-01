<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add a nullable description column to book — a free-text blurb/summary shown on
 * book cards (hover/tap) and editable in the Add/Edit dialog. Length is capped
 * at the DTO (2000), so the column is an unbounded TEXT.
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add book.description (optional free-text blurb).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book DROP description');
    }
}
