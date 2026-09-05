/**
 * Scrolling helpers. Today just the one the pager needs.
 */

/*
 * The app header is `position: sticky; top: 0`, so it covers the first ~60px of
 * the viewport. Scrolling an element to y = 0 would park its first rows behind
 * it. The height is measured rather than tokenised because it differs between
 * the phone and desktop layouts, and both header variants are matched — the
 * public share page renders PublicHeader instead of AppHeader.
 */
const HEADER_SELECTOR = '.app-header, .public-header'

/** A little air between the header and what it was hiding. */
const GAP = 8

function headerHeight() {
  const header = document.querySelector(HEADER_SELECTOR)
  if (!header) return 0

  // Only a header that is actually pinned to the top overlaps the content.
  return getComputedStyle(header).position === 'sticky'
    ? header.getBoundingClientRect().height
    : 0
}

function prefersReducedMotion() {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
}

/**
 * Brings the top of `el` to just under the sticky header — but only when that
 * means scrolling *up*.
 *
 * The one-way rule is the whole design. Paging a list is something you do from
 * the bottom of it, having read to the end, and landing on row one of the next
 * page is what you meant. If you happen to be above the list already (a short
 * list fully on screen, a pager reached by keyboard), there is nothing to fix
 * and yanking the page downwards would be the surprise instead.
 *
 * No-ops when `el` is missing, so a caller never has to guard.
 */
export function scrollToTopOf(el) {
  if (!el) return

  const top = Math.max(el.getBoundingClientRect().top + window.scrollY - headerHeight() - GAP, 0)
  if (window.scrollY <= top) return

  window.scrollTo({ top, behavior: prefersReducedMotion() ? 'auto' : 'smooth' })
}
