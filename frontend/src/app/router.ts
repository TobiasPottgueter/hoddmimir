import { createRouter, createWebHistory } from "vue-router";

import DashboardView from "@/views/DashboardView.vue";

const InventoryView = () => import("@/views/InventoryView.vue");
const BackupTargetsView = () => import("@/views/BackupTargetsView.vue");
const OperationsView = () => import("@/views/OperationsView.vue");
const SectionPlaceholderView = () =>
  import("@/views/SectionPlaceholderView.vue");
const SystemsView = () => import("@/views/SystemsView.vue");

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: "/",
      name: "dashboard",
      component: DashboardView,
      meta: { title: "Übersicht" },
    },
    {
      path: "/systems",
      name: "systems",
      component: SystemsView,
      meta: { title: "Systeme" },
    },
    {
      path: "/inventory",
      name: "inventory",
      component: InventoryView,
      meta: { title: "Inventar" },
    },
    {
      path: "/operations",
      name: "operations",
      component: OperationsView,
      meta: { title: "Collector-Betrieb" },
    },
    {
      path: "/backup-targets",
      name: "backup-targets",
      component: BackupTargetsView,
      meta: { title: "Backup-Ziele" },
    },
    {
      path: "/policies",
      name: "policies",
      component: SectionPlaceholderView,
      meta: {
        title: "Policies",
        description:
          "Auswahl, Zeitpläne und Priorisierungsregeln werden hier konfiguriert.",
      },
    },
    {
      path: "/queue",
      name: "queue",
      component: SectionPlaceholderView,
      meta: {
        title: "Queue",
        description:
          "Geplante und laufende Backup-Anforderungen werden hier sichtbar.",
      },
    },
    {
      path: "/runs",
      name: "runs",
      component: SectionPlaceholderView,
      meta: {
        title: "Läufe",
        description:
          "Backup-Historie, Status und Tasklogs werden hier angezeigt.",
      },
    },
    {
      path: "/administration",
      name: "administration",
      component: SectionPlaceholderView,
      meta: {
        title: "Administration",
        description:
          "Benutzer, Rollen, Worker-Status und Audit-Log werden hier verwaltet.",
      },
    },
  ],
});

router.afterEach((route) => {
  const pageTitle =
    typeof route.meta.title === "string" ? route.meta.title : undefined;
  document.title = pageTitle ? `${pageTitle} · Hoddmímir` : "Hoddmímir";
});

export default router;
