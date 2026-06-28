<?php

namespace App\Service;

use App\Dto\BookInput;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Language\LanguageCatalog;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * CSV export / import for a user's book collection.
 *
 * The CSV carries one book per row with the columns: title, author, isbn,
 * language (ISO 639-1 code), status, categories (semicolon-joined names).
 *
 * Import is parameterised by two independent choices:
 *  - replace:  wipe the user's existing (home) books first, vs. append.
 *  - abortOnError: reject the whole file if any row is invalid, vs. skip the
 *    bad rows and import the rest.
 *
 * Like every service here it persists/removes but never flushes — the
 * controller owns the single transaction, so an aborted import (which returns
 * before staging anything) leaves the database untouched.
 */
class BookCsvService
{
    /** Columns, in export order. Import matches them case-insensitively by header. */
    private const COLUMNS = ['title', 'author', 'isbn', 'language', 'status', 'categories'];

    /** Statuses a book may be imported as — never 'lent', which needs a live loan. */
    private const IMPORTABLE_STATUSES = ['own', 'unavailable'];

    /** Guards against pathologically large uploads. */
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly BookService $books,
        private readonly BookRepository $bookRepo,
        private readonly CategoryRepository $categories,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * Serialise books to CSV text.
     *
     * @param Book[] $books
     */
    public function export(array $books): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUMNS, ',', '"', '');

        foreach ($books as $book) {
            fputcsv($handle, [
                $book->getTitle(),
                $book->getAuthor(),
                $book->getIsbn() ?? '',
                $book->getLanguage() ?? '',
                $book->getStatus()->value,
                implode('; ', array_map(static fn ($c) => $c->getName(), $book->getCategories()->toArray())),
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Import books from CSV text for $owner.
     *
     * @return array{imported:int, skipped:int, aborted:bool, errors:list<array{line:int, errors:string[]}>}
     */
    public function import(User $owner, string $csv, bool $replace, bool $abortOnError): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false || $header === null) {
            return $this->fatal('The file is empty.');
        }

        $index = $this->mapHeader($header);
        foreach (['title', 'author'] as $required) {
            if (!isset($index[$required])) {
                fclose($handle);

                return $this->fatal(sprintf('Missing required column "%s".', $required));
            }
        }

        /** @var BookInput[] $valid */
        $valid = [];
        $errors = [];
        $line = 1; // header was line 1

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            ++$line;
            if ($row === [null] || $row === ['']) {
                continue; // skip blank lines
            }
            if ($line - 1 > self::MAX_ROWS) {
                fclose($handle);

                return $this->fatal(sprintf('Too many rows — the limit is %d.', self::MAX_ROWS));
            }

            [$input, $rowErrors] = $this->buildRow($index, $row);
            if ($rowErrors !== []) {
                $errors[] = ['line' => $line, 'errors' => $rowErrors];
                continue;
            }
            $valid[] = $input;
        }
        fclose($handle);

        // All-or-nothing: a single bad row aborts before anything is staged.
        if ($abortOnError && $errors !== []) {
            return ['imported' => 0, 'skipped' => 0, 'aborted' => true, 'errors' => $errors];
        }

        if ($replace) {
            foreach ($this->bookRepo->findByOwner($owner) as $existing) {
                // Books out on loan are frozen — leave them (and their loan history) intact.
                if ($existing->isHome()) {
                    $this->em->remove($existing);
                }
            }
        }

        foreach ($valid as $input) {
            $this->books->create($owner, $input);
        }

        return [
            'imported' => count($valid),
            'skipped'  => count($errors),
            'aborted'  => false,
            'errors'   => $errors,
        ];
    }

    /**
     * Resolve a header row to a `column => index` map (lower-cased, BOM-stripped).
     *
     * @param string[] $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $index = [];
        foreach ($header as $i => $name) {
            $key = strtolower(trim($this->stripBom((string) $name)));
            if (in_array($key, self::COLUMNS, true)) {
                $index[$key] = $i;
            }
        }

        return $index;
    }

    /**
     * Build a validated BookInput from one CSV row.
     *
     * @param array<string, int> $index
     * @param string[]           $row
     * @return array{0: BookInput, 1: string[]} the input plus any per-row errors
     */
    private function buildRow(array $index, array $row): array
    {
        $get = static fn (string $col): string => trim((string) ($row[$index[$col] ?? -1] ?? ''));

        $errors = [];

        $input = new BookInput();
        $input->title = $get('title');
        $input->author = $get('author');
        $isbn = $get('isbn');
        $input->isbn = $isbn !== '' ? $isbn : null;
        $language = strtolower($get('language'));
        $input->language = $language !== '' ? $language : null;

        $statusRaw = strtolower($get('status')) ?: 'own';
        if (in_array($statusRaw, self::IMPORTABLE_STATUSES, true)) {
            $input->status = BookStatus::from($statusRaw);
        } else {
            $errors[] = sprintf('Unsupported status "%s".', $statusRaw);
        }

        // Match category names against the existing vocabulary only; ignore unknowns.
        $names = array_values(array_filter(array_map('trim', explode(';', $get('categories'))), static fn ($n) => $n !== ''));
        if ($names !== []) {
            $input->categoryIds = array_map(static fn ($c) => $c->getId(), $this->categories->findByNames($names));
        }

        foreach ($this->validator->validate($input) as $violation) {
            $errors[] = $violation->getMessage();
        }

        return [$input, $errors];
    }

    /** @return array{imported:int, skipped:int, aborted:bool, errors:list<array{line:int, errors:string[]}>} */
    private function fatal(string $message): array
    {
        return ['imported' => 0, 'skipped' => 0, 'aborted' => true, 'errors' => [['line' => 1, 'errors' => [$message]]]];
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
