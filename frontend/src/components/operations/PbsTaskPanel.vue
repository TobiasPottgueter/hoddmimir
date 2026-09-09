<script setup lang="ts">
import { onMounted, ref } from "vue";
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Message from "primevue/message";
import InputText from "primevue/inputtext";
import type {
  PbsObservedTask,
  PbsObservedTaskDetail,
} from "@/api/generated/types.gen";
import { loadPbsTask, loadPbsTasks } from "@/api/pbsTasksApi";
import { formatUtc } from "@/composables/useFormatters";
import AsyncState from "@/components/common/AsyncState.vue";

const items = ref<PbsObservedTask[]>([]);
const total = ref(0);
const offset = ref(0);
const connectionId = ref("");
const loading = ref(false);
const error = ref<string | null>(null);
const detail = ref<PbsObservedTaskDetail | null>(null);
const detailLoading = ref(false);
const detailError = ref<string | null>(null);
let pageGeneration = 0;
let detailGeneration = 0;

async function load(nextOffset = 0) {
  const generation = ++pageGeneration;
  ++detailGeneration;
  detail.value = null;
  detailError.value = null;
  detailLoading.value = false;
  loading.value = true;
  error.value = null;
  try {
    const page = await loadPbsTasks(
      nextOffset,
      connectionId.value.trim() || undefined,
    );
    if (generation !== pageGeneration) return;
    items.value = page.items;
    total.value = page.total;
    offset.value = nextOffset;
  } catch {
    if (generation === pageGeneration)
      error.value =
        "PBS-Tasks konnten nicht geladen werden. Prüfe gegebenenfalls die Verbindungs-ID.";
  } finally {
    if (generation === pageGeneration) loading.value = false;
  }
}
async function inspect(id: string) {
  const generation = ++detailGeneration;
  detailLoading.value = true;
  detailError.value = null;
  detail.value = null;
  try {
    const value = await loadPbsTask(id);
    if (generation === detailGeneration) detail.value = value;
  } catch {
    if (generation === detailGeneration)
      detailError.value = "Die gespeicherten Taskdetails sind nicht verfügbar.";
  } finally {
    if (generation === detailGeneration) detailLoading.value = false;
  }
}
onMounted(() => void load());
</script>

<template>
  <section class="data-view" aria-labelledby="pbs-tasks-title">
    <div class="view-heading">
      <div>
        <h3 id="pbs-tasks-title">PBS-Tasks und Logs</h3>
        <p>
          Automatisch erfasste Backup-, Prune-, Verify- und Sync-Tasks. Pro
          Collector-Zyklus werden bis zu acht Tasks mit jeweils bis zu 500
          Logzeilen ergänzt; laufende und danach die neuesten Tasks haben
          Vorrang.
        </p>
      </div>
    </div>
    <form
      class="filter-panel"
      aria-label="PBS-Tasks filtern"
      @submit.prevent="load()"
    >
      <label
        ><span>Verbindungs-ID (optional)</span
        ><InputText v-model="connectionId" placeholder="Alle PBS-Verbindungen"
      /></label>
      <Button
        type="submit"
        label="Filtern"
        severity="secondary"
        :loading="loading"
      />
    </form>
    <AsyncState
      :loading="loading"
      :error="error"
      :empty="items.length === 0"
      empty-title="Keine PBS-Tasks erfasst"
      empty-description="Der Collector ergänzt sichtbare PBS-Tasks automatisch."
    >
      <DataTable
        :value="items"
        data-key="id"
        striped-rows
        responsive-layout="scroll"
      >
        <Column field="workerType" header="Typ" />
        <Column field="workerId" header="Ziel / Job" />
        <Column field="lifecycle" header="Zustand" />
        <Column field="remoteStatus" header="Ergebnis" />
        <Column header="Start"
          ><template #body="{ data }">{{
            formatUtc((data as PbsObservedTask).startedAt)
          }}</template></Column
        >
        <Column header="Details erfasst"
          ><template #body="{ data }">{{
            formatUtc((data as PbsObservedTask).inspectedAt)
          }}</template></Column
        >
        <Column header="Details"
          ><template #body="{ data }"
            ><Button
              label="Details und Log"
              severity="secondary"
              size="small"
              @click="inspect((data as PbsObservedTask).id)" /></template
        ></Column>
      </DataTable>
      <div class="table-pagination">
        <Button
          label="Zurück"
          severity="secondary"
          :disabled="offset === 0"
          @click="load(Math.max(0, offset - 50))"
        />
        <span
          >{{ offset + 1 }}–{{ offset + items.length }} von {{ total }}</span
        >
        <Button
          label="Weiter"
          severity="secondary"
          :disabled="offset + items.length >= total"
          @click="load(offset + 50)"
        />
      </div>
    </AsyncState>
    <AsyncState
      v-if="detailLoading || detailError || detail"
      :loading="detailLoading"
      :error="detailError"
      :empty="false"
    >
      <article v-if="detail" class="detail-panel" aria-label="PBS-Taskdetails">
        <h4>
          {{ detail.workerType }} · {{ detail.workerId ?? "Ohne Job-ID" }}
        </h4>
        <p>
          Letzte Beobachtung: {{ formatUtc(detail.lastSeenAt) }} · Details:
          {{ formatUtc(detail.inspectedAt) }}
        </p>
        <p class="text-break">{{ detail.upid }}</p>
        <Message v-if="!detail.inspection" severity="info" :closable="false"
          >Für diesen Task wurden noch keine Details oder Logs erfasst.</Message
        >
        <template v-else>
          <Message
            v-if="detail.inspection.statusFailure"
            severity="warn"
            :closable="false"
            >Status nicht verfügbar:
            {{ detail.inspection.statusFailure }}</Message
          >
          <p v-else>
            Status: {{ detail.inspection.status }} ·
            {{ detail.inspection.exitStatus ?? "Noch kein Endergebnis" }}
          </p>
          <Message
            v-if="detail.inspection.logFailure"
            severity="warn"
            :closable="false"
            >Log nicht verfügbar: {{ detail.inspection.logFailure }}</Message
          >
          <template v-else>
            <Message
              v-if="detail.inspection.truncated"
              severity="warn"
              :closable="false"
              >Gekürzt: Es werden die ersten 500 Zeilen angezeigt. Das
              vollständige Log ist in PBS verfügbar.</Message
            >
            <p v-if="detail.inspection.lines.length === 0">
              Das erfasste Log enthält keine Zeilen.
            </p>
            <pre
              v-else
              class="pbs-task-log"
              aria-label="Bereinigtes PBS-Tasklog"
            ><template v-for="line in detail.inspection.lines" :key="line.number">{{ line.number }} {{ line.text }}{{ "\n" }}</template></pre>
          </template>
        </template>
      </article>
    </AsyncState>
  </section>
</template>

<style scoped>
.pbs-task-log {
  overflow: auto;
  max-height: 32rem;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.text-break {
  overflow-wrap: anywhere;
}
</style>
