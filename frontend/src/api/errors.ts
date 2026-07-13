export interface ApiHttpError {
  httpStatus: number;
  payload: unknown;
}

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null
    ? (value as Record<string, unknown>)
    : null;
}

export function withHttpStatus(
  error: unknown,
  response: Response | undefined,
): unknown {
  if (response?.status === 401 && typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent("hoddmimir:unauthorized"));
  }
  return response === undefined
    ? error
    : ({ httpStatus: response.status, payload: error } satisfies ApiHttpError);
}

export function apiErrorMessage(error: unknown): string {
  if (error instanceof TypeError) {
    return "Die WebApp-API ist nicht erreichbar.";
  }

  const errorRecord = record(error);
  const status = errorRecord?.httpStatus;
  if (status === 401) {
    return "Die Anmeldung ist abgelaufen oder erforderlich.";
  }
  if (status === 403) {
    return "Für diese Ansicht fehlt die erforderliche Berechtigung.";
  }

  const nestedError = record(errorRecord?.error);
  const code = nestedError?.code;
  if (code === "unauthorized") {
    return "Die Anmeldung ist abgelaufen oder erforderlich.";
  }
  if (code === "forbidden" || code === "permission_denied") {
    return "Für diese Ansicht fehlt die erforderliche Berechtigung.";
  }

  if (error instanceof Error && error.message.trim() !== "") {
    if (/\b403\b|forbidden|permission/i.test(error.message)) {
      return "Für diese Ansicht fehlt die erforderliche Berechtigung.";
    }
    return error.message;
  }

  return "Die Daten konnten nicht geladen werden.";
}
