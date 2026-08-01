<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260801170610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the remote URL a localized book cover / avatar was downloaded from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book ADD cover_source_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD avatar_source_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book DROP cover_source_url');
        $this->addSql('ALTER TABLE "user" DROP avatar_source_url');
    }
}
