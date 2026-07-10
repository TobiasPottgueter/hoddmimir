<script setup lang="ts">
import Message from "primevue/message";

import SetupStatusCard from "@/components/dashboard/SetupStatusCard.vue";

const setupSteps = [
  {
    title: "Proxmox-Systeme",
    description:
      "PVE- und PBS-Endpunkte mit getrennten API-Zugängen verbinden.",
    icon: "pi pi-server",
    actionLabel: "Systeme öffnen",
    actionTo: "/systems",
  },
  {
    title: "Backup-Ziele",
    description: "Verfügbare Storages, Datastores und Namespaces zuordnen.",
    icon: "pi pi-database",
    actionLabel: "Ziele öffnen",
    actionTo: "/backup-targets",
  },
  {
    title: "Backup-Policies",
    description:
      "Gäste auswählen und Regeln für Planung und Priorität festlegen.",
    icon: "pi pi-sliders-h",
    actionLabel: "Policies öffnen",
    actionTo: "/policies",
  },
];
</script>

<template>
  <section class="dashboard">
    <div class="dashboard-hero">
      <div>
        <span class="section-kicker">Sauberer Neustart</span>
        <h2>Willkommen bei Hoddmímir</h2>
        <p>
          Die Anwendung ist bereit für die neue Konfiguration. Verbinde zuerst
          deine Proxmox-Systeme; Inventar- und Backupdaten werden anschließend
          über die Worker aufgebaut.
        </p>
      </div>
      <span class="dashboard-hero__illustration" aria-hidden="true">
        <i class="pi pi-cloud-upload" />
      </span>
    </div>

    <Message severity="info" :closable="false">
      Es sind noch keine produktiven Daten vorhanden. Version 2.0 übernimmt
      bewusst keine Konfiguration oder Historie aus dem Altsystem.
    </Message>

    <div class="section-heading">
      <div>
        <span class="section-kicker">Erste Schritte</span>
        <h2>Grundkonfiguration</h2>
      </div>
      <span>0 von 3 Schritten abgeschlossen</span>
    </div>

    <div class="setup-grid">
      <SetupStatusCard
        v-for="step in setupSteps"
        :key="step.actionTo"
        v-bind="step"
      />
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
              Stellt Konfiguration, Queue, Historie und Administration bereit.
            </p>
          </div>
        </article>
      </div>
    </section>
  </section>
</template>
