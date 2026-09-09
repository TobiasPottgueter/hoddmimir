<script setup lang="ts">
import ReadStatus from "@/components/common/ReadStatus.vue";
import { useAutoRefresh } from "@/composables/useAutoRefresh";
import { computed, watch } from "vue";
import { useRoute } from "vue-router";
import Message from "primevue/message";
import Tag from "primevue/tag";
import {
  backupRunStateLabel,
  backupRunStateSeverity,
  backupStopAttemptStatusLabel,
} from "@/composables/useBackupOperations";
import { formatUtc } from "@/composables/useFormatters";
import { useBackupOperationsStore } from "@/stores/backupOperations";
const route = useRoute();
const store = useBackupOperationsStore();
const id = computed(() => String(route.params.id));
const { refresh, paused } = useAutoRefresh(
  () => store.loadRun(id.value),
  () =>
    store.detail?.id !== id.value ||
    (store.logs.length <= 100 &&
      store.events.length <= 25 &&
      store.requestEvents.length <= 25),
  () => store.detailStatus.loading,
);
watch(id, () => void store.loadRun(id.value));
</script>
<template>
  <section class="data-view run-detail-view" aria-labelledby="run-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Backup-Lauf</span>
        <h2 id="run-title">Laufdetails</h2>
      </div>
    </div>

    <ReadStatus
      :state="store.detailStatus"
      :pause-reason="
        paused
          ? 'Weitere Ereignisse oder Logzeilen sind geöffnet. Aktualisieren lädt wieder den Anfang.'
          : ''
      "
      @refresh="refresh()"
    />
    <template v-if="store.detail && store.detail.id === id">
      <Message
        v-if="
          store.detail.state === 'reconcile_required' ||
          store.detail.state === 'unknown'
        "
        severity="warn"
        :closable="false"
        >Der Startstatus ist nicht eindeutig. Hoddmímir führt keinen
        automatischen vzdump-Retry aus.</Message
      >
      <Message
        v-if="store.detail.stopAttemptStatus === 'dispatch_unknown'"
        severity="warn"
        :closable="false"
      >
        Der Stop-Dispatch ist nach einer Crashgrenze unklar. Hoddmímir sendet
        aus Sicherheitsgründen keinen zweiten PVE-Stop-Aufruf und überwacht den
        Task weiter.
      </Message>
      <div class="shadow-detail-summary">
        <Tag
          :value="backupRunStateLabel(store.detail.state)"
          :severity="backupRunStateSeverity(store.detail.state)"
        /><span>Versuch {{ store.detail.attempt }}</span
        ><span>{{ formatUtc(store.detail.startedAt) }}</span>
      </div>
      <dl class="detail-grid">
        <dt>Gast</dt>
        <dd>
          {{ String(store.detail.guestName ?? "–") }} ({{
            String(store.detail.guestType ?? "Gast").toUpperCase()
          }}
          {{ String(store.detail.vmid ?? "–") }})
        </dd>
        <dt>Node / Ziel</dt>
        <dd>
          {{ String(store.detail.nodeName ?? "–") }} /
          {{ String(store.detail.targetName ?? "–") }}
        </dd>
        <dt>UPID</dt>
        <dd>{{ String(store.detail.upid ?? "Noch nicht vorhanden") }}</dd>
        <dt>Provenienz</dt>
        <dd>{{ String(store.detail.submissionProvenance ?? "–") }}</dd>
        <dt>Exit / Fehler</dt>
        <dd>
          {{
            String(
              store.detail.exitStatus ?? store.detail.statusFailureCode ?? "–",
            )
          }}
        </dd>
        <dt>Recovery</dt>
        <dd>{{ String(store.detail.recoveryOutcome ?? "–") }}</dd>
        <dt>Stop-Versuch</dt>
        <dd>
          {{
            store.detail.stopAttemptStatus
              ? backupStopAttemptStatusLabel(store.detail.stopAttemptStatus)
              : "Nicht angefordert"
          }}
          · Claim {{ formatUtc(store.detail.stopAttemptClaimedAt ?? null) }} ·
          Ergebnis {{ formatUtc(store.detail.stopAttemptResolvedAt ?? null) }}
          <span v-if="store.detail.stopFailureCode">
            · {{ store.detail.stopFailureCode }}</span
          >
        </dd>
      </dl>
      <section>
        <h3>Anforderungsereignisse</h3>
        <ol>
          <li v-for="event in store.requestEvents" :key="event.id">
            <strong>{{ event.type }}</strong> · {{ event.state }} ·
            {{ formatUtc(event.occurredAt) }}
          </li>
        </ol>
        <button
          v-if="store.requestEventPage.hasMore"
          type="button"
          :disabled="store.detailStatus.loading"
          @click="store.loadMoreRequestEvents()"
        >
          Weitere Anforderungsereignisse
        </button>
      </section>
      <section>
        <h3>Ereignisse</h3>
        <ol>
          <li v-for="event in store.events" :key="event.id">
            <strong>{{ event.type }}</strong> · {{ event.state }} ·
            {{ formatUtc(event.occurredAt) }}
          </li>
        </ol>
        <button
          v-if="store.eventPage.hasMore"
          type="button"
          :disabled="store.detailStatus.loading"
          @click="store.loadMoreRunEvents()"
        >
          Weitere Laufereignisse
        </button>
      </section>
      <section>
        <h3>Tasklog</h3>
        <pre
          class="run-log"
        ><code v-for="line in store.logs" :key="line.lineNo">{{ line.content }}
</code></pre>
        <button
          v-if="store.logPage.hasMore"
          type="button"
          :disabled="store.detailStatus.loading"
          @click="store.loadMoreLogs()"
        >
          Weitere Logzeilen
        </button>
      </section>
    </template>
  </section>
</template>
