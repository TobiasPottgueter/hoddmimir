<script setup lang="ts">
import Tag from "primevue/tag";

import type {
  BackupTargetBlockerCode,
  BackupTargetCandidate,
  BackupTargetNodeEvidence,
} from "@/api/generated/types.gen";
import {
  backupTargetBlockerLabel,
  backupTargetCapacityLabel,
  backupTargetExecutorStatusLabel,
  evidenceFreshnessLabel,
  formatDecimalBytes,
  pbsEndpointMatchLabel,
} from "@/composables/useBackupTargetCandidates";
import { formatUtc } from "@/composables/useFormatters";

defineProps<{ candidate: BackupTargetCandidate }>();

function booleanLabel(value: boolean | null): string {
  if (value === null) return "Keine Evidenz";
  return value ? "Ja" : "Nein";
}

function capacitySeverity(node: BackupTargetNodeEvidence) {
  return node.capacityStatus === "measured"
    ? "info"
    : node.capacityStatus === "missing"
      ? "secondary"
      : "danger";
}

function blockerKey(
  subject: string,
  blocker: BackupTargetBlockerCode,
  index: number,
): string {
  return `${subject}-${blocker}-${index}`;
}
</script>

<template>
  <article class="target-card" :aria-labelledby="`target-${candidate.id}`">
    <header class="target-card__header">
      <div>
        <span class="section-kicker">{{ candidate.storageType }}</span>
        <h3 :id="`target-${candidate.id}`">{{ candidate.storageName }}</h3>
        <p>{{ candidate.connectionName }} · {{ candidate.clusterName }}</p>
      </div>
      <Tag
        :value="candidate.canEnable ? 'Aktivierbar' : 'Nicht aktivierbar'"
        :severity="candidate.canEnable ? 'success' : 'warn'"
      />
    </header>

    <dl class="target-facts">
      <div>
        <dt>Inventarstatus</dt>
        <dd>
          {{ candidate.inventoryState === "active" ? "Aktiv" : "Archiviert" }}
        </dd>
      </div>
      <div>
        <dt>Storage-Sicht</dt>
        <dd>{{ formatUtc(candidate.observedAt) }}</dd>
      </div>
      <div>
        <dt>Shared</dt>
        <dd>{{ candidate.shared ? "Ja" : "Nein" }}</dd>
      </div>
    </dl>

    <div v-if="candidate.blockers.length" class="target-blockers" role="list">
      <span
        v-for="(blocker, index) in candidate.blockers"
        :key="blockerKey(candidate.id, blocker, index)"
        role="listitem"
      >
        <i class="pi pi-lock" aria-hidden="true" />
        {{ backupTargetBlockerLabel(blocker) }}
      </span>
    </div>

    <section class="target-evidence" aria-label="PVE-Node-Evidenz">
      <div class="target-evidence__heading">
        <h4>PVE-Nodes</h4>
        <small>{{ candidate.nodes.length }} beobachtet</small>
      </div>
      <div class="target-node-grid">
        <article
          v-for="node in candidate.nodes"
          :key="node.nodeId"
          class="target-node"
        >
          <div class="target-node__header">
            <strong>{{ node.nodeName }}</strong>
            <Tag
              :value="backupTargetCapacityLabel(node.capacityStatus)"
              :severity="capacitySeverity(node)"
            />
          </div>
          <dl>
            <div>
              <dt>Konfiguriert</dt>
              <dd>{{ booleanLabel(node.configuredForStorage) }}</dd>
            </div>
            <div>
              <dt>Enabled / Active</dt>
              <dd>
                {{ booleanLabel(node.enabled) }} /
                {{ booleanLabel(node.active) }}
              </dd>
            </div>
            <div>
              <dt>Frei</dt>
              <dd>{{ formatDecimalBytes(node.availableBytes) }}</dd>
            </div>
            <div>
              <dt>Belegt / Gesamt</dt>
              <dd>
                {{ formatDecimalBytes(node.usedBytes) }} /
                {{ formatDecimalBytes(node.totalBytes) }}
              </dd>
            </div>
            <div class="target-node__wide">
              <dt>Kapazitätsmessung</dt>
              <dd>{{ formatUtc(node.observedAt) }}</dd>
            </div>
          </dl>
          <ul v-if="node.blockers.length" class="evidence-blockers">
            <li v-for="blocker in node.blockers" :key="blocker">
              {{ backupTargetBlockerLabel(blocker) }}
            </li>
          </ul>
        </article>
      </div>
    </section>

    <section class="target-evidence" aria-label="Executor-Evidenz">
      <div class="target-evidence__heading">
        <h4>Executor-Berechtigungen</h4>
        <Tag
          :value="backupTargetExecutorStatusLabel(candidate.executor.status)"
          :severity="
            candidate.executor.status === 'authorized'
              ? 'success'
              : candidate.executor.status === 'unauthorized'
                ? 'danger'
                : 'warn'
          "
        />
      </div>
      <dl class="target-facts">
        <div>
          <dt>Zielkontexte</dt>
          <dd>{{ candidate.executor.targetCount }}</dd>
        </div>
        <div>
          <dt>Node-Nachweise</dt>
          <dd>
            {{ candidate.executor.observedNodeCount }} /
            {{ candidate.executor.expectedNodeCount }}
          </dd>
        </div>
        <div>
          <dt>VM.Backup</dt>
          <dd>{{ booleanLabel(candidate.executor.vmBackupAuthorized) }}</dd>
        </div>
        <div>
          <dt>Datastore.AllocateSpace</dt>
          <dd>
            {{ booleanLabel(candidate.executor.datastoreAllocateAuthorized) }}
          </dd>
        </div>
        <div>
          <dt>Gesamtfreigabe</dt>
          <dd>{{ booleanLabel(candidate.executor.authorized) }}</dd>
        </div>
        <div>
          <dt>Freshness / Messung</dt>
          <dd>
            {{ evidenceFreshnessLabel(candidate.executor.freshness) }} ·
            {{ formatUtc(candidate.executor.observedAt) }}
          </dd>
        </div>
      </dl>
      <ul v-if="candidate.executor.blockers.length" class="evidence-blockers">
        <li v-for="blocker in candidate.executor.blockers" :key="blocker">
          {{ backupTargetBlockerLabel(blocker) }}
        </li>
      </ul>
    </section>

    <section
      v-if="candidate.pbs"
      class="target-evidence target-pbs"
      aria-label="PBS-Evidenz"
    >
      <div class="target-evidence__heading">
        <h4>PBS-Zuordnung</h4>
        <Tag
          :value="pbsEndpointMatchLabel(candidate.pbs.endpointMatch)"
          :severity="
            candidate.pbs.endpointMatch === 'matched' ? 'info' : 'warn'
          "
        />
      </div>
      <dl class="target-facts target-facts--pbs">
        <div>
          <dt>Beobachteter Endpunkt</dt>
          <dd>{{ candidate.pbs.server }}:{{ candidate.pbs.port }}</dd>
        </div>
        <div>
          <dt>Datastore / Namespace</dt>
          <dd>
            {{ candidate.pbs.datastore }} /
            {{ candidate.pbs.namespace ?? "@root" }}
          </dd>
        </div>
        <div>
          <dt>Mapping beobachtet</dt>
          <dd>{{ formatUtc(candidate.pbs.mappingObservedAt) }}</dd>
        </div>
        <div>
          <dt>Kapazitätssemantik</dt>
          <dd>{{ candidate.pbs.capacitySemantics ?? "Keine Evidenz" }}</dd>
        </div>
        <div>
          <dt>Frei / Gesamt</dt>
          <dd>
            {{ formatDecimalBytes(candidate.pbs.availableBytes) }} /
            {{ formatDecimalBytes(candidate.pbs.totalBytes) }}
          </dd>
        </div>
        <div>
          <dt>Kapazitätsmessung</dt>
          <dd>{{ formatUtc(candidate.pbs.capacityObservedAt) }}</dd>
        </div>
      </dl>
      <ul v-if="candidate.pbs.blockers.length" class="evidence-blockers">
        <li v-for="blocker in candidate.pbs.blockers" :key="blocker">
          {{ backupTargetBlockerLabel(blocker) }}
        </li>
      </ul>
    </section>
  </article>
</template>
