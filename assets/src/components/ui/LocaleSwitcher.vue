<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { SUPPORTED, setLocale, currentLocale } from '@/i18n'

/*
 * The one surface for changing UI language. It lives in the header (and on the
 * login card) rather than in Settings: a control whose whole point is "I can't
 * read this screen" is useless three navigations deep.
 *
 * Deliberately a popover, not a modal — switching language is a glance-and-pick,
 * so nothing behind it needs to be blocked or dimmed.
 *
 * The component applies the language itself (setLocale is the single entry
 * point) and then emits. It knows nothing about auth or the API: persisting is
 * the host's call, since a signed-in header can PATCH /me/settings while the
 * login page can only park the choice for the callback to commit.
 */
const emit = defineEmits(['change'])

const { t } = useI18n()

const open = ref(false)
const rootRef = ref(null)

const active = computed(() => currentLocale())
// The trigger shows the code, not the endonym: "Українська" would shove a phone
// header off its rails, while "UK" is recognisable and fixed-width.
const activeCode = computed(() => active.value.toUpperCase())

function select(code) {
  open.value = false
  emit('change', setLocale(code))
}

function onDocClick(e) {
  if (rootRef.value && !rootRef.value.contains(e.target)) open.value = false
}
function onKeydown(e) {
  if (e.key === 'Escape') open.value = false
}
onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="rootRef" class="locale-switcher">
    <button
      class="locale-switcher__trigger"
      type="button"
      :aria-label="t('nav.chooseLanguage')"
      :title="t('nav.language')"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span class="material-symbols-outlined locale-switcher__icon">translate</span>
      <span class="locale-switcher__code">{{ activeCode }}</span>
    </button>

    <transition name="menu">
      <div v-if="open" class="locale-switcher__menu" role="menu">
        <button
          v-for="option in SUPPORTED"
          :key="option.code"
          class="locale-switcher__item"
          :class="{ 'locale-switcher__item--active': option.code === active }"
          type="button"
          role="menuitemradio"
          :aria-checked="option.code === active"
          @click="select(option.code)"
        >
          <span class="locale-switcher__label">{{ option.label }}</span>
          <span
            v-if="option.code === active"
            class="material-symbols-outlined locale-switcher__check"
          >check</span>
        </button>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.locale-switcher { position: relative; }

.locale-switcher__trigger {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 8px;
  border-radius: var(--radius-default);
  color: var(--color-secondary);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 600;
  letter-spacing: var(--ls-label-md);
  transition: background 0.15s, color 0.15s;
}
.locale-switcher__trigger:hover,
.locale-switcher__trigger[aria-expanded='true'] {
  background: var(--color-surface-container-low);
  color: var(--color-primary);
}
.locale-switcher__icon { font-size: 20px; }

/* Icon-only on phones — the header there is brand + actions with no room to
   spare, and the open menu already says which language is active. */
.locale-switcher__code { display: none; }
@media (min-width: 768px) {
  .locale-switcher__code { display: inline; }
}

/* Same dropdown contract as the account menu next to it. */
.locale-switcher__menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 180px;
  background: var(--color-surface-container-lowest);
  border: 1px solid var(--color-surface-container-highest);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(35, 44, 51, 0.12);
  padding: var(--space-xs);
  z-index: 60;
}

.locale-switcher__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  width: 100%;
  padding: 10px 12px;
  border-radius: var(--radius-default);
  font-family: var(--font-body);
  font-size: var(--text-label-md);
  font-weight: 500;
  color: var(--color-on-surface-variant);
  text-align: left;
  transition: background 0.15s, color 0.15s;
}
.locale-switcher__item:hover {
  background: var(--color-surface-container-low);
  color: var(--color-primary);
}
.locale-switcher__item--active { color: var(--color-primary); font-weight: 600; }
.locale-switcher__check { font-size: 18px; }

.menu-enter-active, .menu-leave-active { transition: opacity 0.15s, transform 0.15s; }
.menu-enter-from, .menu-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
