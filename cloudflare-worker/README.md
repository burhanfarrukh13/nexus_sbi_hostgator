# Nexus SBI — Cloudflare AI Edge Worker (Zero Server Load)

This Cloudflare Worker eliminates AI Notebook concurrency starvation and protects your HostGator Business account.

### How it Works
1. **Student Question Arrives at the Edge**: Cloudflare checks its Edge Cache. If an identical question was asked recently for that subject, the answer is returned **in 20ms** with zero server hits.
2. **Context Retrieval**: If uncached, the Worker makes a 50ms API call to HostGator to fetch source chunk excerpts and check daily limits. The HostGator PHP process finishes and terminates immediately.
3. **Direct AI Completion**: The Worker calls Ollama Cloud directly from Cloudflare's global edge network.
4. **Zero PHP Processes Blocked**: Even if Ollama takes 20 to 45 seconds to generate an answer, **zero Entry Processes are held open on HostGator**.
5. **Background Sync**: The completed conversation is asynchronously logged back to your Laravel database without making the student wait.

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
   * Add Variable: `OLLAMA_API_URL` = `https://ollama.com/v1/chat/completions`
   * Add Variable: `OLLAMA_MODEL` = `gemma4:31b`
   * Add Secret: `OLLAMA_API_KEY` = `<your Ollama Cloud API Key>`
8. Copy your Worker URL (e.g. `https://nexus-ai-worker.<your-subdomain>.workers.dev`).

---

## Deployment Option B: Via Wrangler CLI

From this directory:

```bash
# 1. Install Wrangler
npm install

# 2. Login to Cloudflare
npx wrangler login

# 3. Add your secret Ollama API Key
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
