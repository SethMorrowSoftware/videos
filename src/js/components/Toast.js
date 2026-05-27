/**
 * Toast Component
 * Displays temporary notification messages
 */

// Pre-baked icon markup is trusted; toast messages from callers are not, so
// we always render them via textContent rather than HTML interpolation.
const TOAST_ICONS = {
  success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  error: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  info: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 16V12M12 8H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
};

function renderToast(message, type = 'info', duration = 3000) {
  // Replace any existing toast so rapid-fire calls don't stack.
  const existingToast = document.querySelector('.toast');
  if (existingToast) existingToast.remove();

  const toast = document.createElement('div');
  toast.className = `toast toast--${type}`;
  toast.setAttribute('role', 'alert');
  toast.setAttribute('aria-live', 'polite');

  const iconEl = document.createElement('span');
  iconEl.className = 'toast-icon';
  iconEl.innerHTML = TOAST_ICONS[type] || TOAST_ICONS.info;

  const msgEl = document.createElement('span');
  msgEl.className = 'toast-message';
  msgEl.textContent = String(message ?? '');

  toast.appendChild(iconEl);
  toast.appendChild(msgEl);
  document.body.appendChild(toast);

  // Force reflow so the entrance transition runs.
  void toast.offsetHeight;
  toast.classList.add('toast--visible');

  setTimeout(() => {
    toast.classList.remove('toast--visible');
    toast.classList.add('toast--hiding');
    setTimeout(() => toast.remove(), 300);
  }, duration);

  return toast;
}

// Exposed as BOTH static and instance methods so callers can use
// `Toast.show(...)` or `new Toast().show(...)` interchangeably — both
// patterns exist across the codebase, and instance calls used to throw
// silently, swallowing every notification (bookmark added, link copied,
// episode failed, etc.).
export class Toast {
  show(message, type, duration) { return renderToast(message, type, duration); }
  success(message, duration)    { return renderToast(message, 'success', duration); }
  error(message, duration)      { return renderToast(message, 'error', duration); }
  info(message, duration)       { return renderToast(message, 'info', duration); }

  static show(message, type, duration)  { return renderToast(message, type, duration); }
  static success(message, duration)     { return renderToast(message, 'success', duration); }
  static error(message, duration)       { return renderToast(message, 'error', duration); }
  static info(message, duration)        { return renderToast(message, 'info', duration); }
}

export default Toast;
