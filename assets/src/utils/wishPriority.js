/**
 * Wish-list priority: the API emits `wishPriority` as the enum's number (see
 * src/Enum/WishPriority.php, backed by an int so the database can rank it), and
 * everything a reader actually sees is decided here.
 *
 * Presentation is a traffic light — green "can wait", yellow "very interested",
 * red "urgent" — which is the whole point of the field: a wish list is scanned,
 * not read. The same split as `status`, whose colours also live in the frontend.
 *
 * Colours come from the design tokens rather than raw hex; `--color-tertiary`
 * is the app's amber and `--color-error` its red, so a wish list reads in the
 * same palette as the rest of the app instead of introducing three new tones.
 */

export const WISH_CAN_WAIT = 1
export const WISH_VERY_INTERESTED = 2
export const WISH_URGENT = 3

/** Highest first — the order the tab lists them and the picker offers them. */
export const WISH_PRIORITIES = [WISH_URGENT, WISH_VERY_INTERESTED, WISH_CAN_WAIT]

/** The level a new wish-list book starts at, mirroring WishPriority::DEFAULT. */
export const WISH_DEFAULT = WISH_CAN_WAIT

const TONES = {
  [WISH_CAN_WAIT]:        { key: 'canWait',        tone: 'green',  icon: 'schedule' },
  [WISH_VERY_INTERESTED]: { key: 'veryInterested', tone: 'amber',  icon: 'favorite' },
  [WISH_URGENT]:          { key: 'urgent',         tone: 'red',    icon: 'priority_high' },
}

/**
 * The chip descriptor for a priority: its tone (which drives the CSS class) and
 * the i18n key for its label. Unknown/absent values yield null so a caller can
 * `v-if` on it rather than rendering an empty chip — the same graceful fallback
 * resolveCategoryColors() gives an unknown palette hex.
 */
export function wishPriorityMeta(priority) {
  return TONES[priority] ?? null
}

/** The translation key for a priority's label, e.g. `wishlist.priority.urgent`. */
export function wishPriorityKey(priority) {
  const meta = wishPriorityMeta(priority)

  return meta ? `wishlist.priority.${meta.key}` : 'wishlist.priority.unset'
}
