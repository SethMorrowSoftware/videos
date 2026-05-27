/**
 * PlayerComments - YouTube-style comments section for the video player page.
 *
 * Mounts into <section id="commentsSection"> in player.php and renders:
 *  - Header with comment count + sort dropdown (Top / Newest)
 *  - Composer (authenticated users only; guests get a sign-in CTA)
 *  - Thread list with one-level replies, like button, edit/delete menu,
 *    "View N replies" toggle, "Show more" pagination.
 *
 * Lifecycle:
 *   const c = new PlayerComments({ toast });
 *   c.mount(document.getElementById('commentsSection'));
 *   c.load(archiveId);            // on each new video
 *   c.load('different_video');    // re-renders from scratch
 *
 * All network calls go to api/comments.php. Comments never leave this
 * site — there is no archive.org outbound write anywhere in this module.
 */

import { escapeHtml } from '../utils/helpers.js';
import { AuthService } from '../services/AuthService.js';
import { getCsrfToken } from '../services/ApiService.js';

const API = 'api/comments.php';

const ICONS = {
  thumbsUp: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 22V11M2 13V20C2 21.1046 2.89543 22 4 22H17.7305C19.2007 22 20.4717 20.9303 20.7224 19.4856L21.9354 12.4856C22.2492 10.6764 20.8553 9 19.0179 9H14C13.4477 9 13 8.55228 13 8V4.46584C13 3.10399 11.896 2 10.5342 2C10.2086 2 9.91337 2.19169 9.78098 2.48891L7.26121 8.15214C7.10072 8.51091 6.74485 8.74147 6.35257 8.74147H4C2.89543 8.74147 2 9.6369 2 10.7415V13Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  thumbsUpFilled: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 22V11H2V20C2 21.1046 2.89543 22 4 22H7ZM9 11V22H17.7305C19.2007 22 20.4717 20.9303 20.7224 19.4856L21.9354 12.4856C22.2492 10.6764 20.8553 9 19.0179 9H14C13.4477 9 13 8.55228 13 8V4.46584C13 3.10399 11.896 2 10.5342 2C10.2086 2 9.91337 2.19169 9.78098 2.48891L7.26121 8.15214C7.10072 8.51091 6.74485 8.74147 6.35257 8.74147H9V11Z"/></svg>',
  reply: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 14L4 9L9 4M4 9H15C18.3137 9 21 11.6863 21 15V20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  more: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>',
  chevronDown: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12L10 17L20 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
};

// Generate a stable color from a string (for avatar backgrounds). Same
// approach YouTube uses for default avatars: hue derived from a hash.
function hashHue(str) {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
  return Math.abs(h) % 360;
}

function avatarStyle(name) {
  const hue = hashHue(name || '?');
  return `background: linear-gradient(135deg, hsl(${hue}, 55%, 52%), hsl(${(hue + 30) % 360}, 60%, 42%));`;
}

function initial(name) {
  return String(name || '?').trim().charAt(0).toUpperCase() || '?';
}

