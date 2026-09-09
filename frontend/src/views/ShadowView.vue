<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import Button from "primevue/button";
import Message from "primevue/message";
import PagedPicker from "@/components/common/PagedPicker.vue";
import {
  policyPages,
  targetPages,
  resourcePages,
} from "@/composables/usePickerPages";
import {
  useQueryFilters,
  queryChoice,
  queryUuid,
} from "@/composables/useQueryFilters";
import Select from "primevue/select";
import Tab from "primevue/tab";
import TabList from "primevue/tablist";
import TabPanel from "primevue/tabpanel";
import TabPanels from "primevue/tabpanels";
import Tabs from "primevue/tabs";
import Tag from "primevue/tag";

import AsyncState from "@/components/common/AsyncState.vue";
import { formatUtc } from "@/composables/useFormatters";
import {
  shadowGateLabel,
  shadowDetailLabel,
  shadowOutcomeLabel,
  shadowReasonLabel,
  shadowScopeLabel,
  useShadow,
} from "@/composables/useShadow";

const { store, passedGates, blockedGates } = useShadow();
const tab = ref("decisions");
const filters = reactive({
  outcome: "",
  reason: "",
  policyId: "",
  targetId: "",
  guestId: "",
});
const outcomeOptions = [
  { label: "Alle Outcomes", value: "" },
  { label: "Ausführbar", value: "eligible" },
  { label: "Blockiert", value: "blocked" },
  { label: "Nicht fällig", value: "not_due" },
  { label: "Dedupliziert", value: "deduplicated" },
];
const reasonOptions = [
  { label: "Alle Gründe", value: "" },
  { label: "Manuell", value: "manual" },
  { label: "Noch nie gesichert", value: "never_backed_up" },
  { label: "Maximales Alter", value: "max_age" },
  { label: "Geschriebene Bytes", value: "bytes_written" },
];
function loadFilters() {
  void store.applyDecisionFilters({
    ...(filters.outcome === ""
      ? {}
      : {
          outcome: filters.outcome as
            "eligible" | "blocked" | "not_due" | "deduplicated",
        }),
    ...(filters.reason === ""
      ? {}
      : {
          reason: filters.reason as
            "manual" | "never_backed_up" | "max_age" | "bytes_written",
        }),
    ...(filters.policyId === "" ? {} : { policyId: filters.policyId }),
    ...(filters.targetId === "" ? {} : { targetId: filters.targetId }),
    ...(filters.guestId === "" ? {} : { guestId: filters.guestId }),
  });
}

const guestPages = resourcePages({ kind: "pve_guest" });
const url = useQueryFilters((query) => {
  filters.outcome = queryChoice(
    query,
    "outcome",
    ["", "eligible", "blocked", "not_due", "deduplicated"],
    "",
  );
  filters.reason = queryChoice(
    query,
    "reason",
    ["", "manual", "never_backed_up", "max_age", "bytes_written"],
    "",
  );
  filters.policyId = queryUuid(query, "policyId");
  filters.targetId = queryUuid(query, "targetId");
  filters.guestId = queryUuid(query, "guestId");
}, loadFilters);
function applyFilters() {
  void url.apply({ ...filters });
}

onMounted(() => {
  loadFilters();
  void store.loadEvaluations();
});
</script>

