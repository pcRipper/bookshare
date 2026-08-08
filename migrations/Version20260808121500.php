<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets user_settings.locale be null.
 *
 * A NOT NULL DEFAULT 'en' made "never picked a language" indistinguishable from
 * "picked English", so opening Settings pushed the stored default over the
 * language the browser had negotiated. Null now means "no choice made".
 */
final class Version20260808121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make user_settings.locale nullable (null = no explicit choice)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_settings ALTER COLUMN locale DROP DEFAULT');
        $this->addSql('ALTER TABLE user_settings ALTER COLUMN locale DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE user_settings SET locale = 'en' WHERE locale IS NULL");
        $this->addSql("ALTER TABLE user_settings ALTER COLUMN locale SET DEFAULT 'en'");
        $this->addSql('ALTER TABLE user_settings ALTER COLUMN locale SET NOT NULL');
    }
}
