import { getToken } from "./api";

type AssetKind = "page" | "page-info";
type AssetRecord = {
  key: string;
  userId: string;
  url: string;
  revision: string;
  kind: AssetKind;
  cachedAt: number;
  lastUsedAt: number;
  size: number;
  value: Blob | number;
};

const DATABASE_NAME = "nexus-student-test-assets";
const DATABASE_VERSION = 1;
const STORE_NAME = "assets";
const recordsInFlight = new Map<string, Promise<AssetRecord | undefined>>();
const fetchesInFlight = new Map<string, Promise<Blob | number>>();
let cacheUserId: string | null = null;
let databasePromise: Promise<IDBDatabase> | undefined;

function assetKey(userId: string, url: string, kind: AssetKind, revision: string) {
  return `${userId}|${kind}|${url}|${revision}`;
}

export function setTestAssetCacheUser(userId: string | null) {
  if (userId === cacheUserId) return;
  cacheUserId = userId;
  recordsInFlight.clear();
  fetchesInFlight.clear();
}

export function getTestPageCount(url: string, revision: string): Promise<number> {
  const token = getToken();
  return getOrFetchAsset(url, "page-info", revision, async () => {
    const response = await authenticatedFetch(url, token);
    if (!response.ok) {
      throw new Error(`Could not load pages (${response.status}).`);
    }
    const result = (await response.json()) as { pageCount?: number };
    const pageCount = Number(result.pageCount);
    if (!Number.isInteger(pageCount) || pageCount < 0) {
      throw new Error("The server returned an invalid page count.");
    }
    return pageCount;
  });
}

export function getTestPageBlob(url: string, revision: string): Promise<Blob> {
  const token = getToken();
  return getOrFetchAsset(url, "page", revision, async () => {
    const response = await authenticatedFetch(url, token);
    if (!response.ok) {
      throw new Error(`Could not load original page (${response.status}).`);
    }
    return response.blob();
  });
}

async function authenticatedFetch(url: string, token: string | null) {
  if (!token) throw new Error("Your session has expired. Please sign in again.");
  return fetch(url, {
    headers: { Authorization: `Bearer ${token}` },
    cache: "no-store",
  });
}

function getOrFetchAsset<T extends Blob | number>(
  url: string,
  kind: AssetKind,
  revision: string,
  fetchValue: () => Promise<T>,
): Promise<T> {
  const userId = cacheUserId;
  const key = assetKey(userId ?? "anonymous", url, kind, revision);
  const token = getToken();
  const pending = fetchesInFlight.get(key);
  if (pending) return pending as Promise<T>;

  const promise = readAsset(key, userId, url, kind, revision).then(async (record) => {
    if (record) return record.value as T;
    const value = await fetchValue();
    if (
      userId !== null &&
      cacheUserId === userId &&
      token !== null &&
      getToken() === token
    ) {
      await writeAsset(key, userId, url, kind, value, revision);
    }
    return value;
  });
  fetchesInFlight.set(key, promise);
  void promise.then(
    () => {
      if (fetchesInFlight.get(key) === promise) fetchesInFlight.delete(key);
    },
    () => {
      if (fetchesInFlight.get(key) === promise) fetchesInFlight.delete(key);
    },
  );
  return promise;
}

async function openDatabase(): Promise<IDBDatabase> {
  if (databasePromise) return databasePromise;
  try {
    if (navigator.storage?.persist) {
      void navigator.storage.persist().catch(() => false);
    }
  } catch {
    // Persistent browser storage is best-effort.
  }
  const opening = new Promise<IDBDatabase>((resolve, reject) => {
    if (!("indexedDB" in window)) {
      reject(new Error("IndexedDB is unavailable."));
      return;
    }
    const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION);
    request.onupgradeneeded = () => {
      const database = request.result;
      if (!database.objectStoreNames.contains(STORE_NAME)) {
        const store = database.createObjectStore(STORE_NAME, {
          keyPath: "key",
        });
        store.createIndex("userId", "userId");
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
    request.onblocked = () => reject(new Error("IndexedDB upgrade is blocked."));
  });
  const cachedOpening = opening.catch((error: unknown) => {
    databasePromise = undefined;
    throw error;
  });
  databasePromise = cachedOpening;
  return cachedOpening;
}

async function readAsset(
  key: string,
  userId: string | null,
  url: string,
  kind: AssetKind,
  revision: string,
): Promise<AssetRecord | undefined> {
  const pending = recordsInFlight.get(key);
  if (pending) return pending;
  const request = (async () => {
    try {
      const database = await openDatabase();
      const record = await new Promise<AssetRecord | undefined>(
        (resolve, reject) => {
          const transaction = database.transaction(STORE_NAME, "readwrite");
          const store = transaction.objectStore(STORE_NAME);
          const getRequest = store.get(key);
          getRequest.onsuccess = () => {
            const found = getRequest.result as AssetRecord | undefined;
            if (found) {
              found.lastUsedAt = Date.now();
              store.put(found);
            }
            resolve(found);
          };
          getRequest.onerror = () => reject(getRequest.error);
          transaction.onerror = () => reject(transaction.error);
        },
      );
      if (
        !record ||
        record.userId !== userId ||
        record.url !== url ||
        record.kind !== kind ||
        record.revision !== revision
      ) {
        if (record) void deleteAsset(key);
        return undefined;
      }
      return record;
    } catch {
      return undefined;
    }
  })();
  recordsInFlight.set(key, request);
  try {
    return await request;
  } finally {
    if (recordsInFlight.get(key) === request) recordsInFlight.delete(key);
  }
}

async function writeAsset(
  key: string,
  userId: string,
  url: string,
  kind: AssetKind,
  value: Blob | number,
  revision: string,
) {
  const size = value instanceof Blob ? value.size : 0;
  if (cacheUserId !== userId) return;
  try {
    const database = await openDatabase();
    const existing = await getUserAssets(database, userId);
    for (const record of existing) {
      if (
        record.url === url &&
        record.kind === kind &&
        record.revision !== revision
      ) {
        await deleteAsset(record.key);
      }
    }
    if (cacheUserId !== userId) return;
    const now = Date.now();
    const record: AssetRecord = {
      key,
      userId,
      url,
      revision,
      kind,
      cachedAt: now,
      lastUsedAt: now,
      size,
      value,
    };
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction(STORE_NAME, "readwrite");
      transaction.objectStore(STORE_NAME).put(record);
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
  } catch {
    // Network responses remain usable if browser storage is full or unavailable.
  }
}

async function getUserAssets(
  database: IDBDatabase,
  userId: string,
): Promise<AssetRecord[]> {
  return new Promise((resolve, reject) => {
    const transaction = database.transaction(STORE_NAME, "readonly");
    const request = transaction
      .objectStore(STORE_NAME)
      .index("userId")
      .getAll(userId);
    request.onsuccess = () => resolve(request.result as AssetRecord[]);
    request.onerror = () => reject(request.error);
  });
}

async function deleteAsset(key: string) {
  try {
    const database = await openDatabase();
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction(STORE_NAME, "readwrite");
      transaction.objectStore(STORE_NAME).delete(key);
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
    });
  } catch {
    // Cache cleanup must not prevent the student from continuing.
  }
}
