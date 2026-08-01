import { reactive } from 'vue'

/**
 * Tracks cover images whose URL failed to load.
 *
 * Every cover surface renders `<img v-if="coverPath">` with a `menu_book`
 * placeholder as the `v-else`, which covers a *missing* cover but not a *dead*
 * one: a remote URL that 404s leaves the browser rendering the alt text inside
 * the frame. Gate the same `v-if` on `hasCover()` and mark failures with
 * `@error="onCoverError(...)"` so a broken link falls back to that placeholder.
 *
 * State is per-consumer rather than a singleton: a list that unmounts forgets,
 * so a cover that was merely unreachable gets another chance next visit.
 */
export function useCoverFallback() {
  const broken = reactive(new Set())

  /**
   * @param source a bare URL, or anything carrying `coverPath` / `coverUrl`
   *               (book, template, collection…)
   * @param key    identity for the failure set; ids where there are ids, else the URL
   */
  const hasCover = (source, key) => {
    const url = typeof source === 'string' ? source : source?.coverPath ?? source?.coverUrl
    return !!url && !broken.has(key ?? (typeof source === 'string' ? url : source?.id ?? url))
  }

  const onCoverError = key => broken.add(key)

  return { hasCover, onCoverError }
}
