import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// The broadcasting auth route lives at the app root (`/broadcasting/auth`),
// not under the `/api` prefix `api.js` uses — derive the root from it.
const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const appUrl = apiUrl.replace(/\/api\/?$/, '');
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

let echo = null;

/**
 * Lazily creates the shared Reverb connection. Returns null when no
 * Reverb key is configured (dev without `reverb:start` running, or a
 * deployment that hasn't set it up yet) — callers MUST treat that as
 * "stay on polling", never throw.
 */
export function getEcho() {
  if (!reverbKey) return null;
  if (echo) return echo;

  window.Pusher = Pusher;

  echo = new Echo({
    broadcaster: 'reverb',
    key: reverbKey,
    wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
    wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 80,
    wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    // Private channels need our Sanctum bearer token, which api.js keeps
    // in localStorage — not a cookie, so Echo's default auth won't send
    // it. Read the token fresh on every subscribe (it can change between
    // logins) rather than baking it in at construction time.
    authorizer: (channel) => ({
      authorize(socketId, callback) {
        const token = localStorage.getItem('auth_token');

        fetch(`${appUrl}/broadcasting/auth`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
          },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then((res) => {
            if (!res.ok) throw new Error(`broadcasting auth failed (${res.status})`);
            return res.json();
          })
          .then((data) => callback(false, data))
          .catch((error) => callback(true, error));
      },
    }),
  });

  return echo;
}

/** Tear the socket down — call on logout so a stale connection (and its
 * private channel) doesn't outlive the session on a shared machine. */
export function disconnectEcho() {
  if (echo) {
    echo.disconnect();
    echo = null;
  }
}
