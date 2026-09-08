<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import QueueHistory from "@/components/operations/QueueHistory.vue";
import Button from "primevue/button";
import Dialog from "primevue/dialog";
import Message from "primevue/message";
import Select from "primevue/select";
import Tag from "primevue/tag";
import type { BackupRequest } from "@/api/operationsApi";
import type { BackupRequestState } from "@/api/generated/types.gen";
import {
  backupRequestStateLabel,
  backupRequestStateSeverity,
} from "@/composables/useBackupOperations";
import { formatUtc } from "@/composables/useFormatters";
import { useAuthStore } from "@/stores/auth";
import { useBackupOperationsStore } from "@/stores/backupOperations";
import { useConfigurationInventoryStore } from "@/stores/configurationInventory";
import { usePoliciesStore } from "@/stores/policies";

const store = useBackupOperationsStore();
const auth = useAuthStore();
const policies = usePoliciesStore();
const inventory = useConfigurationInventoryStore();
const requestStates: BackupRequestState[] = [
  "pending",
  "retry_wait",
  "leased",
  "starting",
  "running",
  "reconcile_required",
  "succeeded",
  "failed",
  "cancelled",
  "unknown",
];
const requestStateOptions = [
  { label: "Alle Zustände", value: "all" },
  ...requestStates.map((state) => ({
    label: backupRequestStateLabel(state),
    value: state,
  })),
];
const policyId = ref<string | null>(null);
const guestId = ref<string | null>(null);
const cancelCandidate = ref<BackupRequest | null>(null);
const cancelOpen = computed({
  get: () => cancelCandidate.value !== null,
  set: (visible: boolean) => {
    if (!visible) cancelCandidate.value = null;
  },
});
const enabledPolicies = computed(() =>
  policies.operationItems.filter((policy) => policy.status === "enabled"),
);
const selectedPolicy = computed(
  () =>
    enabledPolicies.value.find((policy) => policy.id === policyId.value) ??
    null,
);
watch(selectedPolicy, (policy) => {
  guestId.value = null;
  if (policy)
    void inventory.loadHierarchy(policy.connectionId, policy.clusterId);
});
async function submitManual(): Promise<void> {
  const policy = selectedPolicy.value;
  if (!policy || guestId.value === null) return;
  await store.manual({
    guestId: guestId.value,
    policyId: policy.id,
    expectedRevision: policy.revision,
  });
}
async function confirmCancel(): Promise<void> {
  if (cancelCandidate.value === null) return;
  if (await store.cancel(cancelCandidate.value)) cancelCandidate.value = null;
}
onMounted(() => {
  void store.loadQueue();
  void policies.loadAllEnabledForOperations();
});
</script>

<template>
  <section class="data-view" aria-labelledby="queue-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Verbindliche Prioritätsreihenfolge</span>
        <h2 id="queue-title">Backup-Queue</h2>
        <p>
          Manuell, nie gesichert, maximales Alter und Schreibvolumen – in dieser
          Reihenfolge.
        </p>
      </div>
    </div>
    <QueueHistory />
    <Message v-if="store.error" severity="error" :closable="false">{{
      store.error
    }}</Message>
    <Message v-if="policies.operationError" severity="error" :closable="false">
      {{ policies.operationError }}
    </Message>
    <Message
      v-if="!auth.hasPermission('backup_operations.manage')"
      severity="info"
      :closable="false"
      >Die Queue ist für dich read-only. Für manuelle Backups oder Abbrüche ist
      <code>backup_operations.manage</code> erforderlich.</Message
    >
    <form
      class="filter-bar"
      aria-label="Backup-Queue filtern"
      @submit.prevent="store.loadQueue()"
    >
      <Select
        v-model="store.queueState"
        :options="requestStateOptions"
        option-label="label"
        option-value="value"
        aria-label="Queue-Zustand"
      />
      <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
    </form>
    <form
      v-if="auth.hasPermission('backup_operations.manage')"
      class="operation-command"
      @submit.prevent="submitManual"
    >
      <h3>Manuelles Backup anfordern</h3>
      <label
        >Aktivierte Policy<Select
          v-model="policyId"
          :options="enabledPolicies"
          option-label="displayName"
          option-value="id"
          placeholder="Policy auswählen"
      /></label>
      <label
        >VM oder CT<Select
          v-model="guestId"
          :options="inventory.guests"
          option-label="displayName"
          option-value="id"
          placeholder="Gast auswählen"
          ><template #option="slotProps"
            >{{ slotProps.option.attributes.guestType.toUpperCase() }}
            {{ slotProps.option.attributes.vmid }} ·
            {{ slotProps.option.displayName }}</template
          ></Select
        ></label
      >
      <Button
        type="submit"
        label="Backup anfordern"
        icon="pi pi-plus"
        :disabled="selectedPolicy === null || guestId === null"
        :loading="store.loading || policies.operationLoading"
      />
    </form>
    <div class="shadow-list" aria-live="polite">
      <article
        v-for="request in store.queue"
        :key="request.id"
        class="shadow-evaluation"
      >
        <div>
          <strong>{{
            request.guestName ?? `Gast ${request.vmid ?? "–"}`
          }}</strong
          ><small>{{ request.nodeName }} · {{ request.targetName }}</small>
        </div>
        <Tag
          :value="backupRequestStateLabel(request.state)"
          :severity="backupRequestStateSeverity(request.state)"
        />
        <span
          >Priorität {{ request.priority }} ·
          {{ formatUtc(request.scheduledAt) }}</span
        >
        <Button
          v-if="
            auth.hasPermission('backup_operations.manage') &&
            !['succeeded', 'failed', 'cancelled', 'unknown'].includes(
              request.state,
            )
          "
          label="Abbrechen"
          severity="danger"
          text
          size="small"
          @click="cancelCandidate = request"
        />
      </article>
    </div>
    <Message v-if="store.emptyQueue" severity="secondary" :closable="false"
      >Keine Backup-Anforderungen vorhanden.</Message
    >
    <Button
      v-if="store.queuePage.hasMore"
      label="Weitere Anforderungen"
      severity="secondary"
      @click="store.loadQueue(undefined, true)"
    />
    <Dialog
      v-model:visible="cancelOpen"
      modal
      header="Backup-Anforderung abbrechen"
      :style="{ width: '32rem' }"
      ><p>
        Die Anforderung wird revisioniert abgebrochen. Ein laufender PVE-Task
        wird erst durch den Backup-Worker gestoppt.
      </p>
      <template #footer
        ><Button
          label="Zurück"
          severity="secondary"
          @click="cancelCandidate = null" /><Button
          label="Abbruch bestätigen"
          severity="danger"
          @click="confirmCancel" /></template
    ></Dialog>
  </section>
</template>
