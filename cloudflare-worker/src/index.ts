export interface Env {
  LARAVEL_API_URL: string;
  OLLAMA_API_URL: string;
  OLLAMA_API_KEY: string;
  OLLAMA_MODEL?: string;
}

const CORS_HEADERS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization",
  "Access-Control-Max-Age": "86400",
};

export default {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: CORS_HEADERS });
    }

    const url = new URL(request.url);

    // Health check
    if (url.pathname === "/health" || url.pathname === "/") {
      return jsonResponse({ status: "ok", service: "nexus-ai-worker" });
    }

    if (url.pathname === "/chat" && request.method === "POST") {
      return handleChat(request, env, ctx);
    }

    return jsonResponse({ error: "Not Found" }, 404);
  },
};

async function handleChat(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
  const authHeader = request.headers.get("Authorization");
  if (!authHeader || !authHeader.startsWith("Bearer ")) {
    return jsonResponse({ message: "Authentication required." }, 401);
  }

  let body: {
    subjectId?: string;
    question?: string;
    sessionId?: string;
    studyAidMode?: string;
  };
  try {
    body = await request.json();
  } catch {
    return jsonResponse({ message: "Invalid JSON body." }, 400);
  }

  const { subjectId, question, sessionId, studyAidMode } = body;
  if (!subjectId || !question || !question.trim()) {
    return jsonResponse({ message: "subjectId and question are required." }, 422);
  }

  const cleanQuestion = question.trim();
  const normalizedKey = `${subjectId}:${cleanQuestion.toLowerCase()}:${studyAidMode || "standard"}`;

  // 1. Edge Exact Caching (Serves identical questions in 0ms without hitting HostGator or Ollama)
  const cacheKeyUrl = new URL(`https://cache.nexus.internal/${await sha256(normalizedKey)}`);
  const cache = caches.default;
  const cachedResponse = await cache.match(cacheKeyUrl.toString());
  if (cachedResponse && !studyAidMode) {
    const cachedData = await cachedResponse.json();
    return jsonResponse(
      {
        ...cachedData,
        sessionId: sessionId || cachedData.sessionId || crypto.randomUUID(),
        cached: true,
      },
      200,
      { "X-Nexus-Cache": "HIT" }
    );
  }

  // 2. Fetch Context from Laravel API (Only takes ~50ms of HostGator PHP time)
  const laravelApi = env.LARAVEL_API_URL.replace(/\/$/, "");
  let contextRes: Response;
  try {
    contextRes = await fetch(`${laravelApi}/api/ai-notebook/context`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: authHeader,
      },
      body: JSON.stringify({
        subjectId,
        question: cleanQuestion,
        sessionId,
        studyAidMode,
      }),
    });
  } catch (err) {
    return jsonResponse({ message: "Could not connect to the backend server." }, 502);
  }

  if (!contextRes.ok) {
    const errBody = await contextRes.text();
    let errJson: { message?: string } = {};
    try {
      errJson = JSON.parse(errBody);
    } catch {}
    return jsonResponse(
      { message: errJson.message || `Backend returned ${contextRes.status}` },
      contextRes.status
    );
  }

  const contextData = (await contextRes.json()) as {
    instructions: string;
    sourceContext: string;
    history: Array<{ role: string; content: string }>;
    sessionId: string;
    citations: Array<{ sourceId: string; title: string; chunk: number }>;
    model?: string;
  };

  const finalSessionId = sessionId || contextData.sessionId || crypto.randomUUID();

  // 3. Call Ollama Cloud directly from Cloudflare Edge (ZERO PHP Entry Processes held on HostGator!)
  const model = env.OLLAMA_MODEL || contextData.model || "gemma4:31b";
  const messages = [
    {
      role: "system",
      content: `${contextData.instructions}\n\nSOURCE EXCERPTS:\n${contextData.sourceContext}`,
    },
    ...(contextData.history || []),
    { role: "user", content: cleanQuestion },
  ];

  let ollamaRes: Response;
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 60_000); // 60s edge timeout

    ollamaRes = await fetch(env.OLLAMA_API_URL || "https://ollama.com/v1/chat/completions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${env.OLLAMA_API_KEY}`,
      },
      body: JSON.stringify({
        model,
        messages,
        temperature: 0.2,
        ...(studyAidMode === "mind_map" ? { response_format: { type: "json_object" } } : {}),
      }),
      signal: controller.signal,
    });
    clearTimeout(timeoutId);
  } catch (err: any) {
    if (err?.name === "AbortError") {
      return jsonResponse({ message: "Ollama Cloud request timed out. Please try again." }, 504);
    }
    return jsonResponse({ message: "Ollama Cloud provider is currently unreachable." }, 502);
  }

  if (!ollamaRes.ok) {
    return jsonResponse({ message: "Ollama Cloud rejected the generation request." }, 502);
  }

  const ollamaData = (await ollamaRes.json()) as {
    choices?: Array<{ message?: { content?: string } }>;
  };
  let answerText = ollamaData.choices?.[0]?.message?.content?.trim() || "";
  if (!answerText) {
    return jsonResponse({ message: "Ollama Cloud returned an empty response." }, 502);
  }

  let studyAid: any = null;
  if (studyAidMode === "mind_map") {
    studyAid = parseMindMap(answerText);
    answerText = `Mind map: ${studyAid.title}`;
  } else if (studyAidMode === "summary") {
    studyAid = { type: "summary" };
  }

  const responsePayload = {
    sessionId: finalSessionId,
    answer: answerText,
    response: answerText,
    message: answerText,
    content: answerText,
    citations: contextData.citations || [],
    studyAid,
  };

  // 4. Cache Response at Edge (12 hours) for instant hits
  if (!studyAidMode) {
    const cacheResponseObj = new Response(JSON.stringify(responsePayload), {
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "public, max-age=43200", // 12 hours
      },
    });
    ctx.waitUntil(cache.put(cacheKeyUrl.toString(), cacheResponseObj));
  }

  // 5. Asynchronously record the message in Laravel MySQL in background (ctx.waitUntil)
  ctx.waitUntil(
    fetch(`${laravelApi}/api/ai-notebook/record-message`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: authHeader,
      },
      body: JSON.stringify({
        subjectId,
        sessionId: finalSessionId,
        userQuestion: cleanQuestion,
        answer: answerText,
        citations: contextData.citations || [],
        studyAid,
      }),
    }).catch(() => {})
  );

  return jsonResponse(responsePayload);
}

function parseMindMap(raw: string): { title: string; branches: any[]; type: string } {
  try {
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === "object") {
      return {
        type: "mind_map",
        title: String(parsed.title || "Study Mind Map"),
        branches: Array.isArray(parsed.branches) ? parsed.branches : [],
      };
    }
  } catch {}
  return {
    type: "mind_map",
    title: "Study Mind Map",
    branches: [],
  };
}

async function sha256(str: string): Promise<string> {
  const buf = new TextEncoder().encode(str);
  const hash = await crypto.subtle.digest("SHA-256", buf);
  return Array.from(new Uint8Array(hash))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

function jsonResponse(data: unknown, status = 200, extraHeaders: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      "Content-Type": "application/json",
      ...CORS_HEADERS,
      ...extraHeaders,
    },
  });
}
