<script setup lang="ts">
import Button from "primevue/button";
import Tag from "primevue/tag";

import type {
  ConfiguredBackupTarget,
  ConfiguredBackupTargetBlockerCode,
} from "@/api/generated/types.gen";
import { formatDecimalBytes } from "@/composables/useBackupTargetCandidates";
import { configuredBackupTargetBlockerLabel } from "@/composables/useConfiguredBackupTargets";
import { formatUtc } from "@/composables/useFormatters";

defineProps<{ target: ConfiguredBackupTarget; canManage?: boolean }>();
defineEmits<{
  edit: [target: ConfiguredBackupTarget];
  toggle: [target: ConfiguredBackupTarget];
}>();

function blockerKey(
  targetId: string,
  blocker: ConfiguredBackupTargetBlockerCode,
  index: number,
): string {
  return `${targetId}-${blocker}-${index}`;
}
</script>

<template>
  <article
    class="target-card"
    :aria-labelledby="`configured-target-${target.id}`"
  >
    <header class="target-card__header">
      <div>
        <span class="section-kicker">{{ target.storageType }}</span>
        <h3 :id="`configured-target-${target.id}`">{{ target.displayName }}</h3>
        <p>
          {{ target.connectionName }} · {{ target.clusterName }} ·
          {{ target.storageName }}
        </p>
      </div>
      <div class="configured-target-status">
        <Tag
          :value="target.enabled ? 'Aktiviert' : 'Deaktiviert'"
          :severity="target.enabled ? 'info' : 'secondary'"
        />
        <Tag
          v-if="!target.enabled && !target.canEnable"
          value="Nicht aktivierbar"
          severity="warn"
        />
      </div>
    </header>

    <dl class="target-facts configured-target-facts">
      <div>
        <dt>Revision</dt>
        <dd>{{ target.revision }}</dd>
      </div>
      <div>
        <dt>Mindestfreiplatz</dt>
        <dd>{{ formatDecimalBytes(target.minimumFreeBytes) }}</dd>
      </div>
      <div>
        <dt>Feste Parallelität</dt>
        <dd>{{ target.fixedParallelLimit ?? "Nicht konfiguriert" }}</dd>
      </div>
      <div>
        <dt>Deaktiviert seit</dt>
        <dd>{{ target.disabledAt ? formatUtc(target.disabledAt) : "–" }}</dd>
      </div>
    </dl>

    <div v-if="target.blockers.length" class="target-blockers" role="list">
      <span
        v-for="(blocker, index) in target.blockers"
        :key="blockerKey(target.id, blocker, index)"
        role="listitem"
      >
        <i class="pi pi-lock" aria-hidden="true" />
        {{ configuredBackupTargetBlockerLabel(blocker) }}
      </span>
    </div>

    <section class="target-evidence" aria-label="Erlaubte PVE-Nodes">
      <div class="target-evidence__heading">
        <h4>Erlaubte Nodes</h4>
        <small>{{ target.allowedNodes.length }} konfiguriert</small>
      </div>
      <div v-if="target.allowedNodes.length" class="configured-node-list">
        <Tag
          v-for="node in target.allowedNodes"
          :key="node.id"
          :value="node.name"
          severity="secondary"
        />
      </div>
      <p v-else class="configured-node-empty">
        Keine erlaubten Nodes konfiguriert.
      </p>
    </section>

    <footer v-if="canManage" class="policy-card__actions">
      <Button
        type="button"
        label="Bearbeiten"
        icon="pi pi-pencil"
        severity="secondary"
        @click="$emit('edit', target)"
      />
      <Button
        type="button"
        :label="target.enabled ? 'Deaktivieren' : 'Aktivieren'"
        :icon="target.enabled ? 'pi pi-ban' : 'pi pi-check'"
        :severity="target.enabled ? 'danger' : 'success'"
        :disabled="!target.enabled && !target.canEnable"
        @click="$emit('toggle', target)"
      />
    </footer>
  </article>
</template>
