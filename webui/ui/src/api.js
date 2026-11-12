// Thin client for api.php. Keeps the CSRF token from the last session/login answer.
let csrf = '';

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

async function request(method, route, body, query = {}) {
  const qs = new URLSearchParams({ r: route, ...query }).toString();
  const res = await fetch(`api.php?${qs}`, {
    method,
    credentials: 'same-origin',
    headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {},
    body: method === 'POST' ? JSON.stringify(body ?? {}) : undefined,
  });
  let data;
  try {
    data = await res.json();
  } catch {
    throw new ApiError(`The Drobo answered with HTTP ${res.status}`, res.status);
  }
  if (data && data.csrf) csrf = data.csrf;
  if (!res.ok) throw new ApiError(data?.error || `HTTP ${res.status}`, res.status);
  return data;
}

export const api = {
  get: (route, query) => request('GET', route, null, query),
  post: (route, body) => request('POST', route, body),
};

// --- formatting -----------------------------------------------------------

export function bytes(n, digits = 1) {
  if (n == null) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let i = 0;
  let v = n;
  while (v >= 1000 && i < units.length - 1) {
    v /= 1000;
    i++;
  }
  return `${v.toFixed(i === 0 ? 0 : digits)} ${units[i]}`;
}

export function temp(c, unit = 'C') {
  if (c == null) return '—';
  return unit === 'F' ? `${Math.round((c * 9) / 5 + 32)} °F` : `${Math.round(c)} °C`;
}

export function duration(s) {
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${m}m`;
  return `${m}m`;
}

export function slotName(slot) {
  if (slot?.startsWith('bay')) return `Bay ${slot.slice(3)}`;
  return { msata: 'mSATA SSD', usb: 'USB stick', other: 'Other' }[slot] || slot;
}

export function hours(h) {
  if (h == null) return '—';
  return h < 48 ? `${h} h` : `${Math.round(h / 24)} days`;
}
