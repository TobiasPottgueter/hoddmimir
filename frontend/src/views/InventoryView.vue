<script setup lang="ts">
import { onMounted } from "vue";
import Button from "primevue/button";
import InputText from "primevue/inputtext";
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

function applyFilters(): void {
  store.setConnectionId(store.connectionId);
  store.setParentId(store.parentId);
  void store.load();
}

function changeKind(value: InventoryResourceKind): void {
  store.setKind(value);
  void store.load();
}

function changeState(value: InventoryState): void {
  store.setInventoryState(value);
  void store.load();
}

function changeGuestType(value: "all" | "qemu" | "lxc"): void {
  store.setGuestType(value);
  void store.load();
}

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
          :model-value="store.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          @update:model-value="changeKind"
        />
      </label>
      <label>
        <span>Inventarstatus</span>
        <Select
          :model-value="store.inventoryState"
          :options="stateOptions"
          option-label="label"
          option-value="value"
          @update:model-value="changeState"
        />
      </label>
      <label v-if="store.kind === 'pve_guest'">
        <span>Gasttyp</span>
        <Select
          :model-value="store.guestType"
          :options="guestOptions"
          option-label="label"
          option-value="value"
          @update:model-value="changeGuestType"
        />
      </label>
      <label>
        <span>Verbindungs-ID</span>
        <InputText
          v-model="store.connectionId"
          placeholder="Optional UUID"
          autocomplete="off"
        />
      </label>
      <label v-if="!['pve_cluster', 'pbs_server'].includes(store.kind)">
        <span>Parent-ID</span>
        <InputText
          v-model="store.parentId"
          placeholder="Optional UUID"
          autocomplete="off"
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
