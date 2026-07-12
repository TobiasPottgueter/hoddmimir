<script setup lang="ts">
import Message from "primevue/message";
import { RouterLink } from "vue-router";

const overviewLinks = [
  {
    title: "Erkannte Systeme",
    description:
      "PVE-Cluster und PBS-Server aus dem aktuellen Collector-Inventar einsehen.",
    icon: "pi pi-server",
    actionLabel: "Systeme öffnen",
    actionTo: "/systems",
  },
  {
    title: "Inventar",
    description:
      "Nodes, Gäste, Storages, Datastores und PBS-Inhalte read-only auswerten.",
    icon: "pi pi-sitemap",
    actionLabel: "Inventar öffnen",
    actionTo: "/inventory",
  },
  {
    title: "Collector-Betrieb",
    description:
      "Heartbeat, Zeitraster, Inventurläufe und Scope-Ergebnisse kontrollieren.",
    icon: "pi pi-wave-pulse",
    actionLabel: "Betrieb öffnen",
    actionTo: "/operations",
  },
];
</script>

<template>
  <section class="dashboard">
    <div class="dashboard-hero">
      <div>
        <span class="section-kicker">Betriebsübersicht</span>
        <h2>Willkommen bei Hoddmímir</h2>
        <p>
          Die WebApp zeigt das vom Collector erfasste Inventar und dessen
          Betriebszustand. Die Inventarisierung läuft automatisch ohne manuellen
          Scan.
        </p>
      </div>
      <span class="dashboard-hero__illustration" aria-hidden="true">
        <i class="pi pi-cloud-upload" />
      </span>
    </div>

    <Message severity="info" :closable="false">
      Version 2.0 arbeitet mit einer eigenständigen Konfiguration und Historie.
      Daten aus dem Altsystem werden bewusst nicht übernommen.
    </Message>

    <div class="section-heading">
      <div>
        <span class="section-kicker">Read-only Einblick</span>
        <h2>Inventar und Betrieb</h2>
      </div>
    </div>

    <div class="setup-grid">
      <article
        v-for="link in overviewLinks"
        :key="link.actionTo"
        class="setup-card"
      >
        <div class="setup-card__header">
          <span class="setup-card__icon" aria-hidden="true">
            <i :class="link.icon" />
          </span>
        </div>
        <div>
          <h3>{{ link.title }}</h3>
          <p>{{ link.description }}</p>
        </div>
        <RouterLink class="setup-card__action" :to="link.actionTo">
          {{ link.actionLabel }}
          <i class="pi pi-arrow-right" aria-hidden="true" />
        </RouterLink>
      </article>
    </div>

    <section class="system-overview" aria-labelledby="architecture-title">
      <div class="section-heading">
        <div>
          <span class="section-kicker">Architektur</span>
          <h2 id="architecture-title">Drei getrennte Komponenten</h2>
        </div>
      </div>

      <div class="component-grid">
        <article>
          <i class="pi pi-search" aria-hidden="true" />
          <div>
            <h3>Collector Worker</h3>
            <p>
              Liest Inventar und Laufzeitdaten kontinuierlich und standardmäßig
              alle 120 Sekunden aus PVE und PBS.
            </p>
          </div>
        </article>
        <article>
          <i class="pi pi-play" aria-hidden="true" />
          <div>
            <h3>Backup Worker</h3>
            <p>Startet und überwacht freigegebene Backup-Anforderungen.</p>
          </div>
        </article>
        <article>
          <i class="pi pi-desktop" aria-hidden="true" />
          <div>
            <h3>WebApp</h3>
            <p>
              Stellt Inventar und Collector-Betrieb dar; weitere
              Verwaltungsbereiche werden schrittweise ergänzt.
            </p>
          </div>
        </article>
      </div>
    </section>
  </section>
</template>
