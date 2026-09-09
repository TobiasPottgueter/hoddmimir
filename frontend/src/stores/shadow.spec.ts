import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import type {
  ShadowApi,
  ShadowDecision,
  ShadowDecisionDetail,
  ShadowEvaluation,
} from "@/api/shadowApi";
import { useShadowStore } from "./shadow";

const evaluation: ShadowEvaluation = {
  id: "00000000-0000-4000-8000-000000000001",
  cycleToken: "00000000-0000-4000-8000-000000000002",
  fencingToken: 2,
  evaluatorVersion: 1,
  decisionCount: 1,
  gateCount: 2,
  startedAt: "start",
  completedAt: "done",
  persistedAt: "persisted",
};
const decision: ShadowDecision = {
  id: "00000000-0000-4000-8000-000000000003",
  evaluationId: "00000000-0000-4000-8000-000000000001",
  guestId: "00000000-0000-4000-8000-000000000004",
  nodeId: null,
  outcome: "blocked",
  reason: "bytes_written",
  priority: null,
  policyId: "00000000-0000-4000-8000-000000000005",
  policyRevision: 1,
  targetId: "00000000-0000-4000-8000-000000000006",
  targetRevision: 2,
  completedAt: "done",
};
const detail: ShadowDecisionDetail = {
  ...decision,
  gates: [
    {
      position: 1,
      code: "guest_enabled",
      passed: false,
      scope: "guest",
      subjectId: "00000000-0000-4000-8000-000000000004",
      observedAt: null,
      detailCode: "disabled",
    },
  ],
};
const page = <T>(items: T[], nextCursor: string | null = null) => ({
  items,
  page: {
    limit: 20,
    count: items.length,
    hasMore: nextCursor !== null,
    nextCursor,
  },
});

function api(): ShadowApi {
  return {
    evaluations: vi
      .fn()
      .mockResolvedValueOnce(page([evaluation], "eval-next"))
      .mockResolvedValue(page([evaluation])),
    decisions: vi
      .fn()
      .mockResolvedValueOnce(page([decision], "decision-next"))
      .mockResolvedValue(page([decision])),
    decision: vi.fn().mockResolvedValue(detail),
  };
}

