<?php

namespace App\Tests\Service\Admin\Sql;

use App\Enum\SqlKind;
use App\Exception\DomainRuleException;
use App\Service\Admin\Sql\StatementGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard is defence in depth behind the sandbox role, so what matters here is
 * that it cannot be talked past by hiding a keyword where a naive scan would not
 * look — and that it does not refuse ordinary queries for keywords sitting
 * harmlessly inside a string or a comment.
 */
final class StatementGuardTest extends TestCase
{
    /** @return iterable<string, array{string, SqlKind}> */
    public static function accepted(): iterable
    {
        yield 'plain select' => ['SELECT * FROM book', SqlKind::Read];
        yield 'lowercase, trailing semicolon' => ['select id from book;', SqlKind::Read];
        yield 'several trailing semicolons' => ['SELECT 1;;  ', SqlKind::Read];
        yield 'leading comment' => ["-- look\nSELECT 1", SqlKind::Read];
        yield 'parenthesised select' => ['(SELECT 1) UNION (SELECT 2)', SqlKind::Read];
        yield 'read-only CTE' => ['WITH t AS (SELECT id FROM book) SELECT * FROM t', SqlKind::Read];
        yield 'keyword inside a string' => ["SELECT * FROM book WHERE title = 'insert; drop table book'", SqlKind::Read];
        yield 'escaped quote in a string' => ["SELECT 'it''s; delete'", SqlKind::Read];
        yield 'E-string with backslash quote' => ["SELECT E'a\\'; insert'", SqlKind::Read];
        yield 'dollar-quoted body' => ['SELECT $$ drop table book; $$', SqlKind::Read];
        yield 'tagged dollar quote' => ['SELECT $x$ ; insert $x$', SqlKind::Read];
        yield 'keyword in a block comment' => ['SELECT /* insert into */ 1', SqlKind::Read];
        yield 'nested block comment' => ['SELECT /* a /* insert */ b */ 1', SqlKind::Read];
        yield 'keyword as a quoted column' => ['SELECT "insert" FROM t', SqlKind::Read];
        yield 'quoted user table' => ['SELECT email FROM "user" WHERE id = 1', SqlKind::Read];
        yield 'update with where' => ["UPDATE book SET title = 'x' WHERE id = 1", SqlKind::Write];
        yield 'update with subquery in where' => ['UPDATE book SET status = \'own\' WHERE id IN (SELECT id FROM book WHERE false)', SqlKind::Write];
        yield 'delete with where' => ['DELETE FROM book WHERE id = 1', SqlKind::Write];
        yield 'delete using' => ['DELETE FROM book b USING "user" u WHERE b.owner_id = u.id AND u.id = 1', SqlKind::Write];
        yield 'update returning' => ['update book set title = title where id = 1 returning id', SqlKind::Write];
        yield 'harmless functions' => ['SELECT now(), lower(title), count(*) FROM book GROUP BY 2', SqlKind::Read];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $sql, SqlKind $kind): void
    {
        self::assertSame($kind, (new StatementGuard())->check($sql));
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield 'empty' => ['', 'Enter a statement to run.'];
        yield 'only a comment' => ['-- nothing', 'Enter a statement to run.'];
        yield 'insert' => ["INSERT INTO category (name) VALUES ('x')", 'INSERT statements are not allowed in the sandbox.'];
        yield 'insert lowercase' => ['  insert into book default values', 'INSERT statements are not allowed in the sandbox.'];
        yield 'drop' => ['DROP TABLE book', 'Only SELECT, UPDATE and DELETE statements can run in the sandbox.'];
        yield 'truncate' => ['TRUNCATE book', 'Only SELECT, UPDATE and DELETE statements can run in the sandbox.'];
        yield 'explain analyze' => ['EXPLAIN ANALYZE DELETE FROM book', 'Only SELECT, UPDATE and DELETE statements can run in the sandbox.'];
        yield 'set role' => ['SET ROLE app', 'Only SELECT, UPDATE and DELETE statements can run in the sandbox.'];
        yield 'two statements' => ['SELECT 1; DELETE FROM book WHERE true', 'Only one statement can run at a time.'];
        yield 'statement after a comment' => ["SELECT 1; -- \n INSERT INTO x VALUES (1)", 'Only one statement can run at a time.'];
        yield 'select into' => ['SELECT * INTO copy_of_book FROM book', '%keyword% is not allowed in the sandbox.'];
        yield 'insert in a CTE' => ['WITH x AS (INSERT INTO category (name) VALUES (1) RETURNING id) SELECT * FROM x', '%keyword% is not allowed in the sandbox.'];
        yield 'delete in a CTE' => ['WITH x AS (DELETE FROM book WHERE true RETURNING id) SELECT * FROM x', 'A query may only modify data at its top level; data-modifying subqueries are not allowed.'];
        yield 'CTE wrapping an update' => ['WITH x AS (SELECT 1) UPDATE book SET title = title WHERE true', 'A query may only modify data at its top level; data-modifying subqueries are not allowed.'];
        yield 'select for update' => ['SELECT * FROM book FOR UPDATE', 'A query may only modify data at its top level; data-modifying subqueries are not allowed.'];
        yield 'update without where' => ["UPDATE book SET title = 'x'", 'UPDATE and DELETE need a WHERE clause.'];
        yield 'delete without where' => ['DELETE FROM book', 'UPDATE and DELETE need a WHERE clause.'];
        yield 'where only in a subquery' => ['DELETE FROM book USING (SELECT id FROM book WHERE true) s', 'UPDATE and DELETE need a WHERE clause.'];
        yield 'where hidden in a comment' => ['DELETE FROM book /* WHERE id = 1 */', 'UPDATE and DELETE need a WHERE clause.'];
        yield 'second set in an update' => ["UPDATE book SET title = (SELECT set_config('role', 'app', true)) WHERE true", 'The function %name% is not allowed in the sandbox.'];
        yield 'set_config' => ["SELECT set_config('role', 'app', false)", 'The function %name% is not allowed in the sandbox.'];
        yield 'schema-qualified set_config' => ["SELECT pg_catalog.set_config('role', 'app', false)", 'The function %name% is not allowed in the sandbox.'];
        yield 'quoted function name' => ["SELECT \"set_config\"('role', 'app', false)", 'The function %name% is not allowed in the sandbox.'];
        yield 'uppercase function' => ['SELECT PG_SLEEP(10)', 'The function %name% is not allowed in the sandbox.'];
        yield 'pg_read_file' => ["SELECT pg_read_file('/etc/passwd')", 'The function %name% is not allowed in the sandbox.'];
        yield 'terminate backend' => ['SELECT pg_terminate_backend(pid) FROM pg_stat_activity', 'The function %name% is not allowed in the sandbox.'];
        yield 'advisory lock' => ['SELECT pg_advisory_lock(1)', 'The function %name% is not allowed in the sandbox.'];
        yield 'query smuggled as text' => ["SELECT query_to_xml('insert into category values (1)', true, true, '')", 'The function %name% is not allowed in the sandbox.'];
        yield 'ts_stat smuggling' => ["SELECT * FROM ts_stat('delete from book')", 'The function %name% is not allowed in the sandbox.'];
        yield 'large object import' => ["SELECT lo_import('/etc/passwd')", 'The function %name% is not allowed in the sandbox.'];
        yield 'unterminated string' => ["SELECT 'abc", 'The statement is not terminated: a quote or comment is left open.'];
        yield 'unterminated comment' => ['SELECT 1 /* oops', 'The statement is not terminated: a quote or comment is left open.'];
        yield 'unterminated dollar quote' => ['SELECT $a$ x', 'The statement is not terminated: a quote or comment is left open.'];
        yield 'unterminated identifier' => ['SELECT "abc', 'The statement is not terminated: a quote or comment is left open.'];
        yield 'string closing early hides a statement' => ["SELECT 'a'';' ; DELETE FROM book WHERE true", 'Only one statement can run at a time.'];
        yield 'lock' => ['SELECT 1 FROM book WHERE id IN (SELECT 1) LOCK', '%keyword% is not allowed in the sandbox.'];
    }

    #[DataProvider('rejected')]
    public function testRejected(string $sql, string $messageId): void
    {
        try {
            (new StatementGuard())->check($sql);
            self::fail('Accepted: ' . $sql);
        } catch (DomainRuleException $e) {
            self::assertSame($messageId, $e->id);
        }
    }

    public function testRejectionNamesTheKeyword(): void
    {
        try {
            (new StatementGuard())->check('SELECT * INTO t FROM book');
            self::fail('Accepted SELECT INTO');
        } catch (DomainRuleException $e) {
            self::assertSame(['%keyword%' => 'INTO'], $e->params);
        }
    }
}
