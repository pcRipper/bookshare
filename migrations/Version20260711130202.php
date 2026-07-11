<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711130202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cascade collection_request rows when their collection is deleted, so a collection with borrow history can be removed (mirrors library_request.book_id ON DELETE CASCADE).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_request DROP CONSTRAINT fk_6fbbfd4e514956fd');
        $this->addSql('ALTER TABLE collection_request ADD CONSTRAINT FK_6FBBFD4E514956FD FOREIGN KEY (collection_id) REFERENCES book_collection (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_request DROP CONSTRAINT FK_6FBBFD4E514956FD');
        $this->addSql('ALTER TABLE collection_request ADD CONSTRAINT fk_6fbbfd4e514956fd FOREIGN KEY (collection_id) REFERENCES book_collection (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
