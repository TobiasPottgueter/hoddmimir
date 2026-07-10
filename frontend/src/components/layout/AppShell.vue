<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, RouterView, useRoute } from "vue-router";

interface NavigationItem {
  label: string;
  icon: string;
  to: string;
}

const navigationItems: NavigationItem[] = [
  { label: "Übersicht", icon: "pi pi-home", to: "/" },
  { label: "Systeme", icon: "pi pi-server", to: "/systems" },
  { label: "Inventar", icon: "pi pi-sitemap", to: "/inventory" },
  { label: "Backup-Ziele", icon: "pi pi-database", to: "/backup-targets" },
  { label: "Policies", icon: "pi pi-sliders-h", to: "/policies" },
  { label: "Queue", icon: "pi pi-list-check", to: "/queue" },
  { label: "Läufe", icon: "pi pi-history", to: "/runs" },
  { label: "Administration", icon: "pi pi-cog", to: "/administration" },
];

const route = useRoute();
const navigationOpen = ref(false);
const currentTitle = computed(() =>
  typeof route.meta.title === "string" ? route.meta.title : "Übersicht",
);

function closeNavigation(): void {
  navigationOpen.value = false;
}
</script>

<template>
  <div class="app-shell">
    <a class="skip-link" href="#main-content">Zum Inhalt springen</a>

    <aside
      id="primary-navigation"
      class="app-sidebar"
      :class="{ 'app-sidebar--open': navigationOpen }"
      aria-label="Hauptnavigation"
    >
      <RouterLink class="app-brand" to="/" @click="closeNavigation">
        <span class="app-brand__mark" aria-hidden="true">
          <i class="pi pi-shield" />
        </span>
        <span>
          <strong>Hoddmímir</strong>
          <small>Backup Scheduler · Version 2.0</small>
        </span>
      </RouterLink>

      <nav class="app-navigation">
        <RouterLink
          v-for="item in navigationItems"
          :key="item.to"
          :to="item.to"
          class="app-navigation__item"
          active-class="app-navigation__item--active"
          :aria-label="item.label"
          @click="closeNavigation"
        >
          <i :class="item.icon" aria-hidden="true" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>

      <div class="app-sidebar__footer">
        <span class="status-dot" aria-hidden="true" />
        <span>
          <strong>Einrichtung ausstehend</strong>
          <small>Noch keine Systeme verbunden</small>
        </span>
      </div>
    </aside>

    <div class="app-workspace">
      <header class="app-topbar">
        <button
          class="navigation-toggle"
          type="button"
          :aria-expanded="navigationOpen"
          aria-controls="primary-navigation"
          aria-label="Navigation öffnen"
          @click="navigationOpen = !navigationOpen"
        >
          <i class="pi pi-bars" aria-hidden="true" />
        </button>

        <div>
          <span class="app-topbar__eyebrow">Hoddmímir</span>
          <h1>{{ currentTitle }}</h1>
        </div>

        <span class="app-topbar__environment">
          <i class="pi pi-wrench" aria-hidden="true" />
          Initialisierung
        </span>
      </header>

      <main id="main-content" class="app-content" tabindex="-1">
        <RouterView />
      </main>
    </div>

    <button
      v-if="navigationOpen"
      class="navigation-backdrop"
      type="button"
      aria-label="Navigation schließen"
      @click="closeNavigation"
    />
  </div>
</template>
