import { expect, test } from '@playwright/test'
import { and, expectNoMessage, purgeInbox, readMessage, subjectContains, to, waitForMessage } from './helpers/mailpit.js'
import { BORROWER, OWNER, authHeaders, createBook, requestBook, uniqueTitle, withSettings } from './helpers/app.js'

/**
 * The loan lifecycle's mails, end to end: an API call queues one, the worker
 * renders and sends it, Mailpit receives it.
 *
 * Each spec creates its own book with a run-unique title, so a rerun can never
 * be satisfied by the previous run's mail, and nothing depends on fixture state.
 */
test.describe('loan mails', () => {
  test.beforeEach(async ({ request }) => {
    await purgeInbox(request)
  })

  test('a borrow request mails the owner, not the requester', async ({ request }) => {
    const title = uniqueTitle('E2E Requested')
    const book = await createBook(request, { title })

    await requestBook(request, book.id)

    const mail = await waitForMessage(request, and(to(OWNER), subjectContains(title)), {
      label: `the "would like to borrow ${title}" mail`,
    })

    // Routed by role: the person who asked already knows they asked.
    expect(mail.To.map((t) => t.Address)).toEqual([OWNER])
    await expectNoMessage(request, and(to(BORROWER), subjectContains(title)), { window: 3_000 })

    const full = await readMessage(request, mail.ID)
    // Both parts, always: a text/plain alternative is what keeps HTML-only mail
    // out of spam filters and what a text-mode client reads.
    expect(full.HTML).toContain(title)
    expect(full.Text.trim()).not.toBe('')
    // The mail's job is to get the reader back to the app.
    expect(full.HTML).toContain('/library')
  })

  test('an approval mails the requester with the due date the owner set', async ({ request }) => {
    const title = uniqueTitle('E2E Approved')
    const book = await createBook(request, { title })
    const loan = await requestBook(request, book.id)
    await purgeInbox(request) // drop the request mail; this spec is about the answer

    const approval = await request.post(`/api/requests/${loan.id}/approve`, {
      headers: authHeaders(OWNER),
      data: { dueDate: '2026-12-24' },
    })
    expect(approval.ok()).toBeTruthy()

    const mail = await waitForMessage(request, and(to(BORROWER), subjectContains(title)), {
      label: 'the approval mail',
    })
    const full = await readMessage(request, mail.ID)

    expect(mail.Subject).toContain('approved')
    // The due date is the one thing the borrower has to act on, so it must
    // survive the trip through the worker's own locale-less context.
    expect(full.HTML).toContain('December')
    expect(full.HTML).toContain('2026')
  })

  test("a decline carries the owner's note", async ({ request }) => {
    const title = uniqueTitle('E2E Declined')
    const book = await createBook(request, { title })
    const loan = await requestBook(request, book.id)
    await purgeInbox(request)

    const note = 'Promised to a friend until spring.'
    const decline = await request.post(`/api/requests/${loan.id}/decline`, {
      headers: authHeaders(OWNER),
      data: { message: note },
    })
    expect(decline.ok()).toBeTruthy()

    const mail = await waitForMessage(request, and(to(BORROWER), subjectContains(title)), { label: 'the decline mail' })
    const full = await readMessage(request, mail.ID)

    expect(mail.Subject).toContain('declined')
    // The note lives on the timeline event, not the request row — this is the
    // assertion that the mail reads it from the right place.
    expect(full.HTML).toContain(note)
    expect(full.Text).toContain(note)
  })

  test('a withdrawn request mails nobody', async ({ request }) => {
    const title = uniqueTitle('E2E Withdrawn')
    const book = await createBook(request, { title })
    const loan = await requestBook(request, book.id)
    // Wait for the request mail BEFORE purging: delivery is asynchronous, so a
    // purge issued while it is still in the queue only clears the inbox — the
    // mail lands a second later and the assertion below fails on it. (Which is
    // exactly how this spec failed first time round.)
    await waitForMessage(request, and(to(OWNER), subjectContains(title)), { label: 'the request mail' })
    await purgeInbox(request)

    const withdrawal = await request.delete(`/api/requests/${loan.id}`, { headers: authHeaders(BORROWER) })
    expect(withdrawal.status()).toBe(204)

    // Deliberate: the request it would describe no longer exists.
    await expectNoMessage(request, subjectContains(title))
  })

  test('an opted-out owner is not mailed', async ({ request }) => {
    // withSettings puts back what it found rather than a default: these specs
    // run against a shared database and must leave the account untouched.
    await withSettings(request, OWNER, { notifyBorrowRequests: false }, async () => {
      const title = uniqueTitle('E2E OptedOut')
      const book = await createBook(request, { title })
      await requestBook(request, book.id)

      await expectNoMessage(request, and(to(OWNER), subjectContains(title)), { window: 8_000 })
    })
  })

  test("a mail is written in the recipient's own language, not the actor's", async ({ request }) => {
    // Restoring the *previous* locale matters here specifically: null means
    // "never chose a language", which is not the same as an explicit 'en'.
    await withSettings(request, OWNER, { locale: 'uk' }, async () => {
      const title = uniqueTitle('E2E Locale')
      const book = await createBook(request, { title })
      // The requester's own locale stays English; only the recipient's matters.
      await requestBook(request, book.id)

      const mail = await waitForMessage(request, and(to(OWNER), subjectContains(title)), {
        label: 'the Ukrainian request mail',
      })
      const full = await readMessage(request, mail.ID)

      expect(mail.Subject).toMatch(/\p{Script=Cyrillic}/u)
      expect(full.HTML).toMatch(/\p{Script=Cyrillic}/u)
    })
  })
})
