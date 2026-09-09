export type AuthPermission =
  | "inventory.read"
  | "backup_configuration.manage"
  | "backup_operations.manage"
  | "audit.read"
  | "security.manage";

export interface AuthPrincipal {
  id: string;
  username: string;
  permissions: AuthPermission[];
}

export interface AuthSession {
  user: AuthPrincipal;
  csrfToken: string;
  idleExpiresAt: string;
  absoluteExpiresAt: string;
}

export interface AuthApi {
  login(username: string, password: string): Promise<AuthSession>;
  session(): Promise<AuthSession>;
  logout(csrfToken: string): Promise<void>;
}

async function json<T>(response: Response): Promise<T> {
  if (!response.ok) {
    throw {
      httpStatus: response.status,
      payload: await response.json().catch(() => null),
    };
  }
  return (await response.json()) as T;
}

export function createAuthApi(baseUrl = ""): AuthApi {
  return {
    async login(username, password) {
      return json<AuthSession>(
        await fetch(`${baseUrl}/api/v1/auth/login`, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ username, password }),
        }),
      );
    },
    async session() {
      return json<AuthSession>(
        await fetch(`${baseUrl}/api/v1/auth/session`, {
          credentials: "same-origin",
        }),
      );
    },
    async logout(csrfToken) {
      const response = await fetch(`${baseUrl}/api/v1/auth/logout`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrfToken },
      });
      if (!response.ok) {
        throw {
          httpStatus: response.status,
          payload: await response.json().catch(() => null),
        };
      }
    },
  };
}

export const authApi = createAuthApi();
