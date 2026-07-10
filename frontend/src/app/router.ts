import { createRouter, createWebHistory } from "vue-router";

import DashboardView from "@/views/DashboardView.vue";
import SectionPlaceholderView from "@/views/SectionPlaceholderView.vue";

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
      component: SectionPlaceholderView,
      meta: {
        title: "Systeme",
        description: "PVE- und PBS-Verbindungen werden hier verwaltet.",
      },
    },
    {
      path: "/inventory",
      name: "inventory",
      component: SectionPlaceholderView,
      meta: {
        title: "Inventar",
        description:
          "Cluster, Nodes, VMs und Container erscheinen nach dem ersten Scan.",
      },
    },
    {
      path: "/backup-targets",
      name: "backup-targets",
      component: SectionPlaceholderView,
      meta: {
        title: "Backup-Ziele",
        description:
          "Storages, Datastores und Namespaces werden hier zugeordnet.",
      },
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
