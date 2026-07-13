<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { RouterLink } from "vue-router";
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import MultiSelect from "primevue/multiselect";
import Password from "primevue/password";
import Select from "primevue/select";
import Tag from "primevue/tag";

import AdministrationHealthPanel from "@/components/administration/AdministrationHealthPanel.vue";
import type {
  AdministrationRoleName,
  AdministrationUser,
  AuditEventType,
} from "@/api/generated/types.gen";
import { useAdministration } from "@/composables/useAdministration";
import { useAdministrationStore } from "@/stores/administration";
import { useBackupOperationsStore } from "@/stores/backupOperations";

const store = useAdministrationStore();
const operations = useBackupOperationsStore();
const operationsDashboard = computed(() => operations.dashboard);
const {
  blockerLabel,
  eventLabel,
  formatTimestamp,
  outcomeLabel,
  permissionLabel,
  roleLabel,
} = useAdministration();
const userDialogOpen = ref(false);
const rolesDialogOpen = ref(false);
const disableCandidate = ref<AdministrationUser | null>(null);
const editedUser = ref<AdministrationUser | null>(null);
const userForm = reactive({
  username: "",
  displayName: "",
  password: "",
  roles: [] as AdministrationRoleName[],
});
const roleSelection = ref<AdministrationRoleName[]>([]);
const userFilters = reactive({ search: "", enabled: "all" });
const auditFilters = reactive({ eventType: "all", outcome: "all" });
const enabledOptions = [
  { label: "Alle Status", value: "all" },
  { label: "Aktiv", value: "true" },
  { label: "Deaktiviert", value: "false" },
];
const outcomeOptions = [
  { label: "Alle Ergebnisse", value: "all" },
  { label: "Erfolgreich", value: "succeeded" },
  { label: "Abgelehnt", value: "denied" },
];
const eventTypeValues: AuditEventType[] = [
  "first_admin_created",
  "user_created",
  "user_updated",
  "user_disabled",
  "role_assigned",
  "role_removed",
  "login_succeeded",
  "login_failed",
  "session_created",
  "session_revoked",
  "target_created",
  "target_updated",
  "target_enabled",
  "target_disabled",
  "policy_created",
  "policy_updated",
  "policy_enabled",
  "policy_disabled",
  "selection_upserted",
  "selection_disabled",
  "guest_override_upserted",
  "guest_override_disabled",
  "connection_created",
  "connection_updated",
  "connection_enabled",
  "connection_disabled",
  "endpoint_created",
  "endpoint_updated",
  "endpoint_disabled",
  "credential_rotated",
  "manual_backup_requested",
  "backup_cancel_requested",
];
const eventTypeOptions = [
  { label: "Alle Ereignisse", value: "all" },
  ...eventTypeValues.map((value) => ({ label: eventLabel(value), value })),
];
const userQuery = () => ({
  limit: 50,
  ...(userFilters.search.trim() ? { search: userFilters.search.trim() } : {}),
  ...(userFilters.enabled === "all"
    ? {}
    : { enabled: userFilters.enabled === "true" }),
});
const auditQuery = () => ({
  limit: 50,
  ...(auditFilters.eventType === "all"
    ? {}
    : { eventType: auditFilters.eventType as AuditEventType }),
  ...(auditFilters.outcome === "all"
    ? {}
    : { outcome: auditFilters.outcome as "succeeded" | "denied" }),
});
const roleOptions = computed(() =>
  store.roles.map((role) => ({ label: role.displayName, value: role.name })),
);

function showSection(section: "users" | "roles" | "audit" | "health"): void {
  store.section = section;
  if (section === "users") void store.loadUsers(userQuery());
  if (section === "roles") void store.loadRoles();
  if (section === "audit") void store.loadAudit(auditQuery());
  if (section === "health")
    void Promise.all([operations.loadDashboard(), store.loadHealth()]);
}

function openCreate(): void {
  store.clearMutation();
  editedUser.value = null;
  Object.assign(userForm, {
    username: "",
    displayName: "",
    password: "",
    roles: ["viewer"],
  });
  userDialogOpen.value = true;
}

function openEdit(user: AdministrationUser): void {
  store.clearMutation();
  editedUser.value = user;
  Object.assign(userForm, {
    username: user.username,
    displayName: user.displayName,
    password: "",
    roles: [...user.roles],
  });
  userDialogOpen.value = true;
}

function openRoles(user: AdministrationUser): void {
  store.clearMutation();
  editedUser.value = user;
  roleSelection.value = [...user.roles];
  rolesDialogOpen.value = true;
}

