<script setup lang="ts">
import { onMounted } from "vue";
import Card from "primevue/card";
import Message from "primevue/message";
import Tag from "primevue/tag";

import AsyncState from "@/components/common/AsyncState.vue";
import FreshnessTag from "@/components/inventory/FreshnessTag.vue";
import { formatUtc } from "@/composables/useFormatters";
import { stringAttribute } from "@/composables/useResourceAttributes";
import { useSystemsStore } from "@/stores/systems";

const store = useSystemsStore();

onMounted(() => void store.load());
</script>

<template>
  <section class="data-view" aria-labelledby="systems-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Read-only Inventar</span>
        <h2 id="systems-title">Erkannte Proxmox-Systeme</h2>
        <p>PVE-Cluster und PBS-Server aus dem letzten Collector-Zyklus.</p>
      </div>
      <FreshnessTag :timestamp="store.overview?.latestInventoryAt ?? null" />
    </div>

    <Message severity="info" :closable="false">
      Verbindungen und Zugangsdaten werden in einer späteren Phase
      administriert. Diese Ansicht löst keinen Scan aus.
    </Message>

    <AsyncState
      :loading="store.loading"
      :error="store.error"
      :empty="store.empty"
      empty-title="Noch keine Systeme erkannt"
      empty-description="Der kontinuierliche Collector übernimmt die Inventarisierung automatisch."
    >
      <div class="metric-grid" aria-label="Inventarübersicht">
        <article>
          <span>PVE-Verbindungen</span>
          <strong>{{ store.overview?.counts.pveConnections ?? 0 }}</strong>
        </article>
        <article>
          <span>PBS-Verbindungen</span>
          <strong>{{ store.overview?.counts.pbsConnections ?? 0 }}</strong>
        </article>
        <article>
          <span>Letzte Inventur</span>
          <strong class="metric-grid__date">{{
            formatUtc(store.overview?.latestInventoryAt ?? null)
          }}</strong>
        </article>
      </div>

      <div class="systems-grid">
        <Card v-for="cluster in store.pveClusters" :key="cluster.id">
          <template #title>{{ cluster.displayName }}</template>
          <template #subtitle>{{ cluster.connectionName }}</template>
          <template #content>
            <div class="system-card__content">
              <Tag value="Proxmox VE" severity="info" />
              <span
                >Topologie:
                {{ stringAttribute(cluster, "topology") ?? "unbekannt" }}</span
              >
              <small
                >Zuletzt gesehen: {{ formatUtc(cluster.lastSeenAt) }}</small
              >
            </div>
          </template>
        </Card>

        <Card v-for="server in store.pbsServers" :key="server.id">
          <template #title>{{ server.displayName }}</template>
          <template #subtitle>{{ server.connectionName }}</template>
          <template #content>
            <div class="system-card__content">
              <Tag value="Proxmox Backup Server" severity="contrast" />
              <span
                >Version:
                {{ stringAttribute(server, "version") ?? "unbekannt" }}</span
              >
              <small
                >Statusmessung: {{ formatUtc(server.stateObservedAt) }}</small
              >
            </div>
          </template>
        </Card>
      </div>
    </AsyncState>
  </section>
</template>
