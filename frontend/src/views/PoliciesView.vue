<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";

import type {
  BulkConfigurationCommandRequest,
  ConfiguredPolicy,
  PolicyCommandRequest,
  PolicyStatus,
} from "@/api/generated/types.gen";
import AsyncState from "@/components/common/AsyncState.vue";
import ConfiguredPolicyCard from "@/components/policies/ConfiguredPolicyCard.vue";
import PolicyForm from "@/components/policies/PolicyForm.vue";
import PolicySelectionEditor from "@/components/policies/PolicySelectionEditor.vue";
import { usePolicies } from "@/composables/usePolicies";
import { useAuthStore } from "@/stores/auth";
import { useConfigurationCommandsStore } from "@/stores/configurationCommands";
import { useConfigurationInventoryStore } from "@/stores/configurationInventory";
import { useConfiguredBackupTargetsStore } from "@/stores/configuredBackupTargets";

const { store } = usePolicies();
const auth = useAuthStore();
const commands = useConfigurationCommandsStore();
const inventory = useConfigurationInventoryStore();
const targetStore = useConfiguredBackupTargetsStore();
const canManage = computed(() =>
  auth.hasPermission("backup_configuration.manage"),
);
const selectedPolicy = computed(
  () =>
    store.items.find((policy) => policy.id === store.selectedPolicyId) ?? null,
);
const formOpen = ref(false);
const editedPolicy = ref<ConfiguredPolicy | null>(null);
const searchDraft = ref(store.search);
const statusDraft = ref<PolicyStatus | null>(store.status);
const statusOptions: Array<{ label: string; value: PolicyStatus | null }> = [
  { label: "Alle Zustände", value: null },
  { label: "Entwurf", value: "draft" },
  { label: "Aktiviert", value: "enabled" },
  { label: "Deaktiviert", value: "disabled" },
];

function applyFilters() {
  store.setSearch(searchDraft.value);
  store.setStatus(statusDraft.value);
  void store.load();
}

function openCreate(): void {
  commands.clearResult();
  editedPolicy.value = null;
  formOpen.value = true;
}

function openEdit(policy: ConfiguredPolicy): void {
  commands.clearResult();
  editedPolicy.value = policy;
  formOpen.value = true;
}

function closeForm(): void {
  formOpen.value = false;
  editedPolicy.value = null;
}

async function savePolicy(
  body: PolicyCommandRequest,
  id: string | null,
): Promise<void> {
  if (await commands.savePolicy(body, id)) {
    closeForm();
    await store.load();
  }
}

async function togglePolicy(policy: ConfiguredPolicy): Promise<void> {
  if (
    await commands.setPolicyEnabled(
      policy.id,
      { expectedRevision: policy.revision },
      policy.status !== "enabled",
    )
  ) {
    await store.load();
  }
}

async function changeSelection(
  operation:
    | "selection.upsert"
    | "selection.disable"
    | "guest_override.upsert"
    | "guest_override.disable",
  entries: BulkConfigurationCommandRequest["entries"],
): Promise<void> {
  const policy = selectedPolicy.value;
  if (policy === null) return;
  if (
    await commands.changeSelection(
      policy.id,
      { expectedRevision: policy.revision, entries },
      operation,
    )
  ) {
    await store.load();
    await store.selectPolicyForEditing(policy.id);
  }
}

async function reloadServerState(): Promise<void> {
  const selectedId = store.selectedPolicyId;
  commands.clearResult();
  closeForm();
  await store.load();
  if (selectedId !== null) await store.selectPolicyForEditing(selectedId);
}

async function selectPolicy(policyId: string): Promise<void> {
  const policy = store.items.find((item) => item.id === policyId);
  if (policy === undefined) return;
  await Promise.all([
    store.selectPolicyForEditing(policyId),
    inventory.loadHierarchy(policy.connectionId, policy.clusterId),
  ]);
}

onMounted(() => {
  void store.load();
  void targetStore.loadAllForConfiguration();
  void inventory.loadClusters();
});
</script>

