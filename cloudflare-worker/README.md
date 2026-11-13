# Nexus SBI — Cloudflare AI Edge Worker (Zero Server Load)

This Cloudflare Worker eliminates AI Notebook concurrency starvation and protects your HostGator Business account.

### How it Works
1. **Source and permission check**: Every request reaches Laravel to validate access and retrieve current subject excerpts. Stale cached answers cannot bypass this check.
2. **Grounding gate**: If no excerpt is sufficiently relevant, the Worker returns Laravel's fixed refusal and does not call the chat provider.
3. **Direct AI Completion**: Only questions with relevant source excerpts are sent to the configured chat provider from Cloudflare's edge.
4. **Background Sync**: The completed conversation is asynchronously logged back to your Laravel database without making the student wait.

---

## Deployment Option A: Via Cloudflare Web Dashboard (No CLI or Node.js Required)

1. Log into your [Cloudflare Dashboard](https://dash.cloudflare.com/).
2. In the left navigation, click **Workers & Pages** > **Create application** > **Create Worker**.
3. Name your worker (e.g., `nexus-ai-worker`) and click **Deploy**.
4. Click **Edit code** (Quick Edit).
5. Delete the starter code, and copy & paste the entire contents of [`src/index.ts`](src/index.ts).
6. Click **Save and Deploy**.
7. Go to **Settings** > **Variables and Secrets**:
   * Add Variable: `LARAVEL_API_URL` = `https://api.yourdomain.com` (your HostGator API subdomain).
   * Add Variable: `OLLAMA_API_URL` = `https://openrouter.ai/api/v1/chat/completions`
   * Add Variable: `OLLAMA_MODEL` = `qwen/qwen3-vl-30b-a3b-instruct`
   * Add Secret: `OLLAMA_API_KEY` = `<your OpenRouter API Key>`
8. Copy your Worker URL (e.g. `https://nexus-ai-worker.<your-subdomain>.workers.dev`).

---

## Deployment Option B: Via Wrangler CLI

From this directory:

```bash
# 1. Install Wrangler
npm install

# 2. Login to Cloudflare
npx wrangler login

# 3. Add your OpenRouter API Key
npx wrangler secret put OLLAMA_API_KEY

# 4. Deploy to Cloudflare Edge
npx wrangler deploy
```

---

## Connecting the Worker to Frontend

When building the frontend package for HostGator:

```powershell
$env:VITE_AI_WORKER_URL = "https://nexus-ai-worker.<your-subdomain>.workers.dev"
npm run build
```

Or using the deployment packaging script:
```powershell
.\scripts\prepare-hostgator-package.ps1 -ApiUrl https://api.yourdomain.com -AiWorkerUrl https://nexus-ai-worker.your-subdomain.workers.dev
```

*Note: If `VITE_AI_WORKER_URL` is omitted, the frontend gracefully falls back to the native HostGator backend chat endpoint.*

After updating source-grounding behavior, deploy the Laravel API and this Worker. If the Worker is enabled, redeploy it even when its URL has not changed; it must read the API's `grounded` and `refusal` fields. The Worker no longer caches answers, so every question is checked against the current subject sources.
