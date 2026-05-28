/**
 * OfflineHandler Service
 *
 * Tracks network status with PROBE-BASED detection — `navigator.onLine` is
 * notoriously unreliable (false negatives on Linux/containers/captive
 * portals/post-sleep, false positives on local-only networks), so we never
 * surface offline UI based on that flag alone.
 *
 * Strategy:
 *   - Default to online. Banner stays hidden until we have hard evidence.
 *   - On the browser `offline` event, run a short HEAD probe against the
 *     same-origin API. Only if the probe fails do we declare offline.
 *   - On the browser `online` event, run the same probe. Only when it
 *     succeeds do we hide the banner and fire callbacks.
 *   - While offline, retry the probe on an exponential backoff so the
 *     banner disappears on its own when connectivity returns even if the
 *     browser misses the `online` event (common after VPN reconnect).
 */

import { ICONS } from '../utils/icons.js';

const PROBE_URL = 'api/index.php';
const PROBE_TIMEOUT_MS = 4000;
const PROBE_MIN_INTERVAL_MS = 2000;
const RECHECK_INITIAL_MS = 3000;
const RECHECK_MAX_MS = 30000;

export class OfflineHandler {
  constructor() {
    this.isOnline = true;
    this.callbacks = [];
    this._lastProbeAt = 0;
    this._probePromise = null;
    this._recheckTimer = null;
    this._recheckDelay = RECHECK_INITIAL_MS;

    window.addEventListener('online', () => this._handleBrowserOnline());
    window.addEventListener('offline', () => this._handleBrowserOffline());
  }

  /**
   * Browser fired `offline`. The flag is a hint, not truth — verify with a
   * real network request before changing UI state.
   */
  _handleBrowserOffline() {
    this._probe().then(reachable => {
      if (!reachable) this._declareOffline();
    });
  }

  /**
   * Browser fired `online`. Verify the probe succeeds before telling the
   * app to refresh — networks often flap `online` before they actually work.
   */
  _handleBrowserOnline() {
    this._probe().then(reachable => {
      if (reachable && !this.isOnline) this._declareOnline();
    });
  }

  /**
   * Run a single HEAD probe with a tight timeout. Coalesces concurrent
   * callers and rate-limits to once per PROBE_MIN_INTERVAL_MS.
   */
  async _probe() {
    if (this._probePromise) return this._probePromise;

    const sinceLast = Date.now() - this._lastProbeAt;
    if (sinceLast < PROBE_MIN_INTERVAL_MS) {
      return navigator.onLine !== false;
    }

    this._probePromise = (async () => {
      this._lastProbeAt = Date.now();
      const controller = new AbortController();
      const t = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS);
      try {
        const res = await fetch(PROBE_URL, {
          method: 'HEAD',
          cache: 'no-store',
          credentials: 'same-origin',
          signal: controller.signal,
        });
        clearTimeout(t);
        return res.ok || res.status < 500;
      } catch {
        clearTimeout(t);
        return false;
      }
    })();

    try {
      return await this._probePromise;
    } finally {
      this._probePromise = null;
    }
  }

  _declareOffline() {
    if (!this.isOnline) return;
    this.isOnline = false;
    this._showBanner();
    this.callbacks.forEach(cb => {
      try { cb(false); } catch (e) { console.warn('OfflineHandler callback error', e); }
    });
    this._scheduleRecheck();
  }

  _declareOnline() {
    if (this.isOnline) return;
    this.isOnline = true;
    this._hideBanner();
    this._recheckDelay = RECHECK_INITIAL_MS;
    if (this._recheckTimer) {
      clearTimeout(this._recheckTimer);
      this._recheckTimer = null;
    }
    this.callbacks.forEach(cb => {
      try { cb(true); } catch (e) { console.warn('OfflineHandler callback error', e); }
    });
  }

  /**
   * Background probe so the banner disappears on its own when connectivity
   * comes back without a browser `online` event (VPN reconnect, sleep/wake).
   * Exponential backoff capped at RECHECK_MAX_MS.
   */
  _scheduleRecheck() {
    if (this._recheckTimer) clearTimeout(this._recheckTimer);
    this._recheckTimer = setTimeout(async () => {
      const reachable = await this._probe();
      if (reachable) {
        this._declareOnline();
      } else {
        this._recheckDelay = Math.min(this._recheckDelay * 2, RECHECK_MAX_MS);
        this._scheduleRecheck();
      }
    }, this._recheckDelay);
  }

  _showBanner() {
    if (document.getElementById('offline-banner')) return;
    const banner = document.createElement('div');
    banner.id = 'offline-banner';
    banner.className = 'offline-banner';
    banner.innerHTML = `<span>${ICONS.wifi} You're offline. Some features may be limited.</span>`;
    document.body.appendChild(banner);
  }

  _hideBanner() {
    const banner = document.getElementById('offline-banner');
    if (banner) {
      banner.classList.add('hiding');
      setTimeout(() => banner.remove(), 300);
    }
  }

  onStatusChange(callback) {
    this.callbacks.push(callback);
  }

  /**
   * External callers can ask "are we definitely offline?" — this NEVER
   * returns true based only on `navigator.onLine`; it requires that our
   * probe-driven state machine has flipped to offline. Used by performSearch
   * etc. to gate user-facing offline messaging.
   */
  isDefinitelyOffline() {
    return !this.isOnline;
  }
}

export default OfflineHandler;
