/**
 * Curated category colours. The `bg` values mirror the backend's
 * App\Category\CategoryPalette::COLORS — keep the two lists in sync. Each entry
 * carries the full chip styling (background, ink, border) so a stored colour
 * renders identically everywhere (book cards, chips, the create-picker swatches).
 */
export const CATEGORY_PALETTE = [
  { bg: '#E8F0EA', text: '#1B3625', border: '#c4dbc9', label: 'Green' },
  { bg: '#F4EAE0', text: '#4A3219', border: '#dfcbb3', label: 'Beige' },
  { bg: '#dae4ed', text: '#3f484f', border: '#bec8d1', label: 'Blue' },
  { bg: '#ffdad6', text: '#93000a', border: '#ba1a1a', label: 'Red' },
  { bg: '#EAE4F4', text: '#3A2E55', border: '#d2c8e2', label: 'Lavender' },
  { bg: '#F7E7C2', text: '#4A3A12', border: '#e4d2a4', label: 'Amber' },
  { bg: '#D6EFEA', text: '#143F38', border: '#bce0d8', label: 'Mint' },
  { bg: '#F4DCE8', text: '#5A2540', border: '#e3c2d3', label: 'Rose' },
  { bg: '#E4E8D0', text: '#3A3F1E', border: '#ced3b6', label: 'Olive' },
  { bg: '#E0DBD2', text: '#3D382E', border: '#ccc6ba', label: 'Stone' },
]

const BY_BG = Object.fromEntries(
  CATEGORY_PALETTE.map(entry => [entry.bg.toLowerCase(), entry]),
)

/**
 * Resolve a stored hex to its chip styling. Curated colours map to their full
 * triple; anything else (e.g. legacy categories) falls back to the stored hex
 * as the background with neutral ink so it still renders sensibly.
 */
export function resolveCategoryColors(hex) {
  const known = hex && BY_BG[hex.toLowerCase()]
  if (known) return known
  return { bg: hex || '#efeeea', text: '#1b1c1a', border: 'rgba(27, 28, 26, 0.12)' }
}
