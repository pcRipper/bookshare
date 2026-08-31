import { execSync } from 'node:child_process'
import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { expect, test } from '@playwright/test'

/**
 * Visual regression for the mails.
 *
 * These templates are the one surface in the project with **no** live styling:
 * an email client can't read a CSS variable, so every colour, face and spacing
 * value is a literal copied out of assets/src/styles/tokens.css. That is exactly
 * the kind of copy that rots — the first version of these mails shipped in the
 * app's *previous* green palette, and every existing test passed, because a mail
 * being the wrong colour is not an error, a missing key or a broken link.
 * MailStyleTest catches a value that isn't a token; this catches what the reader
 * actually sees.
 *
 * The subject is `app:mail-preview`, not a live mail: its fixtures are literals
 * (fixed due date, fixed names, a fixed host), so the same bytes render today
 * and next month. Screenshotting a real delivered mail would rebase the baseline
 * on every run — the title carries a timestamp and the date moves.
 *
 * Baselines live beside this spec in mail-visual.spec.js-snapshots/ and are
 * platform-suffixed by Playwright, because the mails deliberately name webfonts
 * they don't ship (Literata, IBM Plex Sans) and fall back to whatever the host
 * has. Update them deliberately, and look at the diff:
 *
 *     npx playwright test mail-visual --update-snapshots
 */
const PREVIEW_DIR = 'var/mail-preview'
const CONSOLE = process.env.E2E_CONSOLE ?? 'docker compose exec -T phpfpm php bin/console'

/** Rendered in the widest layout an email client gives a 600px column, and on a phone. */
const VIEWPORTS = [
  { label: 'desktop', width: 700, height: 900 },
  { label: 'phone', width: 375, height: 800 },
]

/**
 * en covers every variant; uk is carried as well because Cyrillic is where a
 * layout breaks — the words are longer and a heading that fits in English can
 * wrap into the button in another language.
 */
const LOCALES = ['en', 'uk']

test.describe('mail visual regression', () => {
  /** @type {Array<{stem: string, html: string}>} */
  const previews = []

  test.beforeAll(() => {
    execSync(`${CONSOLE} app:mail-preview --dir=${PREVIEW_DIR} --locale=${LOCALES.join(',')}`, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })

    const dir = path.resolve(PREVIEW_DIR)
    const files = readdirSync(dir)
      .filter((name) => name.endsWith('.html') && name !== 'index.html')
      .sort()

    expect(files.length, 'app:mail-preview produced no mails').toBeGreaterThan(0)

    for (const file of files) {
      previews.push({ stem: path.basename(file, '.html'), html: readFileSync(path.join(dir, file), 'utf8') })
    }
  })

  for (const viewport of VIEWPORTS) {
    test(`every mail matches its ${viewport.label} baseline`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height })

      // One test per viewport rather than per mail: a palette change breaks all
      // of them at once, and soft assertions report the whole set in one run
      // instead of hiding 20 diffs behind the first failure.
      for (const { stem, html } of previews) {
        await page.setContent(html)
        await expect
          .soft(page)
          .toHaveScreenshot(`${stem}.${viewport.label}.png`, {
            fullPage: true,
            // `threshold` is per-pixel colour distance in YIQ, and the default
            // 0.2 is uselessly loose here — measured, not guessed: swapping the
            // brand bar back to the old green #274738 scores ~0.002 against navy
            // #223b54, because the two are dark and similarly lit. A full run
            // passed at the default AND at 0.05 with a green header. At 0.002 the
            // same injected change fails with 42,624 differing pixels (7%).
            threshold: 0.002,
            // The counterweight, since a near-zero threshold also counts
            // antialiasing noise: that noise sits along glyph edges, a small
            // fraction of the page, while a palette or spacing change is not.
            maxDiffPixelRatio: 0.01,
            animations: 'disabled',
          })
      }
    })
  }

  /**
   * The palette itself, asserted on the rendered document rather than on the
   * template source: this is the assertion that would have failed the day the
   * mails shipped green, whatever the baselines happened to contain.
   */
  test('the brand bar and button carry the app primary', async ({ page }) => {
    const PRIMARY = 'rgb(34, 59, 84)' // --color-primary #223b54

    for (const { stem, html } of previews.filter((p) => p.stem.endsWith('.en'))) {
      await page.setContent(html)

      const brand = page.locator('a', { hasText: 'FolioShare' }).first()
      const brandBackground = await brand.evaluate((el) => getComputedStyle(el.closest('td')).backgroundColor)
      expect.soft(brandBackground, `${stem}: brand bar`).toBe(PRIMARY)

      // Every mail but none-of-them has a CTA; when present it must match.
      const cta = page.locator('a[style*="display:inline-block"]').first()
      if ((await cta.count()) > 0) {
        const ctaBackground = await cta.evaluate((el) => getComputedStyle(el.closest('td')).backgroundColor)
        expect.soft(ctaBackground, `${stem}: call to action`).toBe(PRIMARY)
      }
    }
  })
})
