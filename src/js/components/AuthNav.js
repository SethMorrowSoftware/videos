/**
 * AuthNav component
 *
 * Renders the auth controls (Sign in / Sign up, or avatar dropdown) into
 * a mount point on pages that have `data-auth-nav` in the header.
 *
 * The same logic is used by any page that imports this module. Pages that
 * render auth state server-side (partials/header.php) don't need it —
 * this exists for pages like index.php and player.php which build their
 * headers without a PHP-side user lookup.
 *
 * Usage (in HTML):
 *   <div data-auth-nav></div>
 *
 * Usage (in JS):
 *   import { AuthNav } from './components/AuthNav.js';
 *   AuthNav.mount();
 */

import { AuthService } from '../services/AuthService.js';

function initial(user) {
  const name = (user && (user.display_name || user.username)) || '?';
  return String(name).charAt(0).toUpperCase();
}

function renderSignedOut() {
  return `
    <a href="login.php" class="header-auth-link">Sign in</a>
    <a href="register.php" class="header-auth-link header-auth-link--primary">Sign up</a>
  `;
}

function renderSignedIn(user) {
  const name = user.display_name || user.username;
  const isAdmin = user.role === 'admin' || user.role === 'editor';
  return `
    <div class="header-auth-avatar" data-auth-menu>
      <button type="button" class="header-auth-avatar-btn" aria-haspopup="true" aria-expanded="false" data-auth-menu-toggle>
        <span class="header-auth-avatar-circle">${escapeHtml(initial(user))}</span>
        <span>${escapeHtml(name)}</span>
      </button>
      <div class="header-auth-menu" role="menu" data-auth-menu-panel>
        <div class="header-auth-menu-label">Signed in as</div>
        <div class="header-auth-menu-label" style="color:var(--color-text-primary); text-transform:none; letter-spacing:0;">
          ${escapeHtml(user.email || user.username)}
        </div>
        <div class="header-auth-menu-divider"></div>
        <a href="account.php" role="menuitem">Account</a>
        <a href="collections.php" role="menuitem">My collections</a>
        <a href="index.php" role="menuitem">Home</a>
        ${isAdmin ? `<a href="admin.php" role="menuitem">Admin</a>` : ''}
        <div class="header-auth-menu-divider"></div>
        <button type="button" role="menuitem" data-auth-logout>Sign out</button>
      </div>
    </div>
  `;
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function wireMenu(root) {
  const toggle = root.querySelector('[data-auth-menu-toggle]');
  const panel = root.querySelector('[data-auth-menu-panel]');
  if (!toggle || !panel) return;

  const close = () => {
    panel.removeAttribute('data-open');
    toggle.setAttribute('aria-expanded', 'false');
  };
  const open = () => {
    panel.setAttribute('data-open', 'true');
    toggle.setAttribute('aria-expanded', 'true');
  };

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    panel.hasAttribute('data-open') ? close() : open();
  });

  document.addEventListener('click', (e) => {
    if (!root.contains(e.target)) close();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  const logoutBtn = root.querySelector('[data-auth-logout]');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try { await AuthService.logout(); }
      finally { window.location.href = 'index.php'; }
    });
  }
}

export const AuthNav = {
  mount() {
    const mounts = document.querySelectorAll('[data-auth-nav]');
    if (!mounts.length) return;

    const render = ({ user }) => {
      mounts.forEach(mount => {
        mount.innerHTML = user ? renderSignedIn(user) : renderSignedOut();
        if (user) wireMenu(mount);
      });
    };

    // Subscribe (fires immediately with cached state, may be null on first load)
    AuthService.onChange(render);

    // Kick off the fetch (no-op if already cached)
    AuthService.fetchMe().catch(() => {});
  },
};

export default AuthNav;
