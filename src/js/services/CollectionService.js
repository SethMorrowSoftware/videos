/**
 * CollectionService - Frontend client for /api/collections.php
 *
 * Thin wrapper around the REST endpoint. All methods return the parsed
 * success payload and throw on error.
 */

// Relative path so subdirectory deployments (e.g. /films/ on cPanel)
// resolve correctly against document.baseURI.
const BASE = 'api/collections.php';

class CollectionApiError extends Error {
  constructor(message, status) {
    super(message);
    this.name = 'CollectionApiError';
    this.status = status;
  }
}

async function call(path, { method = 'GET', body = null } = {}) {
  const options = {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  };
  if (body !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(path, options);
  } catch (_) {
    throw new CollectionApiError('Network error', 0);
  }

  let data = null;
  try { data = await res.json(); } catch (_) {}

  if (!res.ok || (data && data.success === false)) {
    throw new CollectionApiError(
      (data && (data.error || data.message)) || `Request failed (${res.status})`,
      res.status
    );
  }
  return data || {};
}

export const CollectionService = {
  CollectionApiError,

  /** List the current user's collections. */
  async listMine() {
    const res = await call(BASE);
    return res.data || [];
  },

  /** Fetch a single owned collection (with items). */
  async get(id) {
    const res = await call(`${BASE}?id=${encodeURIComponent(id)}`);
    return res.data || null;
  },

  /** Fetch a public collection by owner username + slug. */
  async getPublic(username, slug) {
    const res = await call(
      `${BASE}?username=${encodeURIComponent(username)}&slug=${encodeURIComponent(slug)}`
    );
    return res.data || null;
  },

  /** List public collections (directory view). */
  async listPublic({ limit = 24, offset = 0 } = {}) {
    const res = await call(`${BASE}?public=1&limit=${limit}&offset=${offset}`);
    return res.data || [];
  },

  /** Which of my collections contain this video? */
  async findContaining(archiveId) {
    const res = await call(`${BASE}?archive_id=${encodeURIComponent(archiveId)}`);
    return res.data || [];
  },

  async create({ name, description = '', is_public = false }) {
    const res = await call(BASE, {
      method: 'POST',
      body: { action: 'create', name, description, is_public },
    });
    return res.collection;
  },

  async update(id, fields) {
    const res = await call(BASE, {
      method: 'POST',
      body: { action: 'update', id, ...fields },
    });
    return res.collection;
  },

  async delete(id) {
    return call(BASE, {
      method: 'POST',
      body: { action: 'delete', id },
    });
  },

  async addItem(id, video) {
    return call(BASE, {
      method: 'POST',
      body: { action: 'addItem', id, video },
    });
  },

  async removeItem(id, archiveId) {
    return call(BASE, {
      method: 'POST',
      body: { action: 'removeItem', id, archive_id: archiveId },
    });
  },

  async reorderItems(id, archiveIds) {
    return call(BASE, {
      method: 'POST',
      body: { action: 'reorderItems', id, archive_ids: archiveIds },
    });
  },

  async updateItemNote(id, archiveId, note) {
    return call(BASE, {
      method: 'POST',
      body: { action: 'updateNote', id, archive_id: archiveId, note },
    });
  },
};

export default CollectionService;
