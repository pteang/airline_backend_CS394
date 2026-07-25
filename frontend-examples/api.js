// Central API client for the airline backend.
// Framework-agnostic: works in React, Vue, or plain JS. Uses fetch + a Bearer
// token stored in localStorage. Import `api` and call the typed helpers.

const BASE_URL =
  (import.meta?.env?.VITE_API_URL) || 'http://127.0.0.1:8000/api';

const TOKEN_KEY = 'airline_token';

export const auth = {
  get token() {
    return localStorage.getItem(TOKEN_KEY);
  },
  set(token) {
    localStorage.setItem(TOKEN_KEY, token);
  },
  clear() {
    localStorage.removeItem(TOKEN_KEY);
  },
};

/**
 * Core request wrapper. Attaches the bearer token, sends/parses JSON, and
 * throws an ApiError (with `.status` and `.errors`) on non-2xx responses.
 */
async function request(path, { method = 'GET', body, params } = {}) {
  const url = new URL(`${BASE_URL}${path}`);
  if (params) {
    Object.entries(params)
      .filter(([, v]) => v !== undefined && v !== null && v !== '')
      .forEach(([k, v]) => url.searchParams.set(k, v));
  }

  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (auth.token) headers.Authorization = `Bearer ${auth.token}`;

  const res = await fetch(url, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  // 401 -> token invalid/expired: clear it so the UI can redirect to login.
  if (res.status === 401) {
    auth.clear();
  }

  const data = res.status === 204 ? null : await res.json().catch(() => null);

  if (!res.ok) {
    throw new ApiError(
      data?.message || `Request failed (${res.status})`,
      res.status,
      data?.errors,
    );
  }
  return data;
}

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message);
    this.status = status;
    this.errors = errors || {}; // Laravel validation errors: { field: [msg] }
  }
}

// --- Typed endpoint helpers -------------------------------------------------

export const api = {
  // Auth
  register: (payload) => request('/auth/register', { method: 'POST', body: payload }),
  login: (email, password) => request('/auth/login', { method: 'POST', body: { email, password } }),
  staffLogin: (email, password) => request('/auth/staff/login', { method: 'POST', body: { email, password } }),
  logout: () => request('/auth/logout', { method: 'POST' }),
  me: () => request('/auth/me'),

  // Public reference / search
  airports: (params) => request('/airports', { params }),
  routes: (params) => request('/routes', { params }),
  searchFlights: (params) => request('/flights', { params }),
  flight: (id) => request(`/flights/${id}`),
  lookupTicket: (ticket_code) => request('/tickets/lookup', { method: 'POST', body: { ticket_code } }),

  // Passenger booking journey
  myBookings: (params) => request('/bookings', { params }),
  createBooking: (payload) => request('/bookings', { method: 'POST', body: payload }),
  booking: (id) => request(`/bookings/${id}`),
  cancelBooking: (id) => request(`/bookings/${id}/cancel`, { method: 'POST' }),
  payBooking: (id, payment_method) =>
    request(`/bookings/${id}/payment`, { method: 'POST', body: { payment_method } }),
  bookingTicket: (id) => request(`/bookings/${id}/ticket`),

  // Staff/admin (require an internal-user token)
  createFlight: (payload) => request('/flights', { method: 'POST', body: payload }),
  updateFlightStatus: (id, status, reason) =>
    request(`/flights/${id}/status`, { method: 'PATCH', body: { status, reason } }),
};
