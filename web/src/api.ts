export const API_BASE = (import.meta.env.VITE_API_BASE_URL || "").replace(
  /\/$/,
  "",
);
const TOKEN_KEY = "nexus.accessToken";
export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const setToken = (token: string | null) =>
  token
    ? localStorage.setItem(TOKEN_KEY, token)
    : localStorage.removeItem(TOKEN_KEY);

export type StudentDataRevision = { global: number; user: number };
export type StudentDataUpdate = {
  resource: string;
  instanceId?: string;
  revision?: StudentDataRevision;
  revisions?: Record<string, StudentDataRevision>;
};

export function subscribeToStudentDataUpdates(
  onUpdate: (update: StudentDataUpdate) => void,
  onUnauthorized: () => void,
): () => void {
  let stopped = false;
  let controller: AbortController | undefined;
  let retryTimer: number | undefined;
  let retryDelay = 1_000;

  const connect = async () => {
    while (!stopped) {
      const token = getToken();
      if (!token) return;

      const connection = new AbortController();
      controller = connection;
      try {
        const response = await fetch(`${API_BASE}/api/student-data/events`, {
          headers: {
            Accept: "text/event-stream",
            Authorization: `Bearer ${token}`,
          },
          cache: "no-store",
          signal: connection.signal,
        });
        if (response.status === 401 || response.status === 403) {
          onUnauthorized();
          return;
        }
        if (!response.ok || !response.body) {
          throw new Error(`Update stream failed (${response.status}).`);
        }

        retryDelay = 1_000;
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = "";
        try {
          while (!stopped) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            const events = buffer.split(/\r?\n\r?\n/);
            buffer = events.pop() ?? "";
            for (const event of events) {
              const data = event
                .split(/\r?\n/)
                .filter((line) => line.startsWith("data:"))
                .map((line) => line.slice(5).trimStart())
                .join("\n");
              if (!data) continue;
              try {
                const update = JSON.parse(data) as Partial<StudentDataUpdate>;
                if (typeof update.resource === "string") {
                  onUpdate(update as StudentDataUpdate);
                }
              } catch {
                // Ignore malformed events; the next sync restores consistency.
              }
            }
          }
        } finally {
          reader.releaseLock();
        }
        if (!stopped) throw new Error("Update stream disconnected.");
      } catch {
        if (stopped || connection.signal.aborted) return;
        await new Promise<void>((resolve) => {
          retryTimer = window.setTimeout(resolve, retryDelay);
        });
        retryTimer = undefined;
        retryDelay = Math.min(retryDelay * 2, 30_000);
      }
    }
  };

  void connect();
  return () => {
    stopped = true;
    controller?.abort();
    if (retryTimer !== undefined) window.clearTimeout(retryTimer);
  };
}

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
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
  )
    headers.set("Content-Type", "application/json");
  let response: Response;
  try {
    response = await fetch(`${API_BASE}/api${path}`, { ...init, headers });
  } catch {
    throw new ApiError(
      `Unable to reach the API at ${API_BASE || "(same origin)"}. Check VITE_API_BASE_URL and backend availability.`,
      0,
    );
  }
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
  return response.json() as Promise<T>;
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