<template>
  <section class="data-view shadow-view" aria-labelledby="shadow-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Read-only Scheduler-Evidenz</span>
        <h2 id="shadow-title">Shadow-Auswertungen</h2>
        <p>
          Erklärbare Vorschau der Scheduler-Entscheidungen. Diese Ansicht
          startet keine Backups.
        </p>
      </div>
    </div>
    <Message severity="info" :closable="false"
      >Shadow-Auswertungen sind Beobachtungen. Erst der Backup-Worker darf
      später freigegebene Anforderungen ausführen.</Message
    >
    <Tabs v-model:value="tab">
      <TabList
        ><Tab value="decisions">Entscheidungen</Tab
        ><Tab value="evaluations">Auswertungsläufe</Tab></TabList
      >
      <TabPanels>
        <TabPanel value="decisions">
          <form
            class="filter-bar shadow-filters"
            aria-label="Shadow-Entscheidungen filtern"
            @submit.prevent="applyFilters"
          >
            <Select
              v-model="filters.outcome"
              :options="outcomeOptions"
              option-label="label"
              option-value="value"
              aria-label="Outcome"
            />
            <Select
              v-model="filters.reason"
              :options="reasonOptions"
              option-label="label"
              option-value="value"
              aria-label="Grund"
            />
            <PagedPicker
              v-model="filters.policyId"
              label="Policy"
              :load-page="policyPages"
            />
            <PagedPicker
              v-model="filters.targetId"
              label="Backup-Ziel"
              :load-page="targetPages"
            />
            <PagedPicker
              v-model="filters.guestId"
              label="Gast: Name oder VMID"
              :load-page="guestPages"
            />
            <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
            <Button
              type="button"
              label="Filter zurücksetzen"
              severity="secondary"
              @click="url.apply({})"
            />
          </form>
          <AsyncState
            :loading="store.decisionsLoading"
            :error="store.decisionsError"
            :empty="store.decisionsEmpty"
            empty-title="Keine Entscheidungen"
            empty-description="Der Collector hat noch keine Shadow-Entscheidungen projiziert."
          >
            <div class="shadow-list">
              <button
                v-for="decision in store.decisions"
                :key="decision.id"
                type="button"
                class="shadow-decision"
                @click="store.selectDecision(decision.id)"
              >
                <span
                  ><strong>Gast {{ decision.guestId }}</strong
                  ><small>{{ formatUtc(decision.completedAt) }}</small></span
                >
                <Tag
                  :value="shadowOutcomeLabel(decision.outcome)"
                  :severity="
                    decision.outcome === 'eligible' ? 'success' : 'warn'
                  "
                />
                <span>Priorität {{ decision.priority ?? "–" }}</span>
              </button>
            </div>
            <Button
              v-if="store.decisionPage.hasMore"
              label="Weitere Entscheidungen"
              severity="secondary"
              icon="pi pi-angle-down"
              :loading="store.decisionsLoading"
              @click="store.loadMoreDecisions()"
            />
          </AsyncState>
        </TabPanel>
        <TabPanel value="evaluations">
          <AsyncState
            :loading="store.evaluationsLoading"
            :error="store.evaluationsError"
            :empty="store.evaluationsEmpty"
            empty-title="Keine Auswertungsläufe"
            empty-description="Noch keine persistierte Shadow-Auswertung vorhanden."
          >
            <div class="shadow-list">
              <article
                v-for="evaluation in store.evaluations"
                :key="evaluation.id"
                class="shadow-evaluation"
              >
                <strong>{{ formatUtc(evaluation.completedAt) }}</strong
                ><span
                  >{{ evaluation.decisionCount }} Entscheidungen ·
                  {{ evaluation.gateCount }} Gates</span
                ><small
                  >Evaluator v{{ evaluation.evaluatorVersion }} · Fencing
                  {{ evaluation.fencingToken }}</small
                >
              </article>
            </div>
            <Button
              v-if="store.evaluationPage.hasMore"
              label="Weitere Läufe"
              severity="secondary"
              icon="pi pi-angle-down"
              :loading="store.evaluationsLoading"
              @click="store.loadMoreEvaluations()"
            />
          </AsyncState>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <section class="shadow-detail" aria-labelledby="shadow-detail-title">
      <h3 id="shadow-detail-title">Entscheidungsdetails</h3>
      <AsyncState
        :loading="store.detailLoading"
        :error="store.detailError"
        :empty="store.detail === null"
        empty-title="Keine Entscheidung ausgewählt"
        empty-description="Wähle eine Entscheidung aus, um ihre Gates zu prüfen."
      >
        <template v-if="store.detail">
          <div class="shadow-detail-summary">
            <Tag
              :value="shadowOutcomeLabel(store.detail.outcome)"
              :severity="
                store.detail.outcome === 'eligible' ? 'success' : 'warn'
              "
            /><span>{{ shadowReasonLabel(store.detail.reason) }}</span
            ><span
              >Policy r{{ store.detail.policyRevision }} · Ziel r{{
                store.detail.targetRevision
              }}</span
            >
          </div>
          <div class="shadow-gate-columns">
            <section>
              <h4>Blockierende Gates</h4>
              <p v-if="blockedGates.length === 0">Keine blockierenden Gates.</p>
              <article
                v-for="gate in blockedGates"
                :key="gate.position"
                class="shadow-gate shadow-gate--blocked"
              >
                <strong
                  >#{{ gate.position }} ·
                  {{ shadowGateLabel(gate.code) }}</strong
                ><span
                  >{{ shadowScopeLabel(gate.scope) }} ·
                  {{ shadowDetailLabel(gate.detailCode) }}</span
                ><small>{{
                  gate.observedAt
                    ? formatUtc(gate.observedAt)
                    : "Keine Beobachtungszeit"
                }}</small>
              </article>
            </section>
            <section>
              <h4>Bestandene Gates</h4>
              <article
                v-for="gate in passedGates"
                :key="gate.position"
                class="shadow-gate"
              >
                <strong
                  >#{{ gate.position }} ·
                  {{ shadowGateLabel(gate.code) }}</strong
                ><span>{{ shadowScopeLabel(gate.scope) }}</span>
              </article>
            </section>
          </div>
        </template>
      </AsyncState>
    </section>
  </section>
</template>