async function saveUser(): Promise<void> {
  const current = editedUser.value;
  const saved =
    current === null
      ? await store.createUser({
          expectedRevision: 0,
          username: userForm.username,
          displayName: userForm.displayName,
          password: userForm.password,
          roles: userForm.roles,
        })
      : await store.updateUser(current.id, {
          expectedRevision: current.revision,
          displayName: userForm.displayName,
          ...(userForm.password === "" ? {} : { password: userForm.password }),
        });
  if (saved) {
    userForm.password = "";
    userDialogOpen.value = false;
    await store.loadUsers();
  }
}

async function saveRoles(): Promise<void> {
  const current = editedUser.value;
  if (current === null) return;
  if (
    await store.replaceRoles(current.id, {
      expectedRevision: current.revision,
      roles: roleSelection.value,
    })
  ) {
    rolesDialogOpen.value = false;
    await store.loadUsers();
  }
}

async function disableUser(): Promise<void> {
  const user = disableCandidate.value;
  if (user === null) return;
  if (await store.disableUser(user.id, { expectedRevision: user.revision })) {
    disableCandidate.value = null;
    await store.loadUsers();
  }
}
async function reloadUserConflict(): Promise<void> {
  const id = editedUser.value?.id;
  if (!id) return;
  await store.loadUsers();
  const fresh = store.users.find((user) => user.id === id);
  if (fresh) {
    editedUser.value = fresh;
    store.clearMutation();
  }
}

onMounted(() => {
  if (store.canManageSecurity) {
    void Promise.all([store.loadUsers(), store.loadRoles()]);
  } else if (store.canReadAudit) {
    store.section = "audit";
    void store.loadAudit();
  }
});
</script>

