<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621155808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ON DELETE rules so deleting a book detaches its activity entries (SET NULL) '
            . 'and cascades away its library requests and their events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_item DROP CONSTRAINT fk_1cdffe8cddca0aea');
        $this->addSql('ALTER TABLE activity_item DROP CONSTRAINT fk_1cdffe8c6c066afe');
        $this->addSql('ALTER TABLE activity_item ADD CONSTRAINT FK_1CDFFE8CDDCA0AEA FOREIGN KEY (target_book_id) REFERENCES book (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_item ADD CONSTRAINT FK_1CDFFE8C6C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE library_request DROP CONSTRAINT fk_415637af16a2b381');
        $this->addSql('ALTER TABLE library_request ADD CONSTRAINT FK_415637AF16A2B381 FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE library_request_event DROP CONSTRAINT fk_d5e3961f427eb8a5');
        $this->addSql('ALTER TABLE library_request_event ADD CONSTRAINT FK_D5E3961F427EB8A5 FOREIGN KEY (request_id) REFERENCES library_request (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_item DROP CONSTRAINT FK_1CDFFE8CDDCA0AEA');
        $this->addSql('ALTER TABLE activity_item DROP CONSTRAINT FK_1CDFFE8C6C066AFE');
        $this->addSql('ALTER TABLE activity_item ADD CONSTRAINT fk_1cdffe8cddca0aea FOREIGN KEY (target_book_id) REFERENCES book (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE activity_item ADD CONSTRAINT fk_1cdffe8c6c066afe FOREIGN KEY (target_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE library_request DROP CONSTRAINT FK_415637AF16A2B381');
        $this->addSql('ALTER TABLE library_request ADD CONSTRAINT fk_415637af16a2b381 FOREIGN KEY (book_id) REFERENCES book (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE library_request_event DROP CONSTRAINT FK_D5E3961F427EB8A5');
        $this->addSql('ALTER TABLE library_request_event ADD CONSTRAINT fk_d5e3961f427eb8a5 FOREIGN KEY (request_id) REFERENCES library_request (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
