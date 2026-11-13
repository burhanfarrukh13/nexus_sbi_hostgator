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
  // Fetch current authorization, history, and source relevance before every answer.
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
    grounded?: boolean;
    refusal?: string;
  };

  const finalSessionId = contextData.sessionId || sessionId || crypto.randomUUID();
  if (contextData.grounded !== true) {
    const refusal = contextData.refusal ||
      "I couldn’t find an answer to that in this subject’s uploaded sources, so I can’t answer it from the Notebook.";
    const responsePayload = {
      sessionId: finalSessionId,
      answer: refusal,
      response: refusal,
      message: refusal,
      content: refusal,
      citations: [],
      studyAid: null,
    };
    recordMessage(ctx, laravelApi, authHeader, {
      subjectId,
      sessionId: finalSessionId,
      userQuestion: cleanQuestion,
      answer: refusal,
      citations: [],
      studyAid: null,
    });

    return jsonResponse(responsePayload);
  }

  // Call the configured chat provider directly from Cloudflare Edge.
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

  recordMessage(ctx, laravelApi, authHeader, {
    subjectId,
    sessionId: finalSessionId,
    userQuestion: cleanQuestion,
    answer: answerText,
    citations: contextData.citations || [],
    studyAid,
  });

  return jsonResponse(responsePayload);
}

function recordMessage(
  ctx: ExecutionContext,
  laravelApi: string,
  authHeader: string,
  message: {
    subjectId: string;
    sessionId: string;
    userQuestion: string;
    answer: string;
    citations: Array<{ sourceId: string; title: string; chunk: number }>;
    studyAid: unknown;
  }
): void {
  ctx.waitUntil(
    fetch(`${laravelApi}/api/ai-notebook/record-message`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: authHeader,
      },
      body: JSON.stringify(message),
    }).catch(() => {})
  );
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
