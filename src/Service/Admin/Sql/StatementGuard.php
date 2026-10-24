<?php

namespace App\Service\Admin\Sql;

use App\Enum\SqlKind;
use App\Exception\DomainRuleException;

/**
 * Decides whether a sandbox statement may run, and whether it reads or writes.
 *
 * **This is not the security boundary.** The `bookshare_sandbox` role is: it has
 * no INSERT anywhere, no write on the audit trail, no access to its own log and
 * no route back to the application's superuser (see the migration). What this
 * class adds is defence in depth and clear refusals — "INSERT is not allowed"
 * beats "permission denied for table category" — plus the two rules a grant
 * cannot express: one statement at a time, and a WHERE on every write.
 *
 * Keywords are only meaningful outside literals, so the statement is lexed
 * first, PostgreSQL-style: `--` and nested block comments are dropped, `'…'`,
 * `E'…'` and `$tag$…$tag$` bodies become opaque, and `"quoted"` identifiers are
 * kept as names (they are case-exact, and can still name a function) but never
 * read as keywords. A keyword hidden in a comment or a string therefore neither
 * triggers a refusal nor slips past one.
 */
final class StatementGuard
{
    private const ALLOWED_LEADS = ['select', 'with', 'update', 'delete'];

    /**
     * Refused wherever they appear outside a literal. `set` is the one with an
     * exception — UPDATE's own SET clause (see check()).
     */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'merge', 'copy', 'into', 'create', 'alter', 'drop', 'truncate',
        'grant', 'revoke', 'set', 'reset', 'lock', 'call', 'do', 'execute',
        'prepare', 'deallocate', 'listen', 'unlisten', 'notify', 'vacuum',
        'analyze', 'analyse', 'refresh', 'commit', 'rollback', 'begin', 'start',
        'savepoint', 'release', 'discard', 'cluster', 'reindex', 'checkpoint',
        'comment', 'security', 'import', 'load',
    ];

    /**
     * Functions that act on the server, the session or other connections — or
     * that run a query handed to them as text, which would carry a statement
     * straight past every keyword rule above.
     */
    private const FORBIDDEN_FUNCTIONS = '/^(set_config|pg_sleep\w*|pg_terminate_backend|pg_cancel_backend'
        . '|pg_(try_)?advisory\w*|pg_notify|lo_\w+|dblink\w*|pg_read\w*|pg_ls_\w+|pg_stat_file'
        . '|pg_reload_conf|pg_rotate_logfile|pg_logical\w*|pg_replication\w*|pg_promote'
        . '|pg_switch_wal|pg_create\w*|pg_drop\w*|pg_file\w*|pg_import\w*'
        . '|(query|table|cursor|schema|database)_to_xml\w*|ts_stat|txid_\w+|pg_current_xact_id\w*)$/';

    /**
     * @throws DomainRuleException when the statement may not run
     */
    public function check(string $sql): SqlKind
    {
        $tokens = $this->lex($sql);

        if ($tokens === []) {
            throw new DomainRuleException('Enter a statement to run.');
        }

        // One statement: a `;` may only end it.
        foreach ($tokens as $i => $token) {
            if ($token['type'] === ';' && $this->hasMeaningfulTokenAfter($tokens, $i)) {
                throw new DomainRuleException('Only one statement can run at a time.');
            }
        }
        $tokens = array_values(array_filter($tokens, static fn (array $t) => $t['type'] !== ';'));

        $lead = null;
        foreach ($tokens as $token) {
            if ($token['type'] === 'word') {
                $lead = $token['value'];
                break;
            }
        }

        if ($lead === 'insert') {
            throw new DomainRuleException('INSERT statements are not allowed in the sandbox.');
        }
        if (!\in_array($lead, self::ALLOWED_LEADS, true)) {
            throw new DomainRuleException('Only SELECT, UPDATE and DELETE statements can run in the sandbox.');
        }

        $kind = \in_array($lead, ['update', 'delete'], true) ? SqlKind::Write : SqlKind::Read;

        $leadSeen = false;
        $updateSetSeen = false;
        $hasTopLevelWhere = false;

        foreach ($tokens as $i => $token) {
            if ($token['type'] === 'name' || $token['type'] === 'word') {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next['type'] === '(' && preg_match(self::FORBIDDEN_FUNCTIONS, $token['value'])) {
                    throw new DomainRuleException('The function %name% is not allowed in the sandbox.', [
                        '%name%' => $token['value'],
                    ]);
                }
            }

            if ($token['type'] !== 'word') {
                continue;
            }

            $word = $token['value'];

            // The statement's own leading UPDATE/DELETE; any other one is a
            // data-modifying subquery (or SELECT … FOR UPDATE, which a read-only
            // transaction would refuse anyway).
            if ($word === 'update' || $word === 'delete') {
                if ($kind === SqlKind::Write && !$leadSeen && $token['depth'] === 0) {
                    $leadSeen = true;
                    continue;
                }

                throw new DomainRuleException('A query may only modify data at its top level; data-modifying subqueries are not allowed.');
            }

            // UPDATE's own SET clause: once, at the top level, in an UPDATE.
            if ($word === 'set' && $lead === 'update' && $token['depth'] === 0 && !$updateSetSeen) {
                $updateSetSeen = true;
                continue;
            }

            if (\in_array($word, self::FORBIDDEN_KEYWORDS, true)) {
                throw new DomainRuleException('%keyword% is not allowed in the sandbox.', [
                    '%keyword%' => strtoupper($word),
                ]);
            }

            if ($word === 'where' && $token['depth'] === 0) {
                $hasTopLevelWhere = true;
            }
        }

        if ($kind === SqlKind::Write && $lead === 'with') {
            throw new DomainRuleException('A query may only modify data at its top level; data-modifying subqueries are not allowed.');
        }

        if ($kind === SqlKind::Write && !$hasTopLevelWhere) {
            throw new DomainRuleException('UPDATE and DELETE need a WHERE clause.');
        }

        return $kind;
    }

    /** @param list<array{type: string, value: string, depth: int}> $tokens */
    private function hasMeaningfulTokenAfter(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $n = \count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['type'] !== ';') {
                return true;
            }
        }

        return false;
    }

    /**
     * PostgreSQL-aware tokenizer. Emits:
     *  - `word`  — an unquoted identifier or keyword, lowercased;
     *  - `name`  — a "quoted" identifier, exact case (never a keyword);
     *  - `(` `)` `;` — the punctuation the rules care about;
     *  - `other` — literals, numbers, operators and anything else.
     * Every token carries its parenthesis depth.
     *
     * @return list<array{type: string, value: string, depth: int}>
     *
     * @throws DomainRuleException on an unterminated quote or comment
     */
    private function lex(string $sql): array
    {
        $tokens = [];
        $depth = 0;
        $len = \strlen($sql);
        $i = 0;

        $unterminated = static fn () => new DomainRuleException(
            'The statement is not terminated: a quote or comment is left open.',
        );

        while ($i < $len) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            // -- line comment
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }

            // /* block comment */ — PostgreSQL nests them.
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $nesting = 1;
                $i += 2;
                while ($i < $len && $nesting > 0) {
                    if ($sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
                        $nesting++;
                        $i += 2;
                    } elseif ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/') {
                        $nesting--;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
                if ($nesting > 0) {
                    throw $unterminated();
                }
                continue;
            }

            // 'string' — '' escapes a quote; an E/e prefix (handled below, as
            // the word before it) also lets a backslash escape one.
            if ($c === "'") {
                $escapes = $tokens !== []
                    && end($tokens)['type'] === 'word'
                    && end($tokens)['value'] === 'e'
                    && $i > 0 && ($sql[$i - 1] === 'e' || $sql[$i - 1] === 'E');
                if ($escapes) {
                    array_pop($tokens);
                }
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($escapes && $sql[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === "'") {
                        if (($sql[$i + 1] ?? '') === "'") {
                            $i += 2;
                            continue;
                        }
                        $closed = true;
                        $i++;
                        break;
                    }
                    $i++;
                }
                if (!$closed) {
                    throw $unterminated();
                }
                $tokens[] = ['type' => 'other', 'value' => "''", 'depth' => $depth];
                continue;
            }

            // "quoted identifier" — "" escapes a quote.
            if ($c === '"') {
                $i++;
                $name = '';
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === '"') {
                        if (($sql[$i + 1] ?? '') === '"') {
                            $name .= '"';
                            $i += 2;
                            continue;
                        }
                        $closed = true;
                        $i++;
                        break;
                    }
                    $name .= $sql[$i];
                    $i++;
                }
                if (!$closed) {
                    throw $unterminated();
                }
                $tokens[] = ['type' => 'name', 'value' => $name, 'depth' => $depth];
                continue;
            }

            // $tag$ dollar-quoted body $tag$ — but $1 is a positional parameter.
            if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $delimiter = $m[0];
                $end = strpos($sql, $delimiter, $i + \strlen($delimiter));
                if ($end === false) {
                    throw $unterminated();
                }
                $i = $end + \strlen($delimiter);
                $tokens[] = ['type' => 'other', 'value' => "''", 'depth' => $depth];
                continue;
            }

            if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $sql, $m, 0, $i)) {
                $tokens[] = ['type' => 'word', 'value' => strtolower($m[0]), 'depth' => $depth];
                $i += \strlen($m[0]);
                continue;
            }

            if ($c === '(') {
                $tokens[] = ['type' => '(', 'value' => '(', 'depth' => $depth];
                $depth++;
                $i++;
                continue;
            }

            if ($c === ')') {
                $depth = max(0, $depth - 1);
                $tokens[] = ['type' => ')', 'value' => ')', 'depth' => $depth];
                $i++;
                continue;
            }

            if ($c === ';') {
                $tokens[] = ['type' => ';', 'value' => ';', 'depth' => $depth];
                $i++;
                continue;
            }

            $tokens[] = ['type' => 'other', 'value' => $c, 'depth' => $depth];
            $i++;
        }

        return $tokens;
    }
}
