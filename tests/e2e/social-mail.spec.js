import { expect, test } from '@playwright/test'
import { and, expectNoMessage, purgeInbox, readMessage, subjectContains, to, waitForMessage } from './helpers/mailpit.js'
import { BORROWER, OWNER, authHeaders, withSettings } from './helpers/app.js'

/**
 * The follow notification, and the default that keeps it quiet.
 *
 * notifyActivity ships off, so the interesting assertion is in both directions:
 * nothing by default, a mail once the member asks for one.
 */
test.describe('follow mails', () => {
  let ownerId

  test.beforeAll(async ({ request }) => {
    const me = await request.get('/api/me', { headers: authHeaders(OWNER) })
    expect(me.ok()).toBeTruthy()
    ownerId = (await me.json()).id
  })

  test.beforeEach(async ({ request }) => {
    await purgeInbox(request)
    // Start unfollowed: subscribe() is idempotent, and only a *new* edge mails.
    await request.delete(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
  })

  test('following someone who opted in mails them', async ({ request }) => {
    await withSettings(request, OWNER, { notifyActivity: true }, async () => {
      const follow = await request.post(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
      expect(follow.status()).toBe(201)

      const mail = await waitForMessage(request, and(to(OWNER), subjectContains('following')), {
        label: 'the new-follower mail',
      })
      const full = await readMessage(request, mail.ID)

      // The mail links to the follower's profile, so it has to carry an id.
      expect(full.HTML).toMatch(/\/profile\/\d+/)

      await request.delete(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
    })
  })

  test('following is silent by default', async ({ request }) => {
    // notifyActivity is off unless asked for — community noise stays opt-in.
    await withSettings(request, OWNER, { notifyActivity: false }, async () => {
      const follow = await request.post(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
      expect(follow.status()).toBe(201)

      await expectNoMessage(request, and(to(OWNER), subjectContains('following')), { window: 8_000 })
      await request.delete(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
    })
  })

  test('re-following an existing edge does not mail again', async ({ request }) => {
    await withSettings(request, OWNER, { notifyActivity: true }, async () => {
      await request.post(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
      await waitForMessage(request, and(to(OWNER), subjectContains('following')), { label: 'the first mail' })
      await purgeInbox(request)

      // Idempotent endpoint: the same POST returns the existing subscription,
      // which is why the controller reads the id before flush.
      const again = await request.post(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
      expect(again.status()).toBe(201)

      await expectNoMessage(request, and(to(OWNER), subjectContains('following')))

      await request.delete(`/api/subscriptions/${ownerId}`, { headers: authHeaders(BORROWER) })
    })
  })
})
