<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the per-user UI language to user_settings.
 */
final class Version20260803195419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_settings.locale (UI language)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_settings ADD locale VARCHAR(5) DEFAULT \'en\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_settings DROP locale');
    }
}
