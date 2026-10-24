<script setup>
/**
 * The admin Database tab: backups, the SQL sandbox, and the shared log of what
 * the sandbox was asked to do — in that order, so the way back (a fresh dump)
 * sits above the tool that can change things.
 *
 * It was the Dumps tab. The backups section is that tab's content unchanged;
 * /admin/dumps redirects here.
 */
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useAdminSqlStore } from '@/stores/adminSql'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import DumpsPanel from '@/components/admin/DumpsPanel.vue'
import SqlSandboxPanel from '@/components/admin/SqlSandboxPanel.vue'
import SqlLogTable from '@/components/admin/SqlLogTable.vue'

const sql = useAdminSqlStore()
const toast = useToastStore()
const { t } = useI18n()
const { error } = storeToRefs(sql)

onMounted(async () => {
  try {
    await Promise.all([sql.fetchStatus(), sql.fetchLog(1)])
  } catch (e) {
    toast.error(apiErrorMessage(e, t('admin.sql.loadFailed')))
  }
})
</script>

<template>
  <div class="database">
    <section class="database__section" aria-labelledby="database-backups">
      <h2 id="database-backups" class="database__heading">{{ t('admin.database.backups') }}</h2>
      <DumpsPanel />
    </section>

    <section class="database__section" aria-labelledby="database-sandbox">
      <h2 id="database-sandbox" class="database__heading">{{ t('admin.database.sandbox') }}</h2>
      <div v-if="error === 'forbidden'" class="database__state">
        <span class="material-symbols-outlined">lock</span>
        <p>{{ t('admin.forbidden') }}</p>
      </div>
      <SqlSandboxPanel v-else />
    </section>

    <section v-if="error !== 'forbidden'" class="database__section" aria-labelledby="database-log">
      <h2 id="database-log" class="database__heading">{{ t('admin.database.log') }}</h2>
      <SqlLogTable />
    </section>
  </div>
</template>

<style scoped>
.database {
  display: flex;
  flex-direction: column;
  gap: var(--space-xl);
}
.database__section {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}
.database__heading {
  margin: 0;
  font-family: var(--font-display);
  font-size: var(--text-headline-md);
  line-height: var(--lh-headline-md);
  color: var(--color-on-surface);
}
.database__state {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  color: var(--color-on-surface-variant);
}
</style>
