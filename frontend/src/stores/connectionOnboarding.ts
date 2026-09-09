import { computed, ref } from "vue";
import { defineStore } from "pinia";
import {
  connectionOnboardingApi,
  type ConnectionOnboardingApi,
} from "@/api/connectionOnboardingApi";
import type {
  OnboardingActivationRequestWritable as OnboardingActivationRequest,
  OnboardingEndpointMutationRequestWritable as OnboardingEndpointMutationRequest,
  OnboardingGuidance,
  OnboardingIssue,
  OnboardingMutationResult as OnboardingActivationResult,
  OnboardingProduct,
  OnboardingRotationRequestWritable as OnboardingRotationRequest,
  OnboardingVerification,
} from "@/api/generated/types.gen";
import { useAuthStore } from "@/stores/auth";

type OnboardingFailureKind =
  | "invalid"
  | "permission"
  | "conflict"
  | "verification"
  | "unavailable"
  | "unknown";

interface FailurePayload {
  kind: OnboardingFailureKind;
  revision: number | null;
  issues: OnboardingIssue[];
  verification: OnboardingVerification | null;
}

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null
    ? (value as Record<string, unknown>)
    : null;
}

export function onboardingFailure(error: unknown): FailurePayload {
  const outer = record(error);
  const payload = record(outer?.payload);
  const detail = record(payload?.error);
  const status = outer?.httpStatus;
  const code = detail?.code;
  const revision = detail?.currentRevision;
  const issues = Array.isArray(detail?.issues)
    ? (detail.issues as OnboardingIssue[])
    : [];
  const verification = record(detail?.verification)
    ? (detail?.verification as unknown as OnboardingVerification)
    : null;
  return {
    kind:
      status === 400 || code === "invalid_request"
        ? "invalid"
        : status === 403 || code === "permission_denied"
          ? "permission"
          : status === 409 || code === "revision_conflict"
            ? "conflict"
            : status === 422 || code === "onboarding_verification_failed"
              ? "verification"
              : status === 503 || code === "onboarding_unavailable"
                ? "unavailable"
                : "unknown",
    revision: typeof revision === "number" ? revision : null,
    issues,
    verification,
  };
}

export const useConnectionOnboardingStore = defineStore(
  "connection-onboarding",
  () => {
    const guidance = ref<OnboardingGuidance | null>(null);
    const guidanceLoading = ref(false);
    const submitting = ref(false);
    const error = ref<string | null>(null);
    const issues = ref<OnboardingIssue[]>([]);
    const conflictRevision = ref<number | null>(null);
    const result = ref<OnboardingActivationResult | null>(null);
    const verification = ref<OnboardingVerification | null>(null);
    const auth = useAuthStore();
    const canManage = computed(() =>
      auth.hasPermission("backup_configuration.manage"),
    );

    function reset(): void {
      guidance.value = null;
      guidanceLoading.value = false;
      submitting.value = false;
      error.value = null;
      issues.value = [];
      conflictRevision.value = null;
      result.value = null;
      verification.value = null;
    }

    async function loadGuidance(
      product: OnboardingProduct,
      api: ConnectionOnboardingApi = connectionOnboardingApi,
    ): Promise<boolean> {
      error.value = null;
      guidance.value = null;
      if (!canManage.value) {
        error.value = "Für das Onboarding fehlt backup_configuration.manage.";
        return false;
      }
      guidanceLoading.value = true;
      try {
        const response = await api.guidance(product);
        if (
          response.product !== product ||
          response.commands.some((command) => command.containsSecret !== false)
        ) {
          throw new Error("unsafe guidance");
        }
        guidance.value = response;
        return true;
      } catch {
        error.value =
          "Die sicheren Einzelbefehle konnten nicht geladen werden.";
        return false;
      } finally {
        guidanceLoading.value = false;
      }
    }

    async function activate(
      body: OnboardingActivationRequest,
      api: ConnectionOnboardingApi = connectionOnboardingApi,
      key: () => string = () => crypto.randomUUID(),
    ): Promise<boolean> {
      return submit(() =>
        api.activate(body, {
          csrfToken: auth.csrfToken!,
          idempotencyKey: key(),
        }),
      );
    }

    async function rotate(
      connectionId: string,
      body: OnboardingRotationRequest,
      api: ConnectionOnboardingApi = connectionOnboardingApi,
      key: () => string = () => crypto.randomUUID(),
    ): Promise<boolean> {
      return submit(() =>
        api.rotate(connectionId, body, {
          csrfToken: auth.csrfToken!,
          idempotencyKey: key(),
        }),
      );
    }

    async function addEndpoint(
      connectionId: string,
      body: OnboardingEndpointMutationRequest,
      api: ConnectionOnboardingApi = connectionOnboardingApi,
      key: () => string = () => crypto.randomUUID(),
    ): Promise<boolean> {
      return submit(() =>
        api.addEndpoint(connectionId, body, {
          csrfToken: auth.csrfToken!,
          idempotencyKey: key(),
        }),
      );
    }

    async function updateEndpoint(
      connectionId: string,
      endpointId: string,
      body: OnboardingEndpointMutationRequest,
      api: ConnectionOnboardingApi = connectionOnboardingApi,
      key: () => string = () => crypto.randomUUID(),
    ): Promise<boolean> {
      return submit(() =>
        api.updateEndpoint(connectionId, endpointId, body, {
          csrfToken: auth.csrfToken!,
          idempotencyKey: key(),
        }),
      );
    }

    async function submit(
      operation: () => Promise<OnboardingActivationResult>,
    ): Promise<boolean> {
      error.value = null;
      issues.value = [];
      conflictRevision.value = null;
      result.value = null;
      verification.value = null;
      if (!canManage.value || auth.csrfToken === null) {
        error.value = "Für das Onboarding fehlt backup_configuration.manage.";
        return false;
      }
      submitting.value = true;
      try {
        const response = await operation();
        result.value = response;
        verification.value = response.verification;
        return true;
      } catch (caught) {
        const failure = onboardingFailure(caught);
        issues.value = failure.issues;
        conflictRevision.value = failure.revision;
        verification.value = failure.verification;
        error.value = {
          invalid: "Die Onboarding-Eingaben sind ungültig.",
          permission: "Für das Onboarding fehlt die Berechtigung.",
          conflict: "Die Verbindung wurde zwischenzeitlich geändert.",
          verification: "Die sichere Remote-Prüfung ist fehlgeschlagen.",
          unavailable:
            "Das Verbindungs-Onboarding ist derzeit nicht verfügbar.",
          unknown: "Die Verbindung konnte nicht sicher gespeichert werden.",
        }[failure.kind];
        return false;
      } finally {
        submitting.value = false;
      }
    }

    return {
      guidance,
      guidanceLoading,
      submitting,
      error,
      issues,
      conflictRevision,
      result,
      verification,
      canManage,
      reset,
      loadGuidance,
      activate,
      rotate,
      addEndpoint,
      updateEndpoint,
    };
  },
);
