/**
 * SearchService
 * Handles search queries, API calls, and result processing
 * Now uses local caching API with fallback to Archive.org
 */

import { CONFIG, COLLECTIONS } from '../config.js';

export class SearchService {
  constructor() {
    this.collections = COLLECTIONS;
    this.useLocalApi = true; // Try local API first
  }

  /**
   * Fetch with retry and timeout
   */
  async fetchWithRetry(url, options = {}, retries = 3) {
    for (let i = 0; i < retries; i++) {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), CONFIG.API_TIMEOUT);

        const response = await fetch(url, {
          ...options,
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (response.ok) return response;
        throw new Error(`HTTP ${response.status}`);

      } catch (error) {
        if (i === retries - 1) throw error;
        await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
      }
    }
  }

  /**
   * Build search query based on filters
   */
  buildSearchQuery(options = {}) {
    const {
      query = '*',
      collection = 'all_videos',
      publicDomain = false,
      collectionsOnly = false
    } = options;

    const searchQueryParts = [];
    const knownVideoCollections = Object.keys(this.collections).filter(id => id !== 'all_videos');

    if (collectionsOnly) {
      searchQueryParts.push('mediatype:collection AND collection:movies');

      if (knownVideoCollections.length > 0) {
        searchQueryParts.push(`identifier:(${knownVideoCollections.join(' OR ')})`);
      }
    } else {
      const videoQuery = 'mediatype:(movies OR video OR television)';
      const collectionQuery = knownVideoCollections.length > 0
        ? `(mediatype:collection AND identifier:(${knownVideoCollections.join(' OR ')}))`
        : '';

      if (collectionQuery) {
        searchQueryParts.push(`(${videoQuery} OR ${collectionQuery})`);
      } else {
        searchQueryParts.push(videoQuery);
      }
    }

    if (collection && collection !== 'all_videos') {
      searchQueryParts.push(`collection:(${collection})`);
    }

    if (query && query !== '*') {
      const clean = query.replace(/[:"()]/g, ' ').trim();
      if (clean) searchQueryParts.push(`(${clean})`);
    }

    if (publicDomain) {
      searchQueryParts.push(`(licenseurl:"http://creativecommons.org/publicdomain/mark/1.0/" OR licenseurl:"https://creativecommons.org/publicdomain/mark/1.0/" OR licenseurl:"http://creativecommons.org/publicdomain/")`);
    }

    return searchQueryParts.join(' AND ');
  }

  /**
   * Execute search using local caching API
   */
  async searchViaLocalApi(options = {}) {
    const {
      query = '*',
      page = 1,
      collection = 'all_videos',
      sortBy = 'relevance',
      publicDomain = false,
      collectionsOnly = false
    } = options;

    const searchQuery = this.buildSearchQuery({
      query,
      collection,
      publicDomain,
      collectionsOnly
    });

    const params = new URLSearchParams({
      q: searchQuery,
      page: String(page),
      rows: String(CONFIG.ITEMS_PER_PAGE),
      sort: sortBy
    });

    const response = await this.fetchWithRetry(`api/search.php?${params}`);
    const data = await response.json();

    // Check if API returned an error
    if (data.error) {
      throw new Error(data.error);
    }

    // Transform to match Archive.org format if needed
    return data;
  }

  /**
   * Execute search directly against Archive.org API (fallback)
   */
  async searchArchiveDirect(options = {}) {
    const {
      query = '*',
      page = 1,
      collection = 'all_videos',
      sortBy = 'relevance',
      publicDomain = false,
      collectionsOnly = false
    } = options;

    const searchQuery = this.buildSearchQuery({
      query,
      collection,
      publicDomain,
      collectionsOnly
    });

    const params = new URLSearchParams({
      q: searchQuery,
      output: 'json',
      rows: String(CONFIG.ITEMS_PER_PAGE),
      page: String(page)
    });

    // Add fields to return
    ['identifier', 'title', 'description', 'date', 'downloads', 'creator', 'runtime', 'licenseurl', 'subject', 'mediatype', 'num_items']
      .forEach(f => params.append('fl[]', f));

    // Add sorting
    if (sortBy && sortBy !== 'relevance') {
      switch (sortBy) {
        case 'date': params.append('sort[]', 'publicdate desc'); break;
        case 'downloads': params.append('sort[]', 'downloads desc'); break;
        case 'title': params.append('sort[]', 'titleSorter asc'); break;
        case 'creator': params.append('sort[]', 'creatorSorter asc'); break;
      }
    }

    const url = `https://archive.org/advancedsearch.php?${params}`;
    const response = await this.fetchWithRetry(url);
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    return response.json();
  }

  /**
   * Execute search against Archive.org API (with local cache fallback)
   */
  async searchArchive(options = {}) {
    // Try local caching API first
    if (this.useLocalApi) {
      try {
        return await this.searchViaLocalApi(options);
      } catch (error) {
        console.warn('Local API failed, falling back to Archive.org:', error.message);
        // Disable local API for this session if it's not available
        if (error.message.includes('404') || error.message.includes('Failed to fetch')) {
          this.useLocalApi = false;
        }
      }
    }

    // Fallback to direct Archive.org API
    return this.searchArchiveDirect(options);
  }

  /**
   * Search for a random video
   */
  async getRandomVideo() {
    const collections = Object.keys(this.collections).filter(c => c !== 'all_videos');
    const randomCollection = collections[Math.floor(Math.random() * collections.length)];
    const randomPage = Math.floor(Math.random() * 10) + 1;

    const data = await this.searchArchive({
      query: '*',
      page: randomPage,
      collection: randomCollection
    });

    const docs = data.response.docs.filter(d => d.mediatype !== 'collection');

    if (docs.length === 0) {
      throw new Error('No videos found');
    }

    return docs[Math.floor(Math.random() * docs.length)];
  }

  /**
   * Calculate pagination info
   */
  getPaginationInfo(numFound, currentPage) {
    const totalPages = Math.ceil(numFound / CONFIG.ITEMS_PER_PAGE);
    const start = (currentPage - 1) * CONFIG.ITEMS_PER_PAGE + 1;
    const end = Math.min(currentPage * CONFIG.ITEMS_PER_PAGE, numFound);

    return {
      totalPages,
      start,
      end,
      hasPrevious: currentPage > 1,
      hasNext: currentPage < totalPages
    };
  }

  /**
   * Get collection display name
   */
  getCollectionDisplayName(collectionId) {
    return this.collections[collectionId] || 'Unknown Collection';
  }

  /**
   * Add a dynamic collection
   */
  addCollection(id, name) {
    const displayName = name.length > 30 ? name.slice(0, 27) + '...' : name;
    this.collections[id] = displayName;
  }

  /**
   * Get all collections
   */
  getCollections() {
    return { ...this.collections };
  }

  /**
   * Get sorted collections for display
   */
  getSortedCollections() {
    return Object.entries(this.collections).sort((a, b) => {
      if (a[0] === 'all_videos') return -1;
      if (b[0] === 'all_videos') return 1;
      return a[1].localeCompare(b[1]);
    });
  }
}

export default SearchService;
