import { expect, test } from '@playwright/test'
import { and, purgeInbox, readMessage, subjectContains, to, waitForMessage } from './helpers/mailpit.js'
import { BORROWER, OWNER, authHeaders, createBook, requestBook, signIn, uniqueTitle } from './helpers/app.js'

/**
 * What the mail actually looks like in a renderer, which is the one thing only a
 * browser can answer.
 *
 * Email clients are not browsers, so this proves less than it would for a page —
 * but the failures it catches are the ones that matter and that no PHP assertion
 * reaches: a sheet that scrolls sideways on a phone, a dead call-to-action, a
 * layout that collapsed because a style was dropped. Screenshots land in var/e2e
 * for a human to glance at.
 */
test.describe('mail rendering', () => {
  /** @type {Array<{name: string, html: string}>} */
  const rendered = []

  test.beforeAll(async ({ request }) => {
    await purgeInbox(request)

    const title = uniqueTitle('E2E Render')
    const book = await createBook(request, { title })
    const loan = await requestBook(request, book.id)

    const requestMail = await waitForMessage(request, and(to(OWNER), subjectContains(title)), {
      label: 'the request mail',
    })
    rendered.push({ name: 'loan.requested', html: (await readMessage(request, requestMail.ID)).HTML })

    const approval = await request.post(`/api/requests/${loan.id}/approve`, {
      headers: authHeaders(OWNER),
      data: { dueDate: '2026-12-24' },
    })
    expect(approval.ok()).toBeTruthy()

    const approvalMail = await waitForMessage(request, and(to(BORROWER), subjectContains(title)), {
      label: 'the approval mail',
    })
    rendered.push({ name: 'loan.approved', html: (await readMessage(request, approvalMail.ID)).HTML })
  })

  test('every mail fits a phone without scrolling sideways', async ({ page }) => {
    // 375px is the narrow end of real phones. A mail that overflows it is read
    // by dragging, which is how a 600px table with a stray fixed width behaves.
    await page.setViewportSize({ width: 375, height: 800 })

    for (const { name, html } of rendered) {
      await page.setContent(html)
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
      expect(overflow, `${name} overflows the viewport by ${overflow}px`).toBeLessThanOrEqual(1)
      await page.screenshot({ path: `var/e2e/mails/${name}-375.png`, fullPage: true })
    }
  })

  test('every mail has a working call to action and a way out', async ({ page }) => {
    for (const { name, html } of rendered) {
      await page.setContent(html)

      const links = await page.locator('a').evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href')))

      // Absolute, always: a worker has no request context to resolve a relative
      // href against, so a relative link here means a broken link in the inbox.
      for (const href of links) {
        expect(href, `${name} has a non-absolute link: ${href}`).toMatch(/^https?:\/\//)
      }
      // The action, and the unsubscribe route. Both are the point of the mail.
      expect(links.some((href) => href.includes('/library') || href.includes('/discover')), `${name} has no action link`).toBeTruthy()
      expect(links.some((href) => href.endsWith('/settings')), `${name} offers no way to turn mails off`).toBeTruthy()
    }
  })

  test('no mail ships an unresolved placeholder or template marker', async ({ page }) => {
    for (const { name, html } of rendered) {
      await page.setContent(html)
      const text = await page.locator('body').innerText()

      for (const marker of ['%item%', '%requester%', '%owner%', '%count%', '{{', '{%']) {
        expect(text, `${name} leaks "${marker}"`).not.toContain(marker)
      }
    }
  })

  test('a desktop reader sees the same mail, wider', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 })

    for (const { name, html } of rendered) {
      await page.setContent(html)
      // The column is capped at 600px — a mail stretched across a desktop window
      // is unreadable, which is why the layout has a max-width at all.
      const width = await page.locator('table').first().locator('table').first().evaluate((el) => el.getBoundingClientRect().width)
      expect(width, `${name} is ${width}px wide on desktop`).toBeLessThanOrEqual(601)
      await page.screenshot({ path: `var/e2e/mails/${name}-1280.png`, fullPage: true })
    }
  })
})

/**
 * The SPA half: the settings screen these opt-ins belong to, and the Sharing tab
 * a mail's call to action lands on. Sign-in is seeded rather than performed —
 * Google OAuth cannot be driven from a test browser.
 */
test.describe('the app around the mails', () => {
  test('the notification opt-ins are reachable and reflect the account', async ({ page }) => {
    await signIn(page, OWNER)
    await page.goto('/settings')

    // The screen is a sidebar of sections; the labels carry their material icon
    // name as text, so match on a substring rather than an exact accessible name.
    await page.getByRole('button', { name: /Notifications/ }).click()

    await expect(page.getByText('New borrow requests')).toBeVisible()
    await expect(page.getByText('Request updates')).toBeVisible()
    await expect(page.getByText('Community activity')).toBeVisible()
  })

  test("a mail's call to action lands on the loan", async ({ page, request }) => {
    const title = uniqueTitle('E2E Landing')
    const book = await createBook(request, { title })
    await requestBook(request, book.id)

    // Where the request mail's button points.
    await signIn(page, OWNER)
    await page.goto('/library')

    // The strip is a real tablist, and each tab's accessible name carries its
    // pending-count badge ("Sharing 11"), so match on the label alone.
    await page.getByRole('tab', { name: /Sharing/ }).click()
    // Sharing opens on Borrowing; a request someone made of you is on the
    // Lending side, which is the side the request mail is about.
    await page.getByRole('tab', { name: /Lending/ }).click()

    await expect(page.getByRole('heading', { name: title })).toBeVisible()
  })
})
