<script setup lang="ts">
import Button from "primevue/button";
import Tag from "primevue/tag";

import type { ConfiguredPolicy } from "@/api/generated/types.gen";
import { formatDecimalBytes } from "@/composables/useBackupTargetCandidates";
import {
  policyBlockerLabel,
  policyStatusLabel,
  retentionSummary,
} from "@/composables/usePolicies";

defineProps<{
  policy: ConfiguredPolicy;
  selected: boolean;
  canManage?: boolean;
}>();
defineEmits<{
  showSelection: [policyId: string];
  edit: [policy: ConfiguredPolicy];
  toggle: [policy: ConfiguredPolicy];
}>();
</script>

<template>
  <article
    class="target-card policy-card"
    :aria-labelledby="`policy-${policy.id}`"
  >
    <header class="target-card__header">
      <div>
        <span class="section-kicker">Revision {{ policy.revision }}</span>
        <h3 :id="`policy-${policy.id}`">{{ policy.displayName }}</h3>
        <p>{{ policy.connectionName }} · {{ policy.clusterName }}</p>
      </div>
      <Tag
        :value="policyStatusLabel(policy.status)"
        :severity="
          policy.status === 'enabled'
            ? 'success'
            : policy.status === 'draft'
              ? 'warn'
              : 'secondary'
        "
      />
    </header>

    <dl class="target-facts configured-target-facts">
      <div>
        <dt>Backupziel</dt>
        <dd>{{ policy.targetName ?? "Nicht gewählt" }}</dd>
      </div>
      <div>
        <dt>Priorität</dt>
        <dd>{{ policy.priority ?? "Nicht festgelegt" }}</dd>
      </div>
      <div>
        <dt>Modus / Kompression</dt>
        <dd>{{ policy.mode ?? "–" }} / {{ policy.compression ?? "–" }}</dd>
      </div>
      <div>
        <dt>Zeitplan</dt>
        <dd>{{ policy.schedule ?? "Nicht festgelegt" }}</dd>
      </div>
      <div>
        <dt>Maximales Alter</dt>
        <dd>
          {{
            policy.maximumAgeSeconds === null
              ? "–"
              : `${policy.maximumAgeSeconds} s`
          }}
        </dd>
      </div>
      <div>
        <dt>Schreibschwelle</dt>
        <dd>{{ formatDecimalBytes(policy.bytesWrittenThreshold) }}</dd>
      </div>
      <div>
        <dt>Cooldown</dt>
        <dd>
          {{
            policy.cooldownSeconds === null
              ? "–"
              : `${policy.cooldownSeconds} s`
          }}
        </dd>
      </div>
      <div>
        <dt>Retention</dt>
        <dd>{{ retentionSummary(policy.desiredRetention) }}</dd>
      </div>
      <div>
        <dt>PVE-Fehlermails</dt>
        <dd>
          {{
            policy.failureNotificationRecipients.length === 0
              ? "Deaktiviert"
              : policy.failureNotificationRecipients.join(", ")
          }}
        </dd>
      </div>
    </dl>

    <div v-if="policy.blockers.length" class="target-blockers" role="list">
      <span v-for="blocker in policy.blockers" :key="blocker" role="listitem">
        <i class="pi pi-lock" aria-hidden="true" />{{
          policyBlockerLabel(blocker)
        }}
      </span>
    </div>

    <footer class="policy-card__actions">
      <Button
        type="button"
        :label="selected ? 'Auswahl wird angezeigt' : 'Auswahl anzeigen'"
        :icon="selected ? 'pi pi-eye' : 'pi pi-list'"
        severity="secondary"
        :aria-pressed="selected"
        @click="$emit('showSelection', policy.id)"
      />
      <Button
        v-if="canManage"
        type="button"
        label="Bearbeiten"
        icon="pi pi-pencil"
        severity="secondary"
        @click="$emit('edit', policy)"
      />
      <Button
        v-if="canManage"
        type="button"
        :label="policy.status === 'enabled' ? 'Deaktivieren' : 'Aktivieren'"
        :icon="policy.status === 'enabled' ? 'pi pi-ban' : 'pi pi-check'"
        :severity="policy.status === 'enabled' ? 'danger' : 'success'"
        :disabled="policy.status !== 'enabled' && !policy.canEnable"
        @click="$emit('toggle', policy)"
      />
    </footer>
  </article>
</template>
