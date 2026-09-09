<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
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
const mobileQuery = window.matchMedia("(max-width: 780px)");
const isMobile = ref(mobileQuery.matches);
const sidebar = ref<HTMLElement | null>(null);
const navigationToggle = ref<HTMLButtonElement | null>(null);
const mainContent = ref<HTMLElement | null>(null);
const modalNavigation = computed(() => isMobile.value && navigationOpen.value);
let previousOverflow: string | null = null;
function updateViewport(): void {
  isMobile.value = mobileQuery.matches;
  if (!isMobile.value) navigationOpen.value = false;
}
mobileQuery.addEventListener("change", updateViewport);
onBeforeUnmount(() => {
  mobileQuery.removeEventListener("change", updateViewport);
  if (previousOverflow !== null)
    document.body.style.overflow = previousOverflow;
});
watch(modalNavigation, async (open) => {
  if (open) {
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    await nextTick();
    sidebar.value?.querySelector<HTMLElement>(".navigation-close")?.focus();
  } else if (previousOverflow !== null) {
    document.body.style.overflow = previousOverflow;
    previousOverflow = null;
  }
});
watch(
  () => route.path,
  async () => {
    navigationOpen.value = false;
    await nextTick();
    mainContent.value?.focus({ preventScroll: true });
  },
);
const currentTitle = computed(() =>
  typeof route.meta.title === "string" ? route.meta.title : "Übersicht",
);

async function closeNavigation(restoreFocus = true): Promise<void> {
  navigationOpen.value = false;
  if (restoreFocus && isMobile.value) {
    await nextTick();
    navigationToggle.value?.focus();
  }
}
function trapNavigationFocus(event: KeyboardEvent): void {
  if (!modalNavigation.value) return;
  if (event.key === "Escape") {
    event.preventDefault();
    void closeNavigation();
  } else if (event.key === "Tab") {
    const controls = sidebar.value?.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled]), [tabindex="0"]',
    );
    const first = controls?.[0];
    const last = controls?.[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
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
      ref="sidebar"
      :inert="(isMobile && !navigationOpen) || undefined"
      :role="modalNavigation ? 'dialog' : undefined"
      :aria-modal="modalNavigation || undefined"
      @keydown="trapNavigationFocus"
      class="app-sidebar"
      :class="{ 'app-sidebar--open': navigationOpen }"
      aria-label="Hauptnavigation"
    >
      <button
        v-if="isMobile"
        class="navigation-close"
        type="button"
        @click="closeNavigation()"
      >
        <i class="pi pi-times" aria-hidden="true" /> Navigation schließen
      </button>
      <RouterLink class="app-brand" to="/" @click="closeNavigation(false)">
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
          @click="closeNavigation(false)"
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

    <div class="app-workspace" :inert="modalNavigation || undefined">
      <header class="app-topbar">
        <button
          ref="navigationToggle"
          class="navigation-toggle"
          type="button"
          :aria-expanded="navigationOpen"
          aria-controls="primary-navigation"
          :aria-label="
            navigationOpen ? 'Navigation schließen' : 'Navigation öffnen'
          "
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

      <main
        ref="mainContent"
        id="main-content"
        class="app-content"
        tabindex="-1"
      >
        <RouterView />
      </main>
    </div>

    <button
      v-if="modalNavigation"
      class="navigation-backdrop"
      type="button"
      aria-label="Navigation schließen"
      tabindex="-1"
      @click="closeNavigation()"
    />
  </div>
</template>
