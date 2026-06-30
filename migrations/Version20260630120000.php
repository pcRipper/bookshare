<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add a nullable message column to library_request_event — the optional short
 * note the owner can attach when declining a borrow request.
 */
final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add library_request_event.message (optional decline note, max 255).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_request_event ADD message VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_request_event DROP message');
    }
}
