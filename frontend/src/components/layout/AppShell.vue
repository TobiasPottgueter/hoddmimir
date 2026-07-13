<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";
import Button from "primevue/button";
import { useAuthStore } from "@/stores/auth";

interface NavigationItem {
  label: string;
  icon: string;
  to: string;
}

const allNavigationItems: NavigationItem[] = [
  { label: "Übersicht", icon: "pi pi-home", to: "/" },
  { label: "Systeme", icon: "pi pi-server", to: "/systems" },
  { label: "Verbindungen", icon: "pi pi-link", to: "/connections" },
  { label: "Inventar", icon: "pi pi-sitemap", to: "/inventory" },
  { label: "Collector-Betrieb", icon: "pi pi-wave-pulse", to: "/operations" },
  { label: "Backup-Ziele", icon: "pi pi-database", to: "/backup-targets" },
  { label: "Policies", icon: "pi pi-sliders-h", to: "/policies" },
  { label: "Shadow-Auswertungen", icon: "pi pi-directions-alt", to: "/shadow" },
  { label: "Queue", icon: "pi pi-list-check", to: "/queue" },
  { label: "Läufe", icon: "pi pi-history", to: "/runs" },
  { label: "Administration", icon: "pi pi-cog", to: "/administration" },
];

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const navigationItems = computed(() =>
  allNavigationItems.filter(
    (item) =>
      (item.to !== "/administration" && item.to !== "/connections") ||
      (item.to === "/administration" &&
        (auth.hasPermission("security.manage") ||
          auth.hasPermission("audit.read"))) ||
      (item.to === "/connections" &&
        auth.hasPermission("backup_configuration.manage")),
  ),
);
const navigationOpen = ref(false);
const currentTitle = computed(() =>
  typeof route.meta.title === "string" ? route.meta.title : "Übersicht",
);

function closeNavigation(): void {
  navigationOpen.value = false;
}

async function logout(): Promise<void> {
  await auth.logout();
  await router.replace({ name: "login" });
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
          <strong>Collector-Inventar</strong>
          <small>Automatische Zyklen · Read-only</small>
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

        <div class="app-topbar__principal">
          <span
            ><i class="pi pi-user" aria-hidden="true" />{{
              auth.principal?.username
            }}</span
          >
          <Button
            label="Abmelden"
            icon="pi pi-sign-out"
            severity="secondary"
            text
            size="small"
            @click="logout"
          />
        </div>
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
