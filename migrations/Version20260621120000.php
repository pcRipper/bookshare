<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the `is_private` profile-visibility flag to users. Defaults to false
 * (public) so existing accounts keep appearing in Discover.
 */
final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_private flag to user for hidden profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD is_private BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP is_private');
    }
}
