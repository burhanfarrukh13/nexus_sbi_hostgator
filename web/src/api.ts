export const API_BASE = (import.meta.env.VITE_API_BASE_URL || "").replace(
  /\/$/,
  "",
);
const TOKEN_KEY = "nexus.accessToken";
const REFRESH_TOKEN_KEY = "nexus.refreshToken";
const REFRESH_USER_KEY = "nexus.refreshUserId";
const REFRESH_LOCK_KEY = "nexus.refreshLock";
const pendingGets = new Map<string, Promise<unknown>>();
const etags = new Map<string, string>();
const etagBodies = new Map<string, unknown>();
let observedToken: string | null = null;
let refreshFlight: Promise<boolean> | null = null;
let refreshTimer: number | undefined;

export const getToken = () => {
  const token = localStorage.getItem(TOKEN_KEY);
  if (token !== observedToken) {
    observedToken = token;
    pendingGets.clear();
    etags.clear();
    etagBodies.clear();
  }
  if (token) ensureRefreshScheduled(token);
  return token;
};

export const setToken = (token: string | null) => {
  if (token !== observedToken) {
    observedToken = token;
    pendingGets.clear();
    etags.clear();
    etagBodies.clear();
  }
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(REFRESH_TOKEN_KEY);
    localStorage.removeItem(REFRESH_USER_KEY);
  }
  if (refreshTimer !== undefined) window.clearTimeout(refreshTimer);
  refreshTimer = undefined;
  if (token) ensureRefreshScheduled(token);
};

export function setSessionTokens(
  accessToken: string,
  refreshToken: string,
  userId: string,
) {
  localStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
  localStorage.setItem(REFRESH_USER_KEY, userId);
  setToken(accessToken);
}

function decodeExpiry(token: string): number | null {
  try {
    const payload = token.split(".")[1];
    if (!payload) return null;
    const normalized = payload.replace(/-/g, "+").replace(/_/g, "/");
    const claims = JSON.parse(atob(normalized)) as { exp?: unknown };
    return typeof claims.exp === "number" ? claims.exp * 1000 : null;
  } catch {
    return null;
  }
}

export function getTokenSubject(): string | null {
  const token = getToken();
  if (!token) return null;
  try {
    const payload = token.split(".")[1];
    if (!payload) return null;
    const normalized = payload.replace(/-/g, "+").replace(/_/g, "/");
    const claims = JSON.parse(atob(normalized)) as { sub?: unknown };
    return typeof claims.sub === "string" ? claims.sub : null;
  } catch {
    return null;
  }
}

function ensureRefreshScheduled(token: string) {
  if (typeof window === "undefined" || refreshTimer !== undefined) return;
  const expiresAt = decodeExpiry(token);
  if (expiresAt === null) return;
  // Spread refreshes between two and five minutes before expiry.
  const leadMs = 120_000 + Math.random() * 180_000;
  const delay = Math.max(0, expiresAt - Date.now() - leadMs);
  refreshTimer = window.setTimeout(() => {
    refreshTimer = undefined;
    void refreshSession().then((success) => {
      if (!success && getToken()) {
        scheduleRefreshRetry(30_000);
      }
    });
  }, Math.min(delay, 2_147_000_000));
}

function scheduleRefreshRetry(delayMs: number) {
  if (refreshTimer !== undefined) window.clearTimeout(refreshTimer);
  refreshTimer = window.setTimeout(() => {
    refreshTimer = undefined;
    void refreshSession().then((success) => {
      if (!success && getToken()) scheduleRefreshRetry(30_000);
    });
  }, Math.max(1_000, Math.min(delayMs, 300_000)));
}

function retryAfterMs(response: Response): number {
  const value = response.headers.get("Retry-After");
  if (!value) return 30_000;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return Math.max(1_000, seconds * 1000);
  const date = Date.parse(value);
  return Number.isNaN(date) ? 30_000 : Math.max(1_000, date - Date.now());
}

async function withRefreshLock<T>(callback: () => Promise<T>): Promise<T | null> {
  if (navigator.locks?.request) {
    return navigator.locks.request("nexus-access-token-refresh", callback);
  }
  const lockOwner = `${Date.now()}-${Math.random()}`;
  const lockUntil = Date.now() + 15_000;
  const existing = localStorage.getItem(REFRESH_LOCK_KEY);
  if (existing) {
    const [, expires = "0"] = existing.split(":");
    if (Number(expires) > Date.now()) return null;
  }
  localStorage.setItem(REFRESH_LOCK_KEY, `${lockOwner}:${lockUntil}`);
  try {
    return await callback();
  } finally {
    if (localStorage.getItem(REFRESH_LOCK_KEY)?.startsWith(`${lockOwner}:`)) {
      localStorage.removeItem(REFRESH_LOCK_KEY);
    }
  }
}

