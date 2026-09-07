/**
 * Achievements: the presentation half.
 *
 * The API emits raw keys and numbers only — `{ key, tier, tiers, value, next,
 * thresholds }` per family, in catalogue order (see src/Achievement/). Icons
 * live here and prose lives in the `achievements` i18n namespace, the same
 * split `status` and `wishPriority` already make: the backend decides what a
 * badge *is*, the frontend decides what it looks like and what it's called.
 *
 * Because that vocabulary is duplicated across the two halves,
 * `tests/Achievement/AchievementFrontendParityTest` reads this file and
 * `i18n/locales/en.json` and fails when a family here doesn't match the
 * catalogue — the same job `AnalyticsRoutesTest` does for route names.
 *
 * No new colours. Locked is the muted outline on the low container, earned is
 * the navy primary, and **only a maxed family takes `--color-accent`** — the
 * brass is reserved for active/selected states, so spending it on the top tier
 * of a family keeps it as rare as the design system asks.
 */

/** Material symbol per family. Keys must match AchievementCatalog. */
export const ACHIEVEMENT_ICONS = {
  collector: 'library_books',
  reader: 'auto_stories',
  critic: 'reviews',
  curator: 'collections_bookmark',
  // The same glyph the Wish List shelf carries, so the badge reads as being
  // about that shelf rather than about some other kind of wanting.
  dreamer: 'bookmark',
  lender: 'call_made',
  borrower: 'call_received',
  polyglot: 'translate',
  explorer: 'explore',
  connector: 'group',
}

export function achievementIcon(key) {
  // A family the backend grew and the frontend hasn't learnt yet still renders
  // — as a generic medal rather than an empty box. The parity test is what
  // stops that silently becoming the normal state.
  return ACHIEVEMENT_ICONS[key] ?? 'workspace_premium'
}

/** The i18n keys for a family's name and one-line description. */
export function achievementNameKey(key) {
  return `achievements.items.${key}.name`
}

export function achievementDescriptionKey(key) {
  return `achievements.items.${key}.description`
}

/**
 * The CSS modifier a badge renders under: locked, earned, or maxed. Derived
 * from the payload rather than passed in, so a caller can't mislabel one — the
 * same reasoning behind LoanCard deriving its variant from (perspective, status).
 */
export function achievementState(item) {
  if (!item?.tier) return 'locked'

  return item.tier >= item.tiers ? 'maxed' : 'earned'
}

/** Only the families a member has actually reached tier 1 of. */
export function earnedAchievements(items) {
  return (items ?? []).filter(item => item.tier > 0)
}

/**
 * How far into the current tier the member is, as a 0-1 fraction, measured
 * from the tier they already hold rather than from zero — otherwise a member
 * one book short of tier 3 would show a bar that looks nearly full on their
 * way to tier 2 and then jump backwards.
 *
 * A maxed family returns 1: there is nothing left to aim at.
 */
export function achievementProgress(item) {
  if (!item || item.next == null) return 1

  const floor = item.tier > 0 ? item.thresholds[item.tier - 1] : 0
  const span = item.next - floor
  if (span <= 0) return 1

  return Math.min(Math.max((item.value - floor) / span, 0), 1)
}
