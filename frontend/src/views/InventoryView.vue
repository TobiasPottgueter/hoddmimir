<script setup lang="ts">
import { computed, onMounted, reactive } from "vue";
import Button from "primevue/button";
import PagedPicker from "@/components/common/PagedPicker.vue";
import {
  connectionPages,
  resourcePages,
  namespaceParentPages,
} from "@/composables/usePickerPages";
import {
  useQueryFilters,
  queryChoice,
  queryUuid,
} from "@/composables/useQueryFilters";
import Select from "primevue/select";

import type {
  InventoryResourceKind,
  InventoryState,
} from "@/api/generated/types.gen";
import AsyncState from "@/components/common/AsyncState.vue";
import InventoryResourceTable from "@/components/inventory/InventoryResourceTable.vue";
import { useInventoryStore } from "@/stores/inventory";

const store = useInventoryStore();

const kindOptions: Array<{ label: string; value: InventoryResourceKind }> = [
  { label: "PVE-Cluster", value: "pve_cluster" },
  { label: "PVE-Nodes", value: "pve_node" },
  { label: "VMs und Container", value: "pve_guest" },
  { label: "PVE-Storages", value: "pve_storage" },
  { label: "PBS-Server", value: "pbs_server" },
  { label: "PBS-Datastores", value: "pbs_datastore" },
  { label: "PBS-Namespaces", value: "pbs_namespace" },
  { label: "PBS-Backup-Gruppen", value: "pbs_backup_group" },
  { label: "PBS-Snapshots", value: "pbs_snapshot" },
];
const stateOptions: Array<{ label: string; value: InventoryState }> = [
  { label: "Aktiv", value: "active" },
  { label: "Archiviert", value: "archived" },
];
const guestOptions: Array<{ label: string; value: "all" | "qemu" | "lxc" }> = [
  { label: "QEMU und LXC", value: "all" },
  { label: "QEMU", value: "qemu" },
  { label: "LXC", value: "lxc" },
];

const draft = reactive({
  kind: store.kind,
  state: store.inventoryState,
  guestType: store.guestType,
  connectionId: store.connectionId,
  parentId: store.parentId,
});
const url = useQueryFilters(
  (query) => {
    store.setKind(
      queryChoice(
        query,
        "kind",
        kindOptions.map((item) => item.value),
        "pve_guest",
      ),
    );
    store.setInventoryState(
      queryChoice(query, "state", ["active", "archived"], "active"),
    );
    store.setGuestType(
      store.kind === "pve_guest"
        ? queryChoice(query, "guestType", ["all", "qemu", "lxc"], "all")
        : "all",
    );
    store.setConnectionId(queryUuid(query, "connectionId"));
    store.setParentId(
      ["pve_cluster", "pbs_server"].includes(draft.kind)
        ? ""
        : queryUuid(query, "parentId"),
    );
    Object.assign(draft, {
      kind: store.kind,
      state: store.inventoryState,
      guestType: store.guestType,
      connectionId: store.connectionId,
      parentId: store.parentId,
    });
  },
  () => void store.load(),
);
function applyFilters(): void {
  void url.apply({ ...draft });
}
function resetFilters(): void {
  void url.apply({});
}
function changeKind(value: InventoryResourceKind): void {
  draft.kind = value;
  draft.parentId = "";
  if (value !== "pve_guest") draft.guestType = "all";
}
const parentKind = computed<InventoryResourceKind>(
  () =>
    (
      ({
        pbs_datastore: "pbs_server",
        pbs_namespace: "pbs_datastore",
        pbs_backup_group: "pbs_namespace",
        pbs_snapshot: "pbs_backup_group",
      }) as Partial<Record<InventoryResourceKind, InventoryResourceKind>>
    )[draft.kind] ?? "pve_cluster",
);
const parentPages = computed(() =>
  draft.kind === "pbs_namespace"
    ? namespaceParentPages({
        inventoryState: draft.state,
        ...(draft.connectionId ? { connectionId: draft.connectionId } : {}),
      })
    : resourcePages({
        kind: parentKind.value,
        inventoryState: draft.state,
        ...(draft.connectionId ? { connectionId: draft.connectionId } : {}),
      }),
);

onMounted(() => void store.load());
</script>

<template>
  <section class="data-view" aria-labelledby="inventory-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Collector-Inventar</span>
        <h2 id="inventory-title">PVE- und PBS-Ressourcen</h2>
        <p>
          Aktive und archivierte Ressourcen mit Placement, Kapazität und
          Messfrische.
        </p>
      </div>
    </div>

    <form
      class="filter-panel"
      aria-label="Inventar filtern"
      @submit.prevent="applyFilters"
    >
      <label>
        <span>Ressourcentyp</span>
        <Select
          aria-label="Ressourcentyp"
          :model-value="draft.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          @update:model-value="changeKind"
        />
      </label>
      <label>
        <span>Inventarstatus</span>
        <Select
          aria-label="Inventarstatus"
          v-model="draft.state"
          :options="stateOptions"
          option-label="label"
          option-value="value"
        />
      </label>
      <label v-if="draft.kind === 'pve_guest'">
        <span>Gasttyp</span>
        <Select
          aria-label="Gasttyp"
          v-model="draft.guestType"
          :options="guestOptions"
          option-label="label"
          option-value="value"
        />
      </label>
      <label>
        <span>Verbindung</span>
        <PagedPicker
          :model-value="draft.connectionId"
          label="Verbindung"
          :load-page="connectionPages"
          @update:model-value="
            draft.connectionId = $event;
            draft.parentId = '';
          "
        />
      </label>
      <label v-if="!['pve_cluster', 'pbs_server'].includes(draft.kind)">
        <span>Übergeordnete Ressource</span>
        <PagedPicker
          v-model="draft.parentId"
          label="Übergeordnete Ressource"
          :load-page="parentPages"
        />
      </label>
      <Button
        type="submit"
        label="Filter anwenden"
        icon="pi pi-filter"
        :loading="store.loading"
      />
      <Button
        type="button"
        label="Filter zurücksetzen"
        severity="secondary"
        @click="resetFilters"
      />
    </form>

    <AsyncState
      :loading="store.loading"
      :error="store.error"
      :empty="store.empty"
      empty-title="Keine passenden Ressourcen"
      empty-description="Passe die Filter an oder warte auf den nächsten automatischen Collector-Zyklus."
    >
      <div class="table-panel">
        <InventoryResourceTable :resources="store.items" />
        <div v-if="store.page.hasMore" class="table-pagination">
          <Button
            label="Weitere Ressourcen laden"
            icon="pi pi-angle-down"
            severity="secondary"
            :loading="store.loading"
            @click="store.loadMore()"
          />
        </div>
      </div>
    </AsyncState>
  </section>
</template>