async function refreshSession(): Promise<boolean> {
  if (refreshFlight) return refreshFlight;
  refreshFlight = (async () => {
    const previousAccessToken = localStorage.getItem(TOKEN_KEY);
    const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY);
    const userId = localStorage.getItem(REFRESH_USER_KEY);
    if (!previousAccessToken || !refreshToken || !userId) return false;

    const result = await withRefreshLock(async () => {
      const currentAccessToken = localStorage.getItem(TOKEN_KEY);
      if (currentAccessToken !== previousAccessToken) return true;
      const send = () =>
        fetch(`${API_BASE}/api/auth/refresh`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ userId, refreshToken }),
        });
      let response: Response;
      try {
        response = await send();
        if (response.status === 429) {
          await new Promise((resolve) =>
            window.setTimeout(resolve, retryAfterMs(response)),
          );
          response = await send();
        }
      } catch {
        return false;
      }
      if (response.status === 401) {
        setToken(null);
        return false;
      }
      if (!response.ok) return false;
      const body = (await response.json()) as {
        accessToken?: string;
        refreshToken?: string;
        user?: { id?: string };
      };
      if (!body.accessToken || !body.refreshToken || !body.user?.id) return false;
      setSessionTokens(body.accessToken, body.refreshToken, body.user.id);
      return true;
    });
    return result ?? localStorage.getItem(TOKEN_KEY) !== previousAccessToken;
  })().finally(() => {
    refreshFlight = null;
  });
  return refreshFlight;
}

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

async function sendApiRequest(
  path: string,
  init: RequestInit,
  headers: Headers,
): Promise<Response> {
  let response: Response;
  try {
    response = await fetch(`${API_BASE}/api${path}`, { ...init, headers });
  } catch {
    throw new ApiError("Can't connect right now. Please check your connection and try again.", 0);
  }
  if (
    response.status === 401 &&
    getToken() &&
    path !== "/auth/login" &&
    path !== "/auth/refresh" &&
    (await refreshSession())
  ) {
    const refreshedHeaders = new Headers(headers);
    const token = getToken();
    if (token) refreshedHeaders.set("Authorization", `Bearer ${token}`);
    try {
      response = await fetch(`${API_BASE}/api${path}`, {
        ...init,
        headers: refreshedHeaders,
      });
    } catch {
      throw new ApiError("Can't connect right now. Please check your connection and try again.", 0);
    }
  }
  return response;
}

export async function api<T = unknown>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  const token = getToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);
  if (
    init.body &&
    !(init.body instanceof FormData) &&
    !headers.has("Content-Type")
  ) {
    headers.set("Content-Type", "application/json");
  }

  const isGet = (init.method ?? "GET").toUpperCase() === "GET";
  const cacheKey = `${token ?? "anonymous"}:${path}`;
  if (isGet && !headers.has("If-None-Match")) {
    const etag = etags.get(cacheKey);
    if (etag) headers.set("If-None-Match", etag);
  }
  const pendingKey = `${cacheKey}:${headers.get("If-None-Match") ?? ""}`;
  if (isGet) {
    const pending = pendingGets.get(pendingKey);
    if (pending) return pending as Promise<T>;
  }

  const request = (async (): Promise<T> => {
    const response = await sendApiRequest(path, init, headers);
    if (isGet && response.headers.has("ETag")) {
      etags.set(cacheKey, response.headers.get("ETag")!);
    }
    if (response.status === 304) return etagBodies.get(cacheKey) as T;
    if (!response.ok) {
      let message = `Request failed (${response.status})`;
      try {
        const body = await response.json();
        const detail = body.message;
        message = Array.isArray(detail) ? detail.join(", ") : detail || message;
      } catch {
        /* non-JSON error */
      }
      if (/\bAI\b|Ollama|\bmodels?\b/i.test(message)) {
        message =
          "This feature is temporarily unavailable. Please try again or contact an administrator.";
      }
      throw new ApiError(message, response.status);
    }
    if (response.status === 204) return undefined as T;
    const body = (await response.json()) as T;
    if (isGet && response.headers.has("ETag")) etagBodies.set(cacheKey, body);
    return body;
  })();

  if (!isGet) return request;
  pendingGets.set(pendingKey, request);
  try {
    return await request;
  } finally {
    if (pendingGets.get(pendingKey) === request) pendingGets.delete(pendingKey);
  }
}

export const json = (method: string, body: unknown): RequestInit => ({
  method,
  body: JSON.stringify(body),
});
export function asArray<T = Record<string, unknown>>(data: unknown): T[] {
  return Array.isArray(data) ? (data as T[]) : [];
}
export function label(value: unknown, fallback = "—"): string {
  return typeof value === "string" || typeof value === "number"
    ? String(value)
    : fallback;
}

window.addEventListener("storage", (event) => {
  if (event.key === TOKEN_KEY && event.newValue !== observedToken) {
    observedToken = event.newValue;
    pendingGets.clear();
    etags.clear();
    etagBodies.clear();
    if (event.newValue) ensureRefreshScheduled(event.newValue);
  }
});
