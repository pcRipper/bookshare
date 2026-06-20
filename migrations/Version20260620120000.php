<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user table for Google OAuth authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
                id         INT GENERATED ALWAYS AS IDENTITY NOT NULL,
                google_id  VARCHAR(255)                      NOT NULL,
                email      VARCHAR(255)                      NOT NULL,
                full_name  VARCHAR(255)                      NOT NULL,
                avatar_url VARCHAR(500)                      DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE    NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_GOOGLE_ID ON "user" (google_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_EMAIL    ON "user" (email)');
        $this->addSql("COMMENT ON COLUMN \"user\".created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE "user"');
    }
}
