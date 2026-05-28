/**
 * URL Manager Utility
 * Handles URL state management, deep linking, and browser history
 */

export class UrlManager {
  /**
   * Update URL with new parameters
   */
  static updateUrl(params = {}, usePushState = false) {
    const url = new URL(window.location);
    url.search = '';
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, v);
      }
    });

    if (usePushState) {
      window.history.pushState({}, '', url);
    } else {
      window.history.replaceState({}, '', url);
    }
  }

  /**
   * Clear URL parameters and go to base path
   */
  static clearUrl() {
    window.history.pushState({}, '', window.location.pathname);
  }

  /**
   * Get current URL parameters
   */
  static getParams() {
    return new URLSearchParams(window.location.search);
  }

  /**
   * Get specific URL parameter
   */
  static getParam(name) {
    return this.getParams().get(name);
  }

  /**
   * Parse current URL for video/search state
   */
  static parseUrlState() {
    const params = this.getParams();

    return {
      videoId: params.get('video'),
      track: params.get('track') ? parseInt(params.get('track'), 10) - 1 : null,
      timestamp: params.get('t') ? parseInt(params.get('t'), 10) : null,
      // URLSearchParams.get() already returns a decoded value — decoding it
      // again throws URIError on any literal '%' and corrupts '+' sequences.
      // `|| null` preserves the previous "absent/empty → null" contract.
      search: params.get('search') || null,
      collection: params.get('collection'),
      page: params.get('page') ? parseInt(params.get('page'), 10) : 1
    };
  }

  /**
   * Build video URL for sharing
   */
  static buildVideoShareUrl(videoId, track = null) {
    let link = `${window.location.origin}${window.location.pathname}?video=${videoId}`;
    if (track !== null && track !== undefined) {
      link += `&track=${track + 1}`;
    }
    return link;
  }

  /**
   * Build search URL
   */
  static buildSearchUrl(options = {}) {
    const { search, collection, page } = options;
    const params = {};

    if (search) params.search = search;
    if (collection && collection !== 'all_videos') params.collection = collection;
    if (page && page > 1) params.page = String(page);

    return params;
  }

  /**
   * Check if current URL has a video parameter
   */
  static hasVideoParam() {
    return !!this.getParam('video');
  }

  /**
   * Check if current URL has search params
   */
  static hasSearchParams() {
    const params = this.getParams();
    return params.has('search') || params.has('collection');
  }
}

export default UrlManager;
