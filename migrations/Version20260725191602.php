<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725191602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_read flag to book';
    }

    public function up(Schema $schema): void
    {
        // Add with a default so existing rows backfill to unread, then drop the
        // default to match the entity (read flag is set explicitly on write).
        $this->addSql('ALTER TABLE book ADD is_read BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE book ALTER is_read DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book DROP is_read');
    }
}
