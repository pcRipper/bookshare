<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records when each loan reminder was mailed, on both request tables.
 *
 * Two nullable timestamps rather than a reminder-log entity: the only question
 * asked of them is "has this one gone out yet", and as columns the answer joins
 * the query that finds the loans to remind about — which is what makes
 * app:send-loan-reminders idempotent, so a cron that fires twice mails once.
 *
 * Nullable with no default, so every existing loan starts as "not yet reminded".
 * That is the honest state: a loan already overdue when this ships gets exactly
 * one chase mail on the next run, which is the intended behaviour and not a
 * backfill.
 *
 * Both partial indexes are the reminder query's exact shape — due date within a
 * window, that reminder not yet sent — and partial because the rows that matter
 * are the small live minority; the settled tail is never scanned for reminders.
 */
final class Version20260831093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add loan reminder sent-at timestamps to library_request and collection_request';
    }

    public function up(Schema $schema): void
    {
        foreach (['library_request', 'collection_request'] as $table) {
            $this->addSql("ALTER TABLE {$table} ADD due_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("ALTER TABLE {$table} ADD overdue_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("CREATE INDEX idx_{$table}_due_reminder ON {$table} (due_date) WHERE due_reminder_sent_at IS NULL");
            $this->addSql("CREATE INDEX idx_{$table}_overdue_reminder ON {$table} (due_date) WHERE overdue_reminder_sent_at IS NULL");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['library_request', 'collection_request'] as $table) {
            $this->addSql("DROP INDEX idx_{$table}_overdue_reminder");
            $this->addSql("DROP INDEX idx_{$table}_due_reminder");
            $this->addSql("ALTER TABLE {$table} DROP overdue_reminder_sent_at");
            $this->addSql("ALTER TABLE {$table} DROP due_reminder_sent_at");
        }
    }
}