describe("shadow store", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("lädt und paginiert Auswertungen und Entscheidungen", async () => {
    const client = api();
    const store = useShadowStore();
    expect(store.evaluationsEmpty).toBe(true);
    expect(store.decisionsEmpty).toBe(true);
    await store.loadEvaluations(client);
    await store.loadMoreEvaluations(client);
    await store.loadDecisions(client);
    await store.loadMoreDecisions(client);
    expect(store.evaluations).toHaveLength(2);
    expect(store.decisions).toHaveLength(2);
    expect(client.evaluations).toHaveBeenLastCalledWith(20, "eval-next");
    expect(client.decisions).toHaveBeenLastCalledWith({
      limit: 20,
      cursor: "decision-next",
    });
  });

  it("lädt erklärbare Detail-Gates", async () => {
    const store = useShadowStore();
    await store.selectDecision("decision", api());
    expect(store.detail).toEqual(detail);
    expect(store.detailLoading).toBe(false);
  });

  it("setzt bei Filterwechsel Cursor und Detail zurück und lädt Seite eins", async () => {
    const client = api();
    const store = useShadowStore();
    store.detail = detail;
    store.decisionPage = page([], "stale").page;
    await store.applyDecisionFilters(
      {
        outcome: "blocked",
        reason: "never_backed_up",
        guestId: decision.guestId,
      },
      client,
    );
    expect(store.detail).toBeNull();
    expect(store.decisionFilters).toEqual({
      outcome: "blocked",
      reason: "never_backed_up",
      guestId: decision.guestId,
    });
    expect(client.decisions).toHaveBeenCalledWith({
      limit: 20,
      outcome: "blocked",
      reason: "never_backed_up",
      guestId: decision.guestId,
    });
  });

  it("behandelt aktive, erschöpfte und fehlgeschlagene Requests geschlossen", async () => {
    const client = api();
    const store = useShadowStore();
    store.evaluationsLoading = true;
    store.decisionsLoading = true;
    expect(store.evaluationsEmpty).toBe(false);
    expect(store.decisionsEmpty).toBe(false);
    await store.loadMoreEvaluations(client);
    await store.loadMoreDecisions(client);
    expect(client.evaluations).not.toHaveBeenCalled();
    expect(client.decisions).not.toHaveBeenCalled();
    store.evaluationsLoading = false;
    store.decisionsLoading = false;
    await store.loadMoreEvaluations(client);
    await store.loadMoreDecisions(client);
    expect(client.evaluations).not.toHaveBeenCalled();
    expect(client.decisions).not.toHaveBeenCalled();

    client.evaluations = vi.fn().mockRejectedValue(new Error("eval failed"));
    client.decisions = vi.fn().mockRejectedValue(new Error("decision failed"));
    client.decision = vi.fn().mockRejectedValue(new Error("detail failed"));
    await store.loadEvaluations(client);
    await store.loadDecisions(client);
    await store.selectDecision("missing", client);
    expect(store.evaluationsError).toBe("eval failed");
    expect(store.decisionsError).toBe("decision failed");
    expect(store.detailError).toBe("detail failed");
  });

  it("verwirft veraltete erfolgreiche Antworten und Fehler", async () => {
    let resolveEval:
      ((value: ReturnType<typeof page<ShadowEvaluation>>) => void) | undefined;
    let rejectDecision: ((reason?: unknown) => void) | undefined;
    let resolveDetail: ((value: ShadowDecisionDetail) => void) | undefined;
    const evalPending = new Promise<ReturnType<typeof page<ShadowEvaluation>>>(
      (resolve) => {
        resolveEval = resolve;
      },
    );
    const decisionPending = new Promise<never>((_resolve, reject) => {
      rejectDecision = reject;
    });
    const detailPending = new Promise<ShadowDecisionDetail>((resolve) => {
      resolveDetail = resolve;
    });
    const client: ShadowApi = {
      evaluations: vi
        .fn()
        .mockReturnValueOnce(evalPending)
        .mockResolvedValueOnce(page([evaluation])),
      decisions: vi
        .fn()
        .mockReturnValueOnce(decisionPending)
        .mockResolvedValueOnce(page([decision])),
      decision: vi
        .fn()
        .mockReturnValueOnce(detailPending)
        .mockResolvedValueOnce(detail),
    };
    const store = useShadowStore();
    const staleEval = store.loadEvaluations(client);
    await store.loadEvaluations(client);
    resolveEval?.(page([]));
    await staleEval;
    const staleDecision = store.loadDecisions(client);
    await store.loadDecisions(client);
    rejectDecision?.(new Error("stale"));
    await staleDecision;
    const staleDetail = store.selectDecision("old", client);
    await store.selectDecision("new", client);
    resolveDetail?.({ ...detail, id: "old" });
    await staleDetail;
    expect(store.evaluations).toEqual([evaluation]);
    expect(store.decisions).toEqual([decision]);
    expect(store.decisionsError).toBeNull();
    expect(store.detail).toEqual(detail);
  });

  it("verwirft auch veraltete Evaluation- und Detailfehler", async () => {
    let rejectEval: ((reason?: unknown) => void) | undefined;
    let rejectDetail: ((reason?: unknown) => void) | undefined;
    const client: ShadowApi = {
      evaluations: vi
        .fn()
        .mockReturnValueOnce(
          new Promise<never>((_r, reject) => {
            rejectEval = reject;
          }),
        )
        .mockResolvedValueOnce(page([evaluation])),
      decisions: vi.fn(),
      decision: vi
        .fn()
        .mockReturnValueOnce(
          new Promise<never>((_r, reject) => {
            rejectDetail = reject;
          }),
        )
        .mockResolvedValueOnce(detail),
    };
    const store = useShadowStore();
    const oldEval = store.loadEvaluations(client);
    await store.loadEvaluations(client);
    rejectEval?.(new Error("stale"));
    await oldEval;
    const oldDetail = store.selectDecision("old", client);
    await store.selectDecision("new", client);
    rejectDetail?.(new Error("stale"));
    await oldDetail;
    expect(store.evaluationsError).toBeNull();
    expect(store.detailError).toBeNull();
  });

  it("verwirft eine veraltete erfolgreiche Entscheidungsantwort", async () => {
    let resolveDecision:
      ((value: ReturnType<typeof page<ShadowDecision>>) => void) | undefined;
    const client: ShadowApi = {
      evaluations: vi.fn(),
      decisions: vi
        .fn()
        .mockReturnValueOnce(
          new Promise<ReturnType<typeof page<ShadowDecision>>>((resolve) => {
            resolveDecision = resolve;
          }),
        )
        .mockResolvedValueOnce(page([decision])),
      decision: vi.fn(),
    };
    const store = useShadowStore();

    const stale = store.loadDecisions(client);
    await store.loadDecisions(client);
    resolveDecision?.(page([]));
    await stale;

    expect(store.decisions).toEqual([decision]);
  });

  it.each([400, 404, 503] as const)(
    "maskiert HTTP-%s-Details",
    async (status) => {
      const client = api();
      client.decision = vi.fn().mockRejectedValue({
        httpStatus: status,
        payload: { error: { message: "SQL secret" } },
      });
      const store = useShadowStore();
      await store.selectDecision("decision", client);
      expect(store.detailError).not.toContain("SQL secret");
    },
  );
});
