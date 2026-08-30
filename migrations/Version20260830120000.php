<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the wish list: books a member *wants*, kept on the book table beside the
 * ones they hold (see Book::$isWished for why they share a table).
 *
 * `is_wished` is NOT NULL DEFAULT false, so every existing row becomes what it
 * already was — an owned book — with no backfill. `wish_priority` is a nullable
 * smallint holding WishPriority's backing value; null exactly when the row is
 * not wished, which the entity keeps true and this migration establishes for the
 * existing rows for free.
 *
 * The partial index is the shape every wish-list query has: owner + is_wished,
 * ordered by priority. It is partial because wish-list rows are a small minority
 * of the table, and the *other* side of the split — the ordinary library
 * queries, which now all carry `is_wished = false` — is already served by the
 * existing owner and created_at indexes.
 */
final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the wish-list flag and priority to book';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book ADD is_wished BOOLEAN DEFAULT FALSE NOT NULL');
        // The default exists only to fill the existing rows in the statement
        // above — the entity always writes the flag explicitly. Dropping it here
        // keeps the column matching the mapping, which has no `default` option;
        // leaving it would make every future migrations:diff ask for this line.
        $this->addSql('ALTER TABLE book ALTER is_wished DROP DEFAULT');
        $this->addSql('ALTER TABLE book ADD wish_priority SMALLINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_book_wishlist ON book (owner_id, wish_priority DESC) WHERE is_wished = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_book_wishlist');
        $this->addSql('ALTER TABLE book DROP wish_priority');
        $this->addSql('ALTER TABLE book DROP is_wished');
    }
}
