<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add a nullable language column (ISO 639-1 code) to the book table.
 */
final class Version20260628120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add book.language (ISO 639-1 code).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book ADD language VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book DROP language');
    }
}
