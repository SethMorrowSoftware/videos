/**
 * Utility Helper Functions
 * Common utility functions used throughout the application
 */

/**
 * Safely parse JSON with fallback
 */
export function safeParseJSON(str) {
  try {
    return JSON.parse(str);
  } catch {
    return null;
  }
}

/**
 * Escape HTML to prevent XSS
 */
export function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Sanitize HTML while preserving safe tags
 */
export function sanitizeHtml(html) {
  if (!html) return '';

  try {
    const temp = document.createElement('div');
    temp.innerHTML = html;

    // Remove script tags
    temp.querySelectorAll('script').forEach(script => script.remove());

    // Remove event handlers and dangerous attributes
    temp.querySelectorAll('*').forEach(el => {
      [...el.attributes].forEach(attr => {
        if (attr.name.startsWith('on') || attr.name === 'javascript:') {
          el.removeAttribute(attr.name);
        }
      });

      // Sanitize links
      if (el.tagName === 'A' && el.getAttribute('href')) {
        const href = el.getAttribute('href');
        if (href.startsWith('javascript:') || href.startsWith('data:')) {
          el.removeAttribute('href');
        } else if (!href.startsWith('http') && !href.startsWith('/') && !href.startsWith('#')) {
          if (!href.includes('://')) {
            el.setAttribute('href', `https://archive.org${href.startsWith('/') ? '' : '/'}${href}`);
          }
        }
        if (el.getAttribute('href')?.startsWith('http')) {
          el.setAttribute('target', '_blank');
          el.setAttribute('rel', 'noopener noreferrer');
        }
      }
    });

    return temp.innerHTML;
  } catch (err) {
    console.error('Error sanitizing HTML:', err);
    return escapeHtml(html);
  }
}

/**
 * Extract first value from array or return value
 */
export function extractValue(field) {
  return Array.isArray(field) ? field[0] : field;
}

/**
 * Format runtime from seconds to readable format
 */
export function formatRuntime(runtime) {
  if (!runtime) return '';
  const s = runtime.toString();
  if (s.includes(':')) return s;
  const secs = parseInt(s, 10);
  if (isNaN(secs)) return '';
  const mins = Math.floor(secs / 60);
  const remSecs = secs % 60;
  if (mins < 60) {
    return `${mins}:${remSecs.toString().padStart(2, '0')}`;
  }
  const hrs = Math.floor(mins / 60);
  const remM = mins % 60;
  return `${hrs}:${remM.toString().padStart(2, '0')}:${remSecs.toString().padStart(2, '0')}`;
}

/**
 * Format time from seconds
 */
export function formatTime(seconds) {
  if (isNaN(seconds)) return '0:00';
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  if (hours > 0) {
    return `${hours}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

/**
 * Format file size to human readable
 */
export function formatFileSize(bytes) {
  if (!bytes) return '';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + units[i];
}

/**
 * Debounce function execution
 */
export function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

/**
 * Throttle function execution
 */
export function throttle(func, limit) {
  let inThrottle;
  return function(...args) {
    if (!inThrottle) {
      func.apply(this, args);
      inThrottle = true;
      setTimeout(() => inThrottle = false, limit);
    }
  };
}

/**
 * Get thumbnail URL for an archive.org item
 * Uses local caching API when available, falls back to archive.org
 */
let useLocalThumbnails = false; // Disabled by default - use archive.org directly for reliability

export function getThumbnailUrl(identifier) {
  if (!identifier) return '';

  // Use local caching API if enabled
  if (useLocalThumbnails) {
    return `/api/thumbnail.php?id=${encodeURIComponent(identifier)}`;
  }

  // Use archive.org directly (most reliable)
  return `https://archive.org/services/img/${identifier}`;
}

/**
 * Enable local thumbnail API
 */
export function enableLocalThumbnails() {
  useLocalThumbnails = true;
}

/**
 * Disable local thumbnail API (called when local API fails)
 */
export function disableLocalThumbnails() {
  useLocalThumbnails = false;
}

/**
 * Check if local thumbnail API is enabled
 */
export function isLocalThumbnailsEnabled() {
  return useLocalThumbnails;
}

export default {
  safeParseJSON,
  escapeHtml,
  sanitizeHtml,
  extractValue,
  formatRuntime,
  formatTime,
  formatFileSize,
  debounce,
  throttle,
  getThumbnailUrl,
  enableLocalThumbnails,
  disableLocalThumbnails,
  isLocalThumbnailsEnabled
};
