<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add lender-set due date and return-confirmation timestamp to library_request,
 * supporting the borrow→return→confirm loan lifecycle. New RequestStatus enum
 * values reuse the existing VARCHAR status column, so no column change is needed
 * for those.
 */
final class Version20260621130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add due_date and returned_at to library_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_request ADD due_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE library_request ADD returned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_request DROP due_date');
        $this->addSql('ALTER TABLE library_request DROP returned_at');
    }
}
