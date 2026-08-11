<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes the created_at columns the operator dashboard scans by date range.
 *
 * The growth and engagement series filter on `created_at >= :since` and group by
 * day, which is the app's first date-range scan over these tables — until now
 * they were only ever read by owner or by id.
 *
 * Two of these pay for themselves outside the dashboard: book.created_at is
 * already the sort key of BookRepository::findByOwnerPaginated and
 * findRecentByOwner, and activity_item.created_at of ActivityItemRepository::
 * findRecent, all three of which have been sorting on an unindexed column.
 *
 * Declared as raw indexes rather than #[ORM\Index] attributes on the entities:
 * they exist for query plans, not for the domain model, and adding them to the
 * mapping would put five index declarations on entities whose own behaviour is
 * unaffected.
 */
final class Version20260811210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index created_at on the tables the analytics date-range queries scan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_user_created_at ON "user" (created_at)');
        $this->addSql('CREATE INDEX idx_book_created_at ON book (created_at)');
        $this->addSql('CREATE INDEX idx_book_collection_created_at ON book_collection (created_at)');
        $this->addSql('CREATE INDEX idx_activity_item_created_at ON activity_item (created_at)');
        $this->addSql('CREATE INDEX idx_library_request_event_created_at ON library_request_event (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_created_at');
        $this->addSql('DROP INDEX idx_book_created_at');
        $this->addSql('DROP INDEX idx_book_collection_created_at');
        $this->addSql('DROP INDEX idx_activity_item_created_at');
        $this->addSql('DROP INDEX idx_library_request_event_created_at');
    }
}
