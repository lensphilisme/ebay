export interface HttpRequestOptions {
  method?: string;
  url: string;
  baseUrl?: string;
  params?: Record<string, unknown>;
  headers?: Record<string, string>;
  body?: unknown;
  timeoutMs?: number;
}

export interface HttpResponse<T> {
  data: T;
  status: number;
  statusText: string;
  headers: Record<string, string>;
}

export class HttpError extends Error {
  readonly status?: number;
  readonly statusText?: string;
  readonly url: string;
  readonly data: unknown;
  readonly headers: Record<string, string>;

  constructor(message: string, init: { url: string; status?: number; statusText?: string; data?: unknown; headers?: Record<string, string> }) {
    super(message);
    this.name = 'HttpError';
    this.url = init.url;
    this.status = init.status;
    this.statusText = init.statusText;
    this.data = init.data;
    this.headers = init.headers ?? {};
  }
}

export function isHttpError(error: unknown): error is HttpError {
  return error instanceof HttpError;
}

function buildUrl(url: string, baseUrl?: string, params?: Record<string, unknown>): string {
  let full = baseUrl ? `${baseUrl.replace(/\/$/, '')}/${url.replace(/^\//, '')}` : url;
  if (!params) return full;

  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value == null) continue;
    const values = Array.isArray(value) ? value : [value];
    for (const entry of values) {
      search.append(key, typeof entry === 'object' ? JSON.stringify(entry) : String(entry));
    }
  }

  const query = search.toString();
  return query ? `${full}${full.includes('?') ? '&' : '?'}${query}` : full;
}

function prepareBody(body: unknown): BodyInit | undefined {
  if (body == null) return undefined;
  if (typeof body === 'string' || body instanceof URLSearchParams) return body;
  if (body instanceof ArrayBuffer || body instanceof Uint8Array) return body as BodyInit;
  return JSON.stringify(body);
}

async function decodeResponse<T>(response: Response): Promise<T> {
  const text = await response.text();
  if (!text) return undefined as T;
  try {
    return JSON.parse(text) as T;
  } catch {
    return text as T;
  }
}

function collectHeaders(response: Response): Record<string, string> {
  const headers: Record<string, string> = {};
  response.headers.forEach((value, key) => {
    headers[key.toLowerCase()] = value;
  });
  return headers;
}

export async function httpRequest<T = unknown>(options: HttpRequestOptions): Promise<HttpResponse<T>> {
  const method = options.method ?? 'GET';
  const url = buildUrl(options.url, options.baseUrl, options.params);
  const headers = { ...options.headers };
  const body = prepareBody(options.body);

  if (body && !(body instanceof URLSearchParams) && !Object.keys(headers).some((key) => key.toLowerCase() === 'content-type')) {
    headers['Content-Type'] = 'application/json';
  }

  const controller = new AbortController();
  const timer = options.timeoutMs ? setTimeout(() => controller.abort(), options.timeoutMs) : undefined;

  try {
    const response = await fetch(url, { method, headers, body, signal: controller.signal });
    const responseHeaders = collectHeaders(response);
    const data = await decodeResponse<T>(response);

    if (!response.ok) {
      throw new HttpError(`Request to ${url} failed with status ${response.status}`, {
        url,
        status: response.status,
        statusText: response.statusText,
        data,
        headers: responseHeaders,
      });
    }

    return { data, status: response.status, statusText: response.statusText, headers: responseHeaders };
  } finally {
    if (timer) clearTimeout(timer);
  }
}
