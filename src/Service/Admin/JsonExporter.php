<?php

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;

/**
 * Writes every table's rows to a JSON file.
 *
 * **Table-by-table through DBAL, not entity-by-entity through the ORM.** The
 * point of this file is to show what is actually stored, so going through the
 * mapping layer would be exactly wrong: it would silently drop the join tables
 * and the audit trail (no entities), re-key columns into API names, and drift
 * every time a mapping changed. Raw rows need no mapper and cannot disagree
 * with the database.
 *
 * **It is not a backup.** No schema, no sequences, no constraints, no indexes —
 * `pg_dump` is the restorable kind, and the UI labels them differently for this
 * reason. What this is good for is reading: grepping a value, diffing two days,
 * handing someone a copy of their own rows.
 *
 * Streamed a row at a time rather than assembled in memory: the whole point of
 * an export is that it runs when the data is too big to look at by hand.
 */
class JsonExporter
{
    /**
     * Bookkeeping rather than data. The migration ledger describes a schema this
     * file does not carry, and the queue is transient state whose rows are
     * meaningless an hour later — both would be noise in every diff.
     */
    private const SKIP_TABLES = ['doctrine_migration_versions', 'messenger_messages'];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @throws \RuntimeException when the file cannot be written
     */
    public function exportTo(string $target): void
    {
        $handle = @fopen($target, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open ' . $target . ' for writing.');
        }

        try {
            $this->write($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function write($handle): void
    {
        $tables = array_values(array_filter(
            array_map(self::unquote(...), $this->connection->createSchemaManager()->listTableNames()),
            static fn (string $table) => !\in_array($table, self::SKIP_TABLES, true),
        ));
        sort($tables);

        // Written by hand rather than json_encode()'d whole — the document is
        // built incrementally so no single array of rows is ever fully in
        // memory. Pretty-printed two levels deep, because it exists to be read.
        fwrite($handle, "{\n");
        fwrite($handle, '  "exportedAt": ' . json_encode(
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            \JSON_THROW_ON_ERROR,
        ) . ",\n");
        fwrite($handle, "  \"tables\": {\n");

        foreach ($tables as $i => $table) {
            fwrite($handle, '    ' . json_encode($table, \JSON_THROW_ON_ERROR) . ": [\n");
            $this->writeRows($handle, $table);
            fwrite($handle, '    ]' . ($i === array_key_last($tables) ? '' : ',') . "\n");
        }

        fwrite($handle, "  }\n}\n");
    }

    /**
     * The schema manager returns reserved-word tables already quoted (`"user"`),
     * so the name has to be normalised before we quote it ourselves — otherwise
     * the query asks for a table literally called `"user"`, quotes included, and
     * Postgres quite rightly says it does not exist.
     */
    private static function unquote(string $table): string
    {
        return trim($table, '"');
    }

    /** @param resource $handle */
    private function writeRows($handle, string $table): void
    {
        // The identifier comes from the schema manager, never from a request —
        // there is no user input anywhere near this string. Quoted anyway, so a
        // table named after a reserved word can't break the query.
        $sql = 'SELECT * FROM ' . $this->connection->quoteIdentifier($table);

        $first = true;
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            if (!$first) {
                fwrite($handle, ",\n");
            }
            $first = false;

            // Partial output is worse than none: a row that will not encode
            // (invalid UTF-8 in a text column) must fail the export, not leave a
            // truncated document that still parses.
            fwrite($handle, '      ' . json_encode($row, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        }

        if (!$first) {
            fwrite($handle, "\n");
        }
    }
}
