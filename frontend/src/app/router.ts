import { createRouter, createWebHistory } from "vue-router";

import DashboardView from "@/views/DashboardView.vue";
import { useAuthStore } from "@/stores/auth";
import { pinia } from "@/app/pinia";

const InventoryView = () => import("@/views/InventoryView.vue");
const BackupTargetsView = () => import("@/views/BackupTargetsView.vue");
const OperationsView = () => import("@/views/OperationsView.vue");
const PoliciesView = () => import("@/views/PoliciesView.vue");
const SystemsView = () => import("@/views/SystemsView.vue");
const LoginView = () => import("@/views/LoginView.vue");
const ShadowView = () => import("@/views/ShadowView.vue");
const AdministrationView = () => import("@/views/AdministrationView.vue");
const ConnectionsView = () => import("@/views/ConnectionsView.vue");
const QueueView = () => import("@/views/QueueView.vue");
const RunsView = () => import("@/views/RunsView.vue");
const RunDetailView = () => import("@/views/RunDetailView.vue");

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition;
    return to.path !== from.path ? { top: 0 } : false;
  },
  routes: [
    {
      path: "/login",
      name: "login",
      component: LoginView,
      meta: { title: "Anmelden", public: true },
    },
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
      component: PoliciesView,
      meta: { title: "Policies" },
    },
    {
      path: "/shadow",
      name: "shadow",
      component: ShadowView,
      meta: { title: "Shadow-Auswertungen" },
    },
    {
      path: "/queue",
      name: "queue",
      component: QueueView,
      meta: { title: "Queue" },
    },
    {
      path: "/runs",
      name: "runs",
      component: RunsView,
      meta: { title: "Läufe" },
    },
    {
      path: "/runs/:id",
      name: "run-detail",
      component: RunDetailView,
      meta: { title: "Laufdetails" },
    },
    {
      path: "/connections",
      name: "connections",
      component: ConnectionsView,
      meta: { title: "Verbindungen" },
    },
    {
      path: "/administration",
      name: "administration",
      component: AdministrationView,
      meta: {
        title: "Administration",
      },
    },
  ],
});

router.beforeEach(async (to) => {
  const auth = useAuthStore(pinia);
  await auth.restore();
  if (to.meta.public === true) {
    return auth.authenticated ? { name: "dashboard" } : true;
  }
  return auth.authenticated
    ? true
    : { name: "login", query: { redirect: to.fullPath } };
});

router.afterEach((route) => {
  const pageTitle =
    typeof route.meta.title === "string" ? route.meta.title : undefined;
  document.title = pageTitle ? `${pageTitle} · Hoddmímir` : "Hoddmímir";
});

export default router;