function relativeTime(iso) {
  if (!iso) return '';
  const then = new Date(iso.replace(' ', 'T') + 'Z');
  const now = new Date();
  const sec = Math.max(1, Math.floor((now - then) / 1000));
  if (sec < 60) return `${sec}s ago`;
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min}m ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr}h ago`;
  const day = Math.floor(hr / 24);
  if (day < 7) return `${day}d ago`;
  if (day < 30) return `${Math.floor(day / 7)}w ago`;
  if (day < 365) return `${Math.floor(day / 30)}mo ago`;
  return `${Math.floor(day / 365)}y ago`;
}

function formatCount(n) {
  if (!n || n < 1) return '';
  if (n < 1000) return String(n);
  if (n < 1_000_000) return (n / 1000).toFixed(n < 10_000 ? 1 : 0).replace(/\.0$/, '') + 'K';
  return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
}

// Render body text with safe autolinking. We escape everything first,
// then upgrade URLs to anchors — never the other way around.
function renderBody(text) {
  const esc = escapeHtml(text || '');
  const urlRx = /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g;
  return esc
    .replace(urlRx, (u) => `<a href="${u}" target="_blank" rel="noopener nofollow ugc">${u}</a>`)
    .replace(/\n/g, '<br>');
}

async function apiCall(action, payload, opts = {}) {
  if (opts.method === 'GET' || !opts.method) {
    const q = new URLSearchParams({ action, ...payload });
    const res = await fetch(`${API}?${q}`, { credentials: 'same-origin' });
    const json = await res.json().catch(() => ({ success: false, error: 'Network error' }));
    if (!res.ok || !json.success) {
      throw new Error(json.error || `Request failed (${res.status})`);
    }
    return json;
  }
  const res = await fetch(API, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
    },
    body: JSON.stringify({ action, ...payload }),
  });
  const json = await res.json().catch(() => ({ success: false, error: 'Network error' }));
  if (!res.ok || !json.success) {
    const err = new Error(json.error || `Request failed (${res.status})`);
    err.status = res.status;
    throw err;
  }
  return json;
}

export class PlayerComments {
  constructor({ toast } = {}) {
    this.toast = toast || { show: () => {} };
    this.root = null;
    this.archiveId = null;
    this.sort = 'top';
    this.page = 1;
    this.hasMore = false;
    this.total = 0;
    this.user = null;
    this.guestPending = true;
    this.loaded = false;
    this.openMenu = null;          // currently open kebab menu element
    this.editingId = null;
    this.replyingTo = null;
  }

  // =====================================================
  // MOUNT
  // =====================================================

  mount(container) {
    if (!container) return;
    this.root = container;
    this.root.innerHTML = this.shellHtml();
    this.cacheNodes();
    this.bindShellEvents();

    // Kick off a fetchMe and ONLY start reacting to onChange after it
    // resolves. Subscribing earlier would briefly flash the "Sign in"
    // CTA for logged-in users while the /me round-trip is in flight.
    AuthService.fetchMe().catch(() => {}).finally(() => {
      this.user = AuthService.getUser() || null;
      this.guestPending = false;
      this.renderComposerArea();
      if (this.loaded) this.renderList();
      AuthService.onChange(({ user }) => {
        this.user = user || null;
        this.renderComposerArea();
        if (this.loaded) this.renderList();
      });
    });

    // Close kebab menus on outside click
    document.addEventListener('click', (e) => {
      if (this.openMenu && !this.openMenu.contains(e.target)) {
        this.openMenu.removeAttribute('data-open');
        this.openMenu = null;
      }
    });
  }

  cacheNodes() {
    this.headerEl = this.root.querySelector('[data-comments-header]');
    this.countEl = this.root.querySelector('[data-comments-count]');
    this.sortBtn = this.root.querySelector('[data-comments-sort]');
    this.sortLabel = this.root.querySelector('[data-comments-sort-label]');
    this.sortMenu = this.root.querySelector('[data-comments-sort-menu]');
    this.composerEl = this.root.querySelector('[data-comments-composer]');
    this.listEl = this.root.querySelector('[data-comments-list]');
    this.loadMoreEl = this.root.querySelector('[data-comments-load-more]');
    this.emptyEl = this.root.querySelector('[data-comments-empty]');
    this.loadingEl = this.root.querySelector('[data-comments-loading]');
  }

  bindShellEvents() {
    this.sortBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = this.sortMenu.getAttribute('data-open') === 'true';
      this.sortMenu.setAttribute('data-open', open ? 'false' : 'true');
    });
    document.addEventListener('click', (e) => {
      if (!this.sortMenu) return;
      if (this.sortBtn.contains(e.target) || this.sortMenu.contains(e.target)) return;
      this.sortMenu.setAttribute('data-open', 'false');
    });
    this.sortMenu.addEventListener('click', (e) => {
      const opt = e.target.closest('[data-sort-value]');
      if (!opt) return;
      const value = opt.getAttribute('data-sort-value');
      if (value === this.sort) {
        this.sortMenu.setAttribute('data-open', 'false');
        return;
      }
      this.sort = value;
      this.sortLabel.textContent = value === 'top' ? 'Top comments' : 'Newest first';
      this.updateSortMenuChecks();
      this.sortMenu.setAttribute('data-open', 'false');
      this.reload();
    });

    this.loadMoreEl.addEventListener('click', () => {
      if (!this.hasMore) return;
      this.page += 1;
      this.fetchAndAppend();
    });
  }

  updateSortMenuChecks() {
    this.sortMenu.querySelectorAll('[data-sort-value]').forEach((el) => {
      const active = el.getAttribute('data-sort-value') === this.sort;
      el.setAttribute('data-active', active ? 'true' : 'false');
    });
  }

  // =====================================================
  // LOAD
  // =====================================================

  /**
   * Load (or reload) comments for a given archive id. Called by player.js
   * after the metadata for a video is shown.
   */
  async load(archiveId) {
    if (!this.root) return;
    if (this.archiveId === archiveId && this.loaded) return;
    this.archiveId = archiveId;
    this.page = 1;
    this.threads = [];
    this.threadsById = new Map();
    this.loaded = false;
    this.root.style.display = '';
    this.renderComposerArea();
    this.showLoading();
    await this.fetchAndAppend({ replace: true });
  }

  async reload() {
    if (!this.archiveId) return;
    this.page = 1;
    this.threads = [];
    this.threadsById = new Map();
    this.showLoading();
    await this.fetchAndAppend({ replace: true });
  }

  async fetchAndAppend({ replace = false } = {}) {
    try {
      const res = await apiCall('list', {
        video: this.archiveId,
        sort: this.sort,
        page: this.page,
      });
      const list = res.comments || [];
      if (replace) this.threads = [];
      for (const c of list) {
        this.threads.push(c);
        this.threadsById.set(c.id, c);
        if (Array.isArray(c.replies)) {
          for (const r of c.replies) this.threadsById.set(r.id, r);
        }
      }
      this.total = res.pagination?.total ?? this.threads.length;
      this.hasMore = !!res.pagination?.has_more;
      this.loaded = true;
      this.renderList();
    } catch (err) {
      this.hideLoading();
      this.listEl.innerHTML = `<div class="comments-error">Couldn't load comments. ${escapeHtml(err.message || '')}</div>`;
    }
  }

  // =====================================================
  // RENDER
  // =====================================================

  shellHtml() {
    return `
      <div class="comments-section">
        <div class="comments-section-header" data-comments-header>
          <h2 class="comments-section-title">
            <span data-comments-count>0</span> Comments
          </h2>
          <div class="comments-sort">
            <button type="button" class="comments-sort-btn" data-comments-sort aria-haspopup="true" aria-expanded="false">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 6H21M6 12H18M10 18H14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <span class="comments-sort-label" data-comments-sort-label>Top comments</span>
            </button>
            <div class="comments-sort-menu" data-comments-sort-menu role="menu" data-open="false">
              <button type="button" data-sort-value="top" data-active="true" role="menuitem">
                <span class="comments-sort-check">${ICONS.check}</span>
                <span>Top comments</span>
              </button>
              <button type="button" data-sort-value="newest" data-active="false" role="menuitem">
                <span class="comments-sort-check">${ICONS.check}</span>
                <span>Newest first</span>
              </button>
            </div>
          </div>
        </div>

        <div class="comments-composer-area" data-comments-composer></div>

        <div class="comments-list" data-comments-list></div>

        <div class="comments-loading" data-comments-loading style="display:none">
          ${this.skeletonHtml()}${this.skeletonHtml()}${this.skeletonHtml()}
        </div>
        <div class="comments-empty" data-comments-empty style="display:none">
          <div class="comments-empty-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M21 11.5C21.0034 12.8199 20.6951 14.1219 20.1 15.3C19.3944 16.7118 18.3098 17.8992 16.9674 18.7293C15.6251 19.5594 14.0782 19.9994 12.5 20C11.1801 20.0035 9.87812 19.6951 8.7 19.1L3 21L4.9 15.3C4.30493 14.1219 3.99656 12.8199 4 11.5C4.00061 9.92179 4.44061 8.37488 5.27072 7.03258C6.10083 5.69028 7.28825 4.6056 8.7 3.90003C9.87812 3.30496 11.1801 2.99659 12.5 3.00003H13C15.0843 3.11502 17.053 3.99479 18.5291 5.47089C20.0052 6.94699 20.885 8.91568 21 11V11.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="comments-empty-title">Be the first to comment</div>
          <div class="comments-empty-sub">Share what you thought of this film with the club.</div>
        </div>
        <button type="button" class="comments-load-more" data-comments-load-more style="display:none">
          Show more comments
        </button>
      </div>
    `;
  }

  skeletonHtml() {
    return `
      <div class="comment-skeleton">
        <div class="comment-skeleton-avatar"></div>
        <div class="comment-skeleton-body">
          <div class="comment-skeleton-line" style="width:35%"></div>
          <div class="comment-skeleton-line" style="width:85%"></div>
          <div class="comment-skeleton-line" style="width:60%"></div>
        </div>
      </div>
    `;
  }

  renderComposerArea() {
    const user = this.user;
    if (this.guestPending && user === null) {
      // Auth not yet resolved — leave area empty briefly.
      this.composerEl.innerHTML = '';
      return;
    }
    if (!user) {
      this.composerEl.innerHTML = `
        <div class="comments-signin-prompt">
          <div class="comments-signin-avatar"></div>
          <div class="comments-signin-text">
            <a href="login.php?next=${encodeURIComponent(location.pathname + location.search)}">Sign in</a>
            to leave a comment for other club members.
          </div>
        </div>
      `;
      return;
    }
    const name = user.display_name || user.username;
    this.composerEl.innerHTML = `
      <form class="comments-composer" data-composer>
        <div class="comments-avatar" style="${avatarStyle(name)}">${escapeHtml(initial(name))}</div>
        <div class="comments-composer-body">
          <textarea
            class="comments-composer-input"
            placeholder="Add a comment..."
            rows="1"
            maxlength="2000"
            data-composer-input
            aria-label="Add a comment"
          ></textarea>
          <div class="comments-composer-actions" data-composer-actions hidden>
            <span class="comments-composer-counter" data-composer-counter>0 / 2000</span>
            <div class="comments-composer-buttons">
              <button type="button" class="comments-btn comments-btn--ghost" data-composer-cancel>Cancel</button>
              <button type="submit" class="comments-btn comments-btn--primary" data-composer-submit disabled>Comment</button>
            </div>
          </div>
        </div>
      </form>
    `;
    this.bindComposer(this.composerEl.querySelector('[data-composer]'), {
      onSubmit: async (text, form) => {
        try {
          const res = await apiCall('create', {
            video_id: this.archiveId,
            body: text,
          }, { method: 'POST' });
          const c = res.comment;
          c.replies = [];
          this.threads.unshift(c);
          this.threadsById.set(c.id, c);
          this.total += 1;
          this.renderList();
          this.toast.show('Comment posted', 'success');
        } catch (err) {
          this.toast.show(err.message || 'Could not post comment', 'error');
          throw err;
        }
      },
    });
  }

  bindComposer(form, { onSubmit, autoFocus = false, initialValue = '', submitLabel = 'Comment' } = {}) {
    if (!form) return;
    const input = form.querySelector('[data-composer-input]');
    const actions = form.querySelector('[data-composer-actions]');
    const cancelBtn = form.querySelector('[data-composer-cancel]');
    const submitBtn = form.querySelector('[data-composer-submit]');
    const counter = form.querySelector('[data-composer-counter]');

    if (initialValue) input.value = initialValue;
    if (submitBtn && submitLabel) submitBtn.textContent = submitLabel;

    const updateState = () => {
      const v = input.value;
      const len = v.length;
      counter.textContent = `${len} / 2000`;
      submitBtn.disabled = v.trim().length === 0;
    };

    const expand = () => {
      actions.hidden = false;
      form.classList.add('comments-composer--active');
      updateState();
    };
    const collapse = () => {
      input.value = '';
      input.style.height = '';
      actions.hidden = true;
      form.classList.remove('comments-composer--active');
    };

    input.addEventListener('focus', expand);
    if (initialValue || autoFocus) expand();
    input.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 240) + 'px';
      updateState();
    });
    cancelBtn.addEventListener('click', () => {
      if (form.dataset.replyForm) {
        form.remove();
        this.replyingTo = null;
        return;
      }
      if (form.dataset.editForm) {
        const wrap = form.closest('[data-comment-edit-wrap]');
        if (wrap) wrap.remove();
        this.editingId = null;
        // Re-render the comment so the body comes back
        this.renderList();
        return;
      }
      collapse();
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Posting…';
      try {
        await onSubmit(text, form);
        if (form.dataset.replyForm || form.dataset.editForm) {
          // form is gone after re-render
        } else {
          collapse();
        }
      } catch (_) {
        submitBtn.disabled = false;
        submitBtn.textContent = submitLabel;
      }
    });

    // Auto-resize once if initial value
    if (initialValue) {
      requestAnimationFrame(() => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 240) + 'px';
        input.focus();
        input.selectionStart = input.value.length;
      });
    }
    if (autoFocus) {
      requestAnimationFrame(() => input.focus());
    }
  }

  showLoading() {
    this.loadingEl.style.display = '';
    this.emptyEl.style.display = 'none';
    this.listEl.innerHTML = '';
    this.loadMoreEl.style.display = 'none';
  }
  hideLoading() {
    this.loadingEl.style.display = 'none';
  }

  renderList() {
    this.hideLoading();
    this.countEl.textContent = formatCount(this.total) || '0';
    if (this.threads.length === 0) {
      this.emptyEl.style.display = '';
      this.listEl.innerHTML = '';
      this.loadMoreEl.style.display = 'none';
      return;
    }
    this.emptyEl.style.display = 'none';
    this.listEl.innerHTML = this.threads.map((t) => this.threadHtml(t)).join('');
    this.bindThreadEvents();
    this.loadMoreEl.style.display = this.hasMore ? '' : 'none';
  }

  threadHtml(thread) {
    const replies = thread.replies || [];
    const replyTotal = thread.reply_count || replies.length;
    const replyButtonText = replyTotal === 0
      ? ''
      : `${ICONS.chevronDown} <span>${replyTotal} ${replyTotal === 1 ? 'reply' : 'replies'}</span>`;
    return `
      <div class="comment-thread" data-thread-id="${thread.id}">
        ${this.commentHtml(thread, false)}
        ${replyTotal > 0 ? `
          <button type="button" class="comments-show-replies" data-toggle-replies aria-expanded="false">
            ${replyButtonText}
          </button>
          <div class="comment-replies" data-replies hidden>
            ${replies.map((r) => this.commentHtml(r, true)).join('')}
          </div>
        ` : '<div class="comment-replies" data-replies hidden></div>'}
        <div class="comment-reply-form-slot" data-reply-slot></div>
      </div>
    `;
  }

  commentHtml(c, isReply) {
    if (c.is_deleted) {
      return `
        <article class="comment comment--deleted ${isReply ? 'comment--reply' : ''}" data-comment-id="${c.id}">
          <div class="comments-avatar comments-avatar--muted">—</div>
          <div class="comment-main">
            <div class="comment-meta">
              <span class="comment-deleted-label">[deleted]</span>
            </div>
          </div>
        </article>
      `;
    }
    const name = c.author.display_name || c.author.username;
    const isOwn = c.author.is_viewer;
    const showOwner = isOwn || c.author.is_admin;
    return `
      <article class="comment ${isReply ? 'comment--reply' : ''}" data-comment-id="${c.id}">
        <div class="comments-avatar" style="${avatarStyle(name)}" aria-hidden="true">${escapeHtml(initial(name))}</div>
        <div class="comment-main">
          <header class="comment-meta">
            <span class="comment-author">${escapeHtml(name)}</span>
            ${c.author.is_admin ? '<span class="comment-badge comment-badge--admin">Mod</span>' : ''}
            <span class="comment-time">${escapeHtml(relativeTime(c.created_at))}</span>
            ${c.edited_at ? '<span class="comment-edited">(edited)</span>' : ''}
          </header>
          <div class="comment-body" data-comment-body>${renderBody(c.body)}</div>
          <div class="comment-actions">
            <button type="button" class="comment-like-btn ${c.liked ? 'is-liked' : ''}" data-like aria-pressed="${c.liked ? 'true' : 'false'}" aria-label="Like comment">
              <span class="comment-like-icon">${c.liked ? ICONS.thumbsUpFilled : ICONS.thumbsUp}</span>
              <span class="comment-like-count" data-like-count>${formatCount(c.like_count)}</span>
            </button>
            ${!isReply ? `
              <button type="button" class="comment-reply-btn" data-reply>
                ${ICONS.reply}<span>Reply</span>
              </button>
            ` : `
              <button type="button" class="comment-reply-btn" data-reply data-reply-to-reply>
                ${ICONS.reply}<span>Reply</span>
              </button>
            `}
          </div>
        </div>
        <div class="comment-kebab" data-kebab>
          <button type="button" class="comment-kebab-btn" data-kebab-toggle aria-haspopup="true" aria-expanded="false" aria-label="More options">
            ${ICONS.more}
          </button>
          <div class="comment-kebab-menu" data-kebab-menu role="menu">
            ${c.can_edit ? '<button type="button" data-action="edit" role="menuitem">Edit</button>' : ''}
            ${c.can_delete ? '<button type="button" data-action="delete" role="menuitem">Delete</button>' : ''}
            ${!isOwn ? '<button type="button" data-action="report" role="menuitem">Report</button>' : ''}
          </div>
        </div>
      </article>
    `;
  }

  // =====================================================
  // EVENT BINDING
  // =====================================================

  bindThreadEvents() {
    this.listEl.querySelectorAll('.comment-thread').forEach((threadEl) => {
      const threadId = parseInt(threadEl.dataset.threadId, 10);
      const toggleBtn = threadEl.querySelector('[data-toggle-replies]');
      const repliesEl = threadEl.querySelector('[data-replies]');

      if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
          const open = !repliesEl.hidden;
          repliesEl.hidden = open;
          toggleBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
          toggleBtn.classList.toggle('is-open', !open);
        });
      }

      threadEl.querySelectorAll('.comment').forEach((commentEl) => {
        this.bindCommentEvents(commentEl, threadId);
      });
    });
  }

  bindCommentEvents(commentEl, threadId) {
    const commentId = parseInt(commentEl.dataset.commentId, 10);
    const likeBtn = commentEl.querySelector('[data-like]');
    if (likeBtn) {
      likeBtn.addEventListener('click', () => this.handleLike(commentId, likeBtn));
    }
    const replyBtn = commentEl.querySelector('[data-reply]');
    if (replyBtn) {
      replyBtn.addEventListener('click', () => this.handleReply(threadId, commentId));
    }
    const kebab = commentEl.querySelector('[data-kebab]');
    if (kebab) {
      const toggle = kebab.querySelector('[data-kebab-toggle]');
      const menu = kebab.querySelector('[data-kebab-menu]');
      toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        if (this.openMenu && this.openMenu !== kebab) {
          this.openMenu.removeAttribute('data-open');
        }
        const open = kebab.getAttribute('data-open') === 'true';
        kebab.setAttribute('data-open', open ? 'false' : 'true');
        toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        this.openMenu = open ? null : kebab;
      });
      menu.querySelectorAll('[data-action]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const action = btn.getAttribute('data-action');
          kebab.removeAttribute('data-open');
          this.openMenu = null;
          if (action === 'edit') this.handleEdit(commentId);
          if (action === 'delete') this.handleDelete(commentId);
          if (action === 'report') this.handleReport(commentId);
        });
      });
    }
  }

  // =====================================================
  // ACTIONS
  // =====================================================

  async handleLike(commentId, btn) {
    if (!this.user) {
      this.toast.show('Sign in to like comments', 'info');
      return;
    }
    // Optimistic
    const comment = this.threadsById.get(commentId);
    if (!comment) return;
    const prevLiked = comment.liked;
    const prevCount = comment.like_count;
    comment.liked = !prevLiked;
    comment.like_count = prevCount + (comment.liked ? 1 : -1);
    this.updateLikeUi(btn, comment);
    try {
      const res = await apiCall('like', { id: commentId }, { method: 'POST' });
      comment.liked = res.liked;
      comment.like_count = res.like_count;
      this.updateLikeUi(btn, comment);
    } catch (err) {
      comment.liked = prevLiked;
      comment.like_count = prevCount;
      this.updateLikeUi(btn, comment);
      this.toast.show(err.message || 'Could not update like', 'error');
    }
  }

  updateLikeUi(btn, comment) {
    btn.classList.toggle('is-liked', comment.liked);
    btn.setAttribute('aria-pressed', comment.liked ? 'true' : 'false');
    btn.querySelector('.comment-like-icon').innerHTML = comment.liked ? ICONS.thumbsUpFilled : ICONS.thumbsUp;
    btn.querySelector('[data-like-count]').textContent = formatCount(comment.like_count);
  }

  handleReply(threadId, replyTargetId) {
    if (!this.user) {
      this.toast.show('Sign in to reply', 'info');
      return;
    }
    const threadEl = this.listEl.querySelector(`.comment-thread[data-thread-id="${threadId}"]`);
    if (!threadEl) return;
    const slot = threadEl.querySelector('[data-reply-slot]');
    if (this.replyingTo === replyTargetId) {
      slot.innerHTML = '';
      this.replyingTo = null;
      return;
    }
    this.replyingTo = replyTargetId;
    const name = this.user.display_name || this.user.username;
    slot.innerHTML = `
      <form class="comments-composer comments-composer--reply" data-composer data-reply-form>
        <div class="comments-avatar comments-avatar--sm" style="${avatarStyle(name)}">${escapeHtml(initial(name))}</div>
        <div class="comments-composer-body">
          <textarea class="comments-composer-input" placeholder="Add a reply..." rows="1" maxlength="2000" data-composer-input></textarea>
          <div class="comments-composer-actions" data-composer-actions>
            <span class="comments-composer-counter" data-composer-counter>0 / 2000</span>
            <div class="comments-composer-buttons">
              <button type="button" class="comments-btn comments-btn--ghost" data-composer-cancel>Cancel</button>
              <button type="submit" class="comments-btn comments-btn--primary" data-composer-submit disabled>Reply</button>
            </div>
          </div>
        </div>
      </form>
    `;
    const form = slot.querySelector('[data-composer]');
    this.bindComposer(form, {
      autoFocus: true,
      submitLabel: 'Reply',
      onSubmit: async (text) => {
        const res = await apiCall('create', {
          video_id: this.archiveId,
          body: text,
          parent_id: replyTargetId,
        }, { method: 'POST' });
        const reply = res.comment;
        const thread = this.threads.find((t) => t.id === threadId);
        if (thread) {
          thread.replies = thread.replies || [];
          thread.replies.push(reply);
          thread.reply_count = (thread.reply_count || 0) + 1;
        }
        this.threadsById.set(reply.id, reply);
        slot.innerHTML = '';
        this.replyingTo = null;
        this.renderList();
        // Auto-open the replies section
        const newThreadEl = this.listEl.querySelector(`.comment-thread[data-thread-id="${threadId}"]`);
        if (newThreadEl) {
          const repliesEl = newThreadEl.querySelector('[data-replies]');
          const toggleBtn = newThreadEl.querySelector('[data-toggle-replies]');
          if (repliesEl) repliesEl.hidden = false;
          if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.classList.add('is-open');
          }
        }
        this.toast.show('Reply posted', 'success');
      },
    });
  }

  handleEdit(commentId) {
    const comment = this.threadsById.get(commentId);
    if (!comment || !comment.can_edit) return;
    this.editingId = commentId;
    const commentEl = this.listEl.querySelector(`.comment[data-comment-id="${commentId}"]`);
    if (!commentEl) return;
    const bodyEl = commentEl.querySelector('[data-comment-body]');
    if (!bodyEl) return;
    const name = this.user?.display_name || this.user?.username || '?';
    const original = comment.body;
    bodyEl.outerHTML = `
      <div class="comment-edit-wrap" data-comment-edit-wrap>
        <form class="comments-composer comments-composer--inline" data-composer data-edit-form>
          <div class="comments-composer-body" style="margin-left:0">
            <textarea class="comments-composer-input" rows="1" maxlength="2000" data-composer-input>${escapeHtml(original)}</textarea>
            <div class="comments-composer-actions" data-composer-actions>
              <span class="comments-composer-counter" data-composer-counter>0 / 2000</span>
              <div class="comments-composer-buttons">
                <button type="button" class="comments-btn comments-btn--ghost" data-composer-cancel>Cancel</button>
                <button type="submit" class="comments-btn comments-btn--primary" data-composer-submit>Save</button>
              </div>
            </div>
          </div>
        </form>
      </div>
    `;
    const form = commentEl.querySelector('[data-composer]');
    this.bindComposer(form, {
      initialValue: original,
      submitLabel: 'Save',
      onSubmit: async (text) => {
        const res = await apiCall('edit', { id: commentId, body: text }, { method: 'POST' });
        Object.assign(comment, res.comment);
        this.editingId = null;
        this.renderList();
        this.toast.show('Comment updated', 'success');
      },
    });
  }

  async handleDelete(commentId) {
    if (!window.confirm('Delete this comment? This cannot be undone.')) return;
    try {
      await apiCall('delete', { id: commentId }, { method: 'POST' });
      const target = this.threadsById.get(commentId);
      if (!target) return;
      // If it's a top-level thread, soft-mark it deleted in place
      if (target.parent_id === null) {
        target.is_deleted = true;
        target.body = '';
      } else {
        // It's a reply — remove from the parent's replies array
        const parent = this.threads.find((t) => t.id === target.parent_id);
        if (parent) {
          parent.replies = (parent.replies || []).filter((r) => r.id !== commentId);
          parent.reply_count = Math.max(0, (parent.reply_count || 1) - 1);
        }
        this.threadsById.delete(commentId);
      }
      this.renderList();
      this.toast.show('Comment deleted', 'success');
    } catch (err) {
      this.toast.show(err.message || 'Could not delete comment', 'error');
    }
  }

  async handleReport(commentId) {
    if (!this.user) {
      this.toast.show('Sign in to report comments', 'info');
      return;
    }
    const reason = window.prompt('Why are you reporting this comment? (optional)');
    if (reason === null) return; // canceled
    try {
      await apiCall('report', { id: commentId, reason: reason || null }, { method: 'POST' });
      this.toast.show('Thanks — a moderator will review this comment.', 'success');
    } catch (err) {
      this.toast.show(err.message || 'Could not submit report', 'error');
    }
  }
}

export default PlayerComments;
