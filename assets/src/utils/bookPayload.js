/**
 * Build the full BookInput payload for `PATCH /api/books/{id}` from a book
 * object as returned by the API. The endpoint maps the *whole* DTO, so every
 * field must be resent — omitting one resets it to the DTO default (e.g. a
 * blank title would 422). Used by the inline "mark as read" toggle, which
 * changes only `isRead` but has to carry the book's current values along.
 */
export function toBookInput(book) {
  return {
    title: book.title,
    author: book.author,
    isbn: book.isbn ?? null,
    description: book.description ?? null,
    coverPath: book.coverPath ?? null,
    status: book.status,
    language: book.language ?? null,
    isRead: book.isRead,
    categoryIds: (book.categories ?? []).map(c => c.id),
  }
}
