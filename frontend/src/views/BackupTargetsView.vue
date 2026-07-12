<script setup lang="ts">
import { onMounted, ref } from "vue";
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";

import AsyncState from "@/components/common/AsyncState.vue";
import BackupTargetCandidateCard from "@/components/targets/BackupTargetCandidateCard.vue";
import { useBackupTargetCandidates } from "@/composables/useBackupTargetCandidates";

const { store, freshnessUnresolved } = useBackupTargetCandidates();
const connectionDraft = ref(store.connectionId);
const clusterDraft = ref(store.clusterId);

function applyFilters(): void {
  store.setConnectionId(connectionDraft.value);
  store.setClusterId(clusterDraft.value);
  void store.load();
}

onMounted(() => void store.load());
</script>

<template>
  <section class="data-view target-view" aria-labelledby="backup-targets-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Read-only Zielprüfung</span>
        <h2 id="backup-targets-title">Backupziel-Kandidaten</h2>
        <p>
          PVE-Storage-, Node- und PBS-Evidenz aus dem automatischen Collector.
          Diese Ansicht ändert keine Konfiguration und startet keine Backups.
        </p>
      </div>
    </div>

    <Message severity="warn" :closable="false">
      Die fachliche Freshness-Grenze ist noch nicht festgelegt. Deshalb bleiben
      alle Kandidaten fail-closed und können nicht aktiviert werden.
    </Message>

    <form
      class="filter-panel target-filter"
      aria-label="Backupziel-Kandidaten filtern"
      @submit.prevent="applyFilters"
    >
      <label>
        <span>Verbindungs-ID</span>
        <InputText
          v-model="connectionDraft"
          placeholder="Optional UUID"
          autocomplete="off"
        />
      </label>
      <label>
        <span>Cluster-ID</span>
        <InputText
          v-model="clusterDraft"
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
      empty-title="Keine Backupziel-Kandidaten"
      empty-description="Passe die Filter an oder warte auf den nächsten automatischen Collector-Zyklus."
    >
      <Message
        v-if="freshnessUnresolved"
        severity="secondary"
        :closable="false"
        class="target-freshness-note"
      >
        <strong>Freshness unresolved:</strong> Die dargestellten Zeitpunkte sind
        rohe Evidenz und noch keine Aktivierungsfreigabe.
      </Message>

      <div class="target-grid">
        <BackupTargetCandidateCard
          v-for="candidate in store.items"
          :key="candidate.id"
          :candidate="candidate"
        />
      </div>

      <div v-if="store.page.hasMore" class="table-pagination target-pagination">
        <Button
          label="Weitere Kandidaten laden"
          icon="pi pi-angle-down"
          severity="secondary"
          :loading="store.loading"
          @click="store.loadMore()"
        />
      </div>
    </AsyncState>
  </section>
</template>
