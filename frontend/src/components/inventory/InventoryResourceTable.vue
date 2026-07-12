<script setup lang="ts">
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Tag from "primevue/tag";

import type { InventoryResource } from "@/api/generated/types.gen";
import { formatBytes, formatUtc } from "@/composables/useFormatters";
import {
  booleanAttribute,
  numberAttribute,
  stringAttribute,
  stringListAttribute,
} from "@/composables/useResourceAttributes";
import FreshnessTag from "./FreshnessTag.vue";

defineProps<{ resources: InventoryResource[] }>();

const kindLabels: Record<InventoryResource["kind"], string> = {
  pve_cluster: "PVE-Cluster",
  pve_node: "PVE-Node",
  pve_guest: "PVE-Gast",
  pve_storage: "PVE-Storage",
  pbs_server: "PBS-Server",
  pbs_datastore: "PBS-Datastore",
  pbs_namespace: "PBS-Namespace",
  pbs_backup_group: "PBS-Backup-Gruppe",
  pbs_snapshot: "PBS-Snapshot",
};

function details(resource: InventoryResource): string {
  switch (resource.kind) {
    case "pve_cluster":
      return stringAttribute(resource, "topology") ?? "Topologie unbekannt";
    case "pve_node":
      return `API: ${stringAttribute(resource, "apiStatus") ?? "unbekannt"}`;
    case "pve_guest": {
      const guestType =
        stringAttribute(resource, "guestType")?.toUpperCase() ?? "Gast";
      const vmid = numberAttribute(resource, "vmid");
      const node = stringAttribute(resource, "nodeName") ?? "ohne Placement";
      return `${guestType} ${vmid ?? "–"} · ${node}`;
    }
    case "pve_storage": {
      const type = stringAttribute(resource, "storageType") ?? "unbekannt";
      const content =
        stringListAttribute(resource, "content").join(", ") || "kein Content";
      return `${type} · ${content}`;
    }
    case "pbs_server": {
      const version = stringAttribute(resource, "version") ?? "unbekannt";
      const used = numberAttribute(resource, "memoryUsedBytes");
      const total = numberAttribute(resource, "memoryTotalBytes");
      return `PBS ${version} · RAM ${formatBytes(used)} / ${formatBytes(total)}`;
    }
    case "pbs_datastore": {
      const available = numberAttribute(resource, "availableBytes");
      const total = numberAttribute(resource, "totalBytes");
      return `${stringAttribute(resource, "backendType") ?? "Datastore"} · Frei ${formatBytes(available)} / ${formatBytes(total)}`;
    }
    case "pbs_namespace": {
      const hierarchy =
        resource.parentId === null && resource.attributes.namespaceDepth > 0
          ? "Parent nicht sichtbar (ACL)"
          : `Tiefe ${resource.attributes.namespaceDepth}`;
      return `${resource.attributes.namespacePath || "@root"} · ${hierarchy}`;
    }
    case "pbs_backup_group":
      return `${resource.attributes.backupType.toUpperCase()} · ${resource.attributes.backupId}`;
    case "pbs_snapshot": {
      const verification =
        resource.attributes.verificationState === "ok"
          ? "verifiziert"
          : resource.attributes.verificationState === "failed"
            ? "Verifikation fehlgeschlagen"
            : "nicht verifiziert";
      const protection = resource.attributes.protected
        ? "geschützt"
        : "nicht geschützt";
      return `${formatUtc(resource.attributes.backupTime)} · ${formatBytes(resource.attributes.sizeBytes)} · ${protection} · ${verification}`;
    }
  }
}

function warning(resource: InventoryResource): string | null {
  if (resource.kind === "pve_storage") {
    if (booleanAttribute(resource, "disabled") === true) return "Deaktiviert";
    if (booleanAttribute(resource, "supportsBackup") === false)
      return "Kein Backup-Content";
  }
  if (resource.kind === "pbs_datastore") {
    if (booleanAttribute(resource, "allowsBackupWrites") === false)
      return "Nur lesbar";
    const maintenance = stringAttribute(resource, "maintenanceMode");
    if (maintenance !== null) return `Wartung: ${maintenance}`;
  }
  if (
    resource.kind === "pbs_snapshot" &&
    resource.attributes.verificationState === "failed"
  ) {
    return "Verifikation fehlgeschlagen";
  }
  return null;
}
</script>

<template>
  <DataTable
    :value="resources"
    data-key="id"
    striped-rows
    responsive-layout="scroll"
    table-style="min-width: 56rem"
    aria-label="Inventarressourcen"
  >
    <Column field="displayName" header="Ressource">
      <template #body="{ data }">
        <div class="resource-name">
          <strong>{{ (data as InventoryResource).displayName }}</strong>
          <span>{{ (data as InventoryResource).connectionName }}</span>
        </div>
      </template>
    </Column>
    <Column field="kind" header="Typ">
      <template #body="{ data }">
        <Tag
          :value="kindLabels[(data as InventoryResource).kind]"
          severity="info"
        />
      </template>
    </Column>
    <Column header="Details">
      <template #body="{ data }">
        <span>{{ details(data as InventoryResource) }}</span>
        <Tag
          v-if="warning(data as InventoryResource)"
          class="resource-warning"
          :value="warning(data as InventoryResource) ?? undefined"
          severity="warn"
        />
      </template>
    </Column>
    <Column field="inventoryState" header="Inventarstatus">
      <template #body="{ data }">
        <Tag
          :value="
            (data as InventoryResource).inventoryState === 'active'
              ? 'Aktiv'
              : 'Archiviert'
          "
          :severity="
            (data as InventoryResource).inventoryState === 'active'
              ? 'success'
              : 'secondary'
          "
        />
      </template>
    </Column>
    <Column header="Messzustand">
      <template #body="{ data }">
        <div class="freshness-cell">
          <FreshnessTag
            :timestamp="
              (data as InventoryResource).stateObservedAt ??
              (data as InventoryResource).lastSeenAt
            "
          />
          <small>{{
            formatUtc((data as InventoryResource).stateObservedAt)
          }}</small>
        </div>
      </template>
    </Column>
  </DataTable>
</template>
