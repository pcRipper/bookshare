/**
 * v-hscroll — affordance for horizontally scrollable bars (tab navs, filter
 * pill rows, feed scrollers). Mobile hides scrollbars, so there's otherwise no
 * sign these bars can be swiped. The directive measures how much hidden content
 * lies past each edge and writes it to `data-hscroll` ("none" | "left" |
 * "right" | "both"); the global CSS in styles/tokens.css turns that into an
 * edge fade. It no-ops automatically when the content fits (e.g. the Discover
 * pills wrap on desktop), since then there's nothing to scroll toward.
 */
const FUZZ = 2 // tolerate sub-pixel rounding so a fitting bar reads as "none"

function measure(el) {
  const { scrollLeft, scrollWidth, clientWidth } = el
  const canLeft = scrollLeft > FUZZ
  const canRight = scrollLeft + clientWidth < scrollWidth - FUZZ
  el.dataset.hscroll = canLeft && canRight ? 'both' : canLeft ? 'left' : canRight ? 'right' : 'none'
}

export default {
  mounted(el) {
    const update = () => measure(el)
    el.__hscrollUpdate = update

    el.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)

    // Recompute when the bar or its contents change size (fonts loading,
    // async-loaded tabs/pills, viewport breakpoint flips).
    const ro = new ResizeObserver(update)
    ro.observe(el)
    for (const child of el.children) ro.observe(child)
    el.__hscrollRO = ro

    update()
  },
  // Content can change without a resize (a tab/pill added or removed).
  updated(el) {
    el.__hscrollUpdate?.()
  },
  unmounted(el) {
    el.removeEventListener('scroll', el.__hscrollUpdate)
    window.removeEventListener('resize', el.__hscrollUpdate)
    el.__hscrollRO?.disconnect()
    delete el.__hscrollUpdate
    delete el.__hscrollRO
  },
}