<template>
  <section class="data-view policy-view" aria-labelledby="policies-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Policy-Konfiguration</span>
        <h2 id="policies-title">Policies</h2>
        <p>
          Backupregeln, Auswahlzuweisungen und Guest-Overrides aus der
          serverseitigen Projektion. Änderungen verwenden CSRF, Idempotenz und
          erwartete Revisionen.
        </p>
      </div>
      <Button
        v-if="canManage"
        type="button"
        label="Policy anlegen"
        icon="pi pi-plus"
        @click="openCreate"
      />
    </div>

    <Message v-if="!canManage" severity="secondary" :closable="false">
      Diese Ansicht ist read-only. Für Änderungen wird
      <code>backup_configuration.manage</code> benötigt.
    </Message>
    <Message v-else severity="warn" :closable="false">
      Die WebApp speichert ausschließlich Konfiguration. Sie scannt nicht
      manuell und startet oder stoppt keine Backups direkt.
    </Message>

    <Message v-if="commands.success" severity="success" :closable="false">{{
      commands.success
    }}</Message>
    <Message v-if="commands.error" severity="error" :closable="false">
      {{ commands.error }}
      <span v-if="commands.conflictRevision !== null"
        >Aktuelle Serverrevision: {{ commands.conflictRevision }}.</span
      >
      <span v-if="commands.blockers.length"
        >Blocker: {{ commands.blockers.join(", ") }}.</span
      >
    </Message>
    <Message
      v-if="targetStore.configurationError"
      severity="error"
      :closable="false"
    >
      {{ targetStore.configurationError }}
    </Message>
    <Button
      v-if="commands.conflictRevision !== null"
      type="button"
      label="Aktuellen Serverstand laden"
      icon="pi pi-refresh"
      severity="secondary"
      @click="reloadServerState"
    />

    <PolicyForm
      v-if="formOpen && canManage"
      :policy="editedPolicy"
      :targets="targetStore.configurationItems"
      :clusters="inventory.clusters"
      :pending="commands.pending || targetStore.configurationLoading"
      @submit="savePolicy"
      @cancel="closeForm"
    />

    <form
      class="filter-panel policy-filter"
      aria-label="Policies filtern"
      @submit.prevent="applyFilters"
    >
      <label>
        <span>Suche</span>
        <InputText
          v-model="searchDraft"
          placeholder="Policy, Verbindung oder Cluster"
          autocomplete="off"
        />
      </label>
      <label>
        <span>Status</span>
        <Select
          v-model="statusDraft"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          aria-label="Policy-Status"
        />
      </label>
      <Button
        type="submit"
        label="Filter anwenden"
        icon="pi pi-filter"
        :loading="store.loading"
      />
    </form>

    <AsyncState
      :loading="store.loading"
      :error="store.error"
      :empty="store.empty"
      empty-title="Keine Policies"
      empty-description="Es wurden noch keine Policies konfiguriert oder der Filter liefert keine Treffer."
    >
      <div class="target-grid">
        <ConfiguredPolicyCard
          v-for="policy in store.items"
          :key="policy.id"
          :policy="policy"
          :selected="store.selectedPolicyId === policy.id"
          :can-manage="canManage"
          @show-selection="selectPolicy"
          @edit="openEdit"
          @toggle="togglePolicy"
        />
      </div>
      <div v-if="store.page.hasMore" class="table-pagination policy-pagination">
        <Button
          label="Weitere Policies laden"
          icon="pi pi-angle-down"
          severity="secondary"
          :loading="store.loading"
          @click="store.loadMore()"
        />
      </div>
    </AsyncState>

    <section
      v-if="store.selectedPolicyId"
      class="policy-selection"
      aria-labelledby="policy-selection-title"
    >
      <div class="target-section-heading">
        <span class="section-kicker">Serverseitige Auswahlprojektion</span>
        <h3 id="policy-selection-title">Auswahl und Guest-Overrides</h3>
      </div>
      <AsyncState
        :loading="store.selectionLoading"
        :error="store.selectionError"
        :empty="store.selectionEmpty"
        empty-title="Keine Auswahlregeln"
        empty-description="Für diese Policy sind keine Zuweisungen oder Guest-Overrides vorhanden."
      >
        <PolicySelectionEditor
          v-if="selectedPolicy"
          :policy="selectedPolicy"
          :entries="store.selectionItems"
          :nodes="inventory.nodes"
          :guests="inventory.guests"
          :can-manage="canManage"
          :pending="commands.pending"
          @change="changeSelection"
        />
        <div
          v-if="store.selectionPage.hasMore"
          class="table-pagination policy-selection-pagination"
        >
          <Button
            label="Weitere Auswahlregeln laden"
            icon="pi pi-angle-down"
            severity="secondary"
            :loading="store.selectionLoading"
            @click="store.loadMoreSelection()"
          />
        </div>
      </AsyncState>
    </section>
  </section>
</template>