<template>
  <section
    class="data-view administration-view"
    aria-labelledby="administration-title"
  >
    <div class="view-heading">
      <div>
        <span class="section-kicker">Zugriff und Nachvollziehbarkeit</span>
        <h2 id="administration-title">Administration</h2>
        <p>
          Benutzer, Rollen und unveränderliche Audit-Ereignisse werden über die
          versionierte Server-API verwaltet.
        </p>
      </div>
      <Button
        v-if="store.section === 'users' && store.canManageSecurity"
        label="Benutzer anlegen"
        icon="pi pi-user-plus"
        @click="openCreate"
      />
    </div>

    <div class="administration-layout">
      <nav
        class="administration-navigation"
        aria-label="Administrationsbereiche"
      >
        <button
          type="button"
          :class="{ active: store.section === 'health' }"
          @click="showSection('health')"
        >
          <i class="pi pi-heart-fill" aria-hidden="true" /> Worker &amp; Runtime
        </button>
        <button
          v-if="store.canManageSecurity"
          type="button"
          :class="{ active: store.section === 'users' }"
          @click="showSection('users')"
        >
          <i class="pi pi-users" aria-hidden="true" /> Benutzer
        </button>
        <button
          v-if="store.canManageSecurity"
          type="button"
          :class="{ active: store.section === 'roles' }"
          @click="showSection('roles')"
        >
          <i class="pi pi-key" aria-hidden="true" /> Rollen &amp; Permissions
        </button>
        <button
          v-if="store.canReadAudit"
          type="button"
          :class="{ active: store.section === 'audit' }"
          @click="showSection('audit')"
        >
          <i class="pi pi-book" aria-hidden="true" /> Audit-Log
        </button>
        <RouterLink to="/connections">
          <i class="pi pi-server" aria-hidden="true" /> Verbindungen
        </RouterLink>
      </nav>

      <div class="administration-content">
        <template v-if="store.section === 'health'">
          <AdministrationHealthPanel
            :workers="operationsDashboard?.workers ?? null"
            :health="store.health"
            :loading="store.healthLoading || operations.loading"
            :error="store.healthError ?? operations.error"
          />
        </template>
        <Message
          v-if="!store.canManageSecurity && !store.canReadAudit"
          severity="secondary"
          :closable="false"
        >
          Für die Administration wird <code>security.manage</code> oder
          <code>audit.read</code> benötigt.
        </Message>
        <Message v-if="store.success" severity="success" :closable="false">
          {{ store.success }}
        </Message>
        <Message v-if="store.error" severity="error" :closable="false">
          {{ store.error }}
          <span v-if="store.conflictRevision !== null">
            Aktuelle Serverrevision: {{ store.conflictRevision }}.
            <Button
              label="Stand laden und erneut anwenden"
              size="small"
              text
              @click="reloadUserConflict"
            />
          </span>
        </Message>
        <Message
          v-for="blocker in store.blockers"
          :key="blocker"
          severity="warn"
          :closable="false"
        >
          {{ blockerLabel(blocker) }}
        </Message>

        <template v-if="store.section === 'users' && store.canManageSecurity">
          <h3>Benutzer</h3>
          <form
            class="filter-bar"
            aria-label="Benutzer filtern"
            @submit.prevent="store.loadUsers(userQuery())"
          >
            <InputText
              v-model="userFilters.search"
              aria-label="Benutzersuche"
              placeholder="Benutzer oder Anzeigename"
            />
            <Select
              v-model="userFilters.enabled"
              :options="enabledOptions"
              option-label="label"
              option-value="value"
              aria-label="Benutzerstatus"
            />
            <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
          </form>
          <DataTable
            :value="store.users"
            :loading="store.loading"
            data-key="id"
          >
            <Column field="username" header="Benutzername" />
            <Column field="displayName" header="Anzeigename" />
            <Column header="Rollen">
              <template #body="{ data }">
                {{ data.roles.map(roleLabel).join(", ") }}
              </template>
            </Column>
            <Column header="Status">
              <template #body="{ data }">
                <Tag
                  :value="data.enabled ? 'Aktiv' : 'Deaktiviert'"
                  :severity="data.enabled ? 'success' : 'secondary'"
                />
              </template>
            </Column>
            <Column header="Aktionen">
              <template #body="{ data }">
                <div class="table-actions">
                  <Button
                    label="Bearbeiten"
                    size="small"
                    text
                    @click="openEdit(data)"
                  />
                  <Button
                    label="Rollen"
                    size="small"
                    text
                    @click="openRoles(data)"
                  />
                  <Button
                    v-if="data.enabled"
                    label="Deaktivieren"
                    size="small"
                    severity="danger"
                    text
                    @click="disableCandidate = data"
                  />
                </div>
              </template>
            </Column>
          </DataTable>
          <Message
            v-if="!store.loading && store.users.length === 0"
            severity="secondary"
            :closable="false"
            >Keine Benutzer für diesen Filter.</Message
          >
          <Button
            v-if="store.usersCursor"
            label="Weitere Benutzer"
            severity="secondary"
            icon="pi pi-angle-down"
            :loading="store.loading"
            @click="store.loadMoreUsers(userQuery())"
          />
        </template>

        <template v-if="store.section === 'roles' && store.canManageSecurity">
          <h3>Rollen &amp; Permissions</h3>
          <Message severity="secondary" :closable="false">
            Rollen sind systemdefiniert. Die Zuweisung erfolgt am Benutzer.
          </Message>
          <DataTable
            :value="store.roles"
            :loading="store.loading"
            data-key="id"
          >
            <Column field="displayName" header="Rolle" />
            <Column header="Permissions">
              <template #body="{ data }">
                {{ data.permissions.map(permissionLabel).join(", ") }}
              </template>
            </Column>
          </DataTable>
          <Message
            v-if="!store.loading && store.roles.length === 0"
            severity="secondary"
            :closable="false"
            >Keine Rollen vorhanden.</Message
          >
          <Button
            v-if="store.rolesCursor"
            label="Weitere Rollen"
            severity="secondary"
            icon="pi pi-angle-down"
            :loading="store.loading"
            @click="store.loadMoreRoles()"
          />
        </template>

        <template v-if="store.section === 'audit' && store.canReadAudit">
          <h3>Audit-Log</h3>
          <form
            class="filter-bar"
            aria-label="Audit-Log filtern"
            @submit.prevent="store.loadAudit(auditQuery())"
          >
            <Select
              v-model="auditFilters.eventType"
              :options="eventTypeOptions"
              option-label="label"
              option-value="value"
              aria-label="Audit-Ereignistyp"
            />
            <Select
              v-model="auditFilters.outcome"
              :options="outcomeOptions"
              option-label="label"
              option-value="value"
              aria-label="Audit-Ergebnis"
            />
            <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
          </form>
          <DataTable
            :value="store.auditEvents"
            :loading="store.loading"
            data-key="id"
          >
            <Column header="Zeitpunkt">
              <template #body="{ data }">{{
                formatTimestamp(data.occurredAt)
              }}</template>
            </Column>
            <Column header="Ereignis">
              <template #body="{ data }">{{
                eventLabel(data.eventType)
              }}</template>
            </Column>
            <Column header="Ergebnis">
              <template #body="{ data }">
                <Tag
                  :value="outcomeLabel(data.outcome)"
                  :severity="
                    data.outcome === 'succeeded' ? 'success' : 'danger'
                  "
                />
              </template>
            </Column>
            <Column field="reasonCode" header="Grund" />
            <Column header="Details">
              <template #body="{ data }">
                <Button
                  label="Anzeigen"
                  size="small"
                  text
                  @click="store.selectAuditEvent(data.id)"
                />
              </template>
            </Column>
          </DataTable>
          <Message
            v-if="!store.loading && store.auditEvents.length === 0"
            severity="secondary"
            :closable="false"
            >Keine Audit-Ereignisse für diesen Filter.</Message
          >
          <Button
            v-if="store.auditCursor"
            label="Weitere Audit-Ereignisse"
            severity="secondary"
            icon="pi pi-angle-down"
            :loading="store.loading"
            @click="store.loadMoreAudit(auditQuery())"
          />
        </template>

        <aside class="connection-contract-note">
          <h3>Verbindungen</h3>
          <p>
            PVE- und PBS-Verbindungen sowie ihr read-only Inventar sind unter
            <RouterLink to="/connections">Verbindungen</RouterLink> sicher
            verwaltbar. API-Token-Secrets werden ausschließlich geschrieben und
            niemals zurückgelesen.
          </p>
        </aside>
      </div>
    </div>

    <Dialog
      v-model:visible="userDialogOpen"
      modal
      :header="editedUser === null ? 'Benutzer anlegen' : 'Benutzer bearbeiten'"
      :style="{ width: '34rem' }"
    >
      <form class="administration-form" @submit.prevent="saveUser">
        <label>
          <span>Benutzername</span>
          <InputText
            v-model="userForm.username"
            :disabled="editedUser !== null"
            required
            autocomplete="off"
          />
        </label>
        <label>
          <span>Anzeigename</span>
          <InputText
            v-model="userForm.displayName"
            required
            autocomplete="off"
          />
        </label>
        <label>
          <span>{{
            editedUser === null ? "Passwort" : "Neues Passwort (optional)"
          }}</span>
          <Password
            v-model="userForm.password"
            :required="editedUser === null"
            :minlength="12"
            :feedback="false"
            toggle-mask
            autocomplete="new-password"
          />
        </label>
        <label v-if="editedUser === null">
          <span>Rollen</span>
          <MultiSelect
            v-model="userForm.roles"
            :options="roleOptions"
            option-label="label"
            option-value="value"
            placeholder="Rollen auswählen"
          />
        </label>
        <div class="dialog-actions">
          <Button
            type="button"
            label="Abbrechen"
            severity="secondary"
            text
            @click="userDialogOpen = false"
          />
          <Button type="submit" label="Speichern" :loading="store.pending" />
        </div>
      </form>
    </Dialog>

    <Dialog v-model:visible="rolesDialogOpen" modal header="Rollen zuweisen">
      <div class="administration-form">
        <MultiSelect
          v-model="roleSelection"
          :options="roleOptions"
          option-label="label"
          option-value="value"
          placeholder="Rollen auswählen"
        />
        <div class="dialog-actions">
          <Button
            type="button"
            label="Abbrechen"
            severity="secondary"
            text
            @click="rolesDialogOpen = false"
          />
          <Button
            label="Rollen speichern"
            :loading="store.pending"
            @click="saveRoles"
          />
        </div>
      </div>
    </Dialog>

    <Dialog
      :visible="disableCandidate !== null"
      modal
      header="Benutzer deaktivieren"
      @update:visible="disableCandidate = null"
    >
      <p>
        Der Benutzer <strong>{{ disableCandidate?.username }}</strong> wird
        deaktiviert und seine Sitzungen werden widerrufen.
      </p>
      <div class="dialog-actions">
        <Button
          label="Abbrechen"
          severity="secondary"
          text
          @click="disableCandidate = null"
        />
        <Button
          label="Deaktivieren"
          severity="danger"
          :loading="store.pending"
          @click="disableUser"
        />
      </div>
    </Dialog>

    <Dialog
      :visible="store.selectedAuditEvent !== null"
      modal
      header="Audit-Ereignis"
      @update:visible="store.selectedAuditEvent = null"
    >
      <dl v-if="store.selectedAuditEvent" class="audit-details">
        <dt>Ereignis</dt>
        <dd>{{ eventLabel(store.selectedAuditEvent.eventType) }}</dd>
        <dt>Zeitpunkt</dt>
        <dd>{{ formatTimestamp(store.selectedAuditEvent.occurredAt) }}</dd>
        <dt>Ergebnis</dt>
        <dd>{{ outcomeLabel(store.selectedAuditEvent.outcome) }}</dd>
        <dt>Akteur</dt>
        <dd>{{ store.selectedAuditEvent.actorUserId ?? "System" }}</dd>
        <dt>Subjekt</dt>
        <dd>
          {{ store.selectedAuditEvent.subjectType ?? "–" }} ·
          {{ store.selectedAuditEvent.subjectId ?? "–" }}
        </dd>
        <dt>Korrelation</dt>
        <dd>{{ store.selectedAuditEvent.correlationId }}</dd>
      </dl>
    </Dialog>
  </section>
</template>
