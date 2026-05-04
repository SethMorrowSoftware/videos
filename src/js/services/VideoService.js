/**
 * VideoService
 * Handles video playback, loading, metadata, and file selection
 * Now uses local caching API with fallback to Archive.org
 */

import { CONFIG } from '../config.js';
import { formatFileSize } from '../utils/helpers.js';

export class VideoService {
  constructor() {
    this.currentlyPlaying = null;
    this.videoControls = null;
    this.useLocalApi = true; // Try local API first
  }

  /**
   * Get video metadata from local caching API
   */
  async getMetadataViaLocalApi(id) {
    // Relative path so subdirectory deployments (e.g. /films/ on cPanel) work.
    const resp = await fetch(`api/metadata.php?id=${encodeURIComponent(id)}`);
    if (!resp.ok) throw new Error(`Failed to fetch metadata: ${resp.statusText}`);
    const payload = await resp.json();
    if (payload.error || payload.success === false) {
      throw new Error(payload.error || 'Metadata API returned failure');
    }
    // api/metadata.php wraps the normalized metadata under a `data` key
    // ({ success, cached, stale, data }). Unwrap it so downstream callers
    // (loadNativeVideo, player.js) see the metadata shape directly — the
    // same contract as getMetadataDirectFromArchive. Without this, the
    // player's file-list lookup finds nothing, loadNativeVideo throws,
    // and the page silently falls back to the iframe embed, which has
    // no playlist sidebar.
    return payload.data || payload;
  }

  /**
   * Get video metadata directly from Archive.org (fallback)
   */
  async getMetadataDirectFromArchive(id) {
    const resp = await fetch(`https://archive.org/metadata/${id}`);
    if (!resp.ok) throw new Error(`Failed to fetch metadata: ${resp.statusText}`);
    return resp.json();
  }

  /**
   * Get video metadata (with local cache fallback)
   */
  async getVideoMetadata(id) {
    // Try local caching API first
    if (this.useLocalApi) {
      try {
        return await this.getMetadataViaLocalApi(id);
      } catch (error) {
        console.warn('Local metadata API failed, falling back to Archive.org:', error.message);
        // Disable local API for this session if it's not available
        if (error.message.includes('404') || error.message.includes('Failed to fetch')) {
          this.useLocalApi = false;
        }
      }
    }

    // Fallback to direct Archive.org API
    return this.getMetadataDirectFromArchive(id);
  }

  /**
   * Get video files from metadata, filtering and sorting for playable files
   */
  getVideoFiles(metadata) {
    const files = metadata.files || (metadata.metadata && metadata.metadata.files) || [];

    if (!files.length) return [];

    // File extensions a browser <video> element can reasonably attempt.
    // .ia is an Archive.org placeholder/redirect — not directly playable.
    const PLAYABLE_EXTS = /\.(mp4|m4v|webm|ogv|ogg|mov|mpg|mpeg)$/i;
    // Common video formats that may need a transcoded derivative; we keep
    // them so the playlist surfaces them, but they may fail in the
    // browser and trigger our error-fallback path.
    const TOLERATED_EXTS = /\.(avi|flv|mkv|wmv)$/i;
    // Sentinel/auxiliary files Archive.org generates per item that we
    // never want in a playlist.
    const SENTINEL_PATTERNS = [
      /_meta\.(xml|sqlite|json)$/i,
      /_files\.(xml|json)$/i,
      /_reviews\.(xml|json)$/i,
      /_itemimage\./i,
      /__ia_thumb\./i,
      /_thumb\.(jpg|jpeg|png|gif|webp)$/i,
      /\.(torrent|srt|vtt|ass|ssa|json|xml|txt|pdf|doc|docx|sqlite|gz|zip|nfo|md5|asr|cue)$/i,
      /\.ia$/i,
    ];

    const videoFiles = files.filter(f => {
      const fmt = (f.format || '').toLowerCase();
      const name = (f.name || '');
      const lower = name.toLowerCase();
      const size = parseInt(f.size || 0, 10);

      if (!name) return false;

      // Reject sentinel/aux files first.
      for (const re of SENTINEL_PATTERNS) {
        if (re.test(lower)) return false;
      }

      // Reject by format hint.
      if (fmt.includes('metadata') || fmt.includes('text') ||
          fmt.includes('image') || fmt.includes('thumbnail') ||
          fmt.includes('archive bittorrent') || fmt.includes('json') ||
          fmt.includes('item tile') || fmt.includes('subtitles')) {
        return false;
      }

      const isPlayableExt = PLAYABLE_EXTS.test(lower);
      const isToleratedExt = TOLERATED_EXTS.test(lower);

      const isVideoFormat = fmt.includes('mp4') || fmt.includes('mpeg') ||
                            fmt.includes('video') || fmt.includes('h.264') ||
                            fmt.includes('h264') || fmt.includes('webm') ||
                            fmt.includes('ogv') || fmt.includes('ogg video') ||
                            fmt.includes('matroska') || fmt.includes('quicktime');

      // Require either a known playable/tolerated extension OR a video
      // format hint. Reject anything else even if it has a "reasonable"
      // size — a 200KB JSON manifest is not a video.
      if (!isPlayableExt && !isToleratedExt && !isVideoFormat) return false;

      // Drop files smaller than 100KB — usually placeholders/manifests.
      if (size > 0 && size < 100 * 1024) return false;

      return true;
    });

    return videoFiles.sort((a, b) => {
      const aIsMP4 = (a.name || '').toLowerCase().endsWith('.mp4');
      const bIsMP4 = (b.name || '').toLowerCase().endsWith('.mp4');

      if (aIsMP4 && !bIsMP4) return -1;
      if (!aIsMP4 && bIsMP4) return 1;

      const nameA = a.name || '';
      const nameB = b.name || '';
      return nameA.localeCompare(nameB, undefined, { numeric: true, sensitivity: 'base' });
    });
  }

  /**
   * Select the best quality video file from available options
   */
  selectBestQuality(files) {
    if (!files.length) return null;

    const mp4s = files.filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));

    if (mp4s.length > 0) {
      mp4s.sort((a, b) => (parseInt(b.size) || 0) - (parseInt(a.size) || 0));
      return mp4s[0];
    }

    return files[0];
  }

  /**
   * Strip extensions, quality markers, and known derivative suffixes from
   * a filename to expose the underlying "episode" identity. Used by both
   * dedup and the multi-episode detector — they MUST stay in sync,
   * otherwise the playlist can disagree with itself about whether an item
   * is a series or a single video.
   */
  normalizeBaseName(name) {
    if (!name) return '';
    let bn = name.replace(/\.[^.]+$/, ''); // strip final extension
    // Iteratively strip stacked derivative suffixes (e.g.,
    // `episode_archive_h264.mp4` -> `episode`).
    let prev;
    do {
      prev = bn;
      bn = bn
        .replace(/\.(mp4|m4v|webm|ogv|ogg|avi|mov|mkv|flv|wmv|mpg|mpeg|ia)$/i, '')
        .replace(/[_.-]\d{3,4}p$/i, '')      // _1080p / .720p
        .replace(/[_.-](?:archive|512kb|h264|h\.264|hd|sd)$/i, '');
    } while (bn !== prev);
    return bn.toLowerCase().trim();
  }

  /**
   * Check if there are multiple unique videos (not just quality variants)
   */
  hasMultipleUniqueVideos(videoFiles) {
    if (videoFiles.length <= 1) return false;
    const baseNames = new Set();
    videoFiles.forEach(f => baseNames.add(this.normalizeBaseName(f.name)));
    return baseNames.size > 1;
  }

  /**
   * Deduplicate video files for playlist display.
   * When the same episode exists in multiple formats / qualities,
   * keep only the best one (MP4 > other formats; largest size wins
   * within a format).
   */
  deduplicateVideoFiles(videoFiles) {
    if (videoFiles.length <= 1) return videoFiles;

    const fileGroups = new Map();
    videoFiles.forEach(file => {
      const baseName = this.normalizeBaseName(file.name);
      if (!fileGroups.has(baseName)) fileGroups.set(baseName, []);
      fileGroups.get(baseName).push(file);
    });

    const deduplicatedFiles = [];
    fileGroups.forEach(files => {
      if (files.length === 1) {
        deduplicatedFiles.push(files[0]);
        return;
      }
      const mp4s = files.filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));
      const others = files.filter(f => !(f.name || '').toLowerCase().endsWith('.mp4'));

      const bySizeDesc = (a, b) => (parseInt(b.size, 10) || 0) - (parseInt(a.size, 10) || 0);

      if (mp4s.length > 0) {
        mp4s.sort(bySizeDesc);
        deduplicatedFiles.push(mp4s[0]);
      } else {
        others.sort(bySizeDesc);
        deduplicatedFiles.push(others[0]);
      }
    });

    return deduplicatedFiles.sort((a, b) => {
      const nameA = a.name || '';
      const nameB = b.name || '';
      return nameA.localeCompare(nameB, undefined, { numeric: true, sensitivity: 'base' });
    });
  }

  /**
   * Map a filename extension to the MIME type to declare on <source>.
   * Returning null means "let the browser sniff from the bytes" — safer
   * than declaring the wrong type, which some browsers honor strictly.
   */
  guessMimeType(name) {
    const lower = (name || '').toLowerCase();
    if (lower.endsWith('.mp4') || lower.endsWith('.m4v')) return 'video/mp4';
    if (lower.endsWith('.webm')) return 'video/webm';
    if (lower.endsWith('.ogv') || lower.endsWith('.ogg')) return 'video/ogg';
    if (lower.endsWith('.mov')) return 'video/quicktime';
    if (lower.endsWith('.mpg') || lower.endsWith('.mpeg')) return 'video/mpeg';
    return null;
  }

  /**
   * Load a native HTML5 video element. If `specificFileName` is omitted,
   * picks the best-quality file across the whole list — fine for
   * single-video items, but multi-episode callers should always pass an
   * explicit filename so we don't load (e.g.) episode 7's HD MP4 just
   * because it happens to be the largest file in the metadata.
   */
  async loadNativeVideo(id, metadata, videoWrapper, specificFileName = null, userVolume = 1) {
    const actual = metadata.metadata ? metadata : { metadata, files: metadata.files };
    const videoFiles = this.getVideoFiles(actual);

    if (!videoFiles.length) {
      throw new Error('No playable video files found');
    }

    let selected;
    if (specificFileName) {
      selected = videoFiles.find(f => f.name === specificFileName);
      if (!selected) {
        selected = this.selectBestQuality(videoFiles);
      }
    } else {
      selected = this.selectBestQuality(videoFiles);
    }

    if (!selected) {
      throw new Error('No suitable video file found');
    }

    const url = `https://archive.org/download/${id}/${encodeURIComponent(selected.name)}`;
    const mime = this.guessMimeType(selected.name);

    if (!videoWrapper) {
      throw new Error('Video wrapper not found');
    }

    // Drop any old iframe (fallback player) or stale custom-controls block.
    const oldIframe = videoWrapper.querySelector('iframe.video-player');
    if (oldIframe) oldIframe.remove();
    const oldControls = videoWrapper.querySelector('.video-controls');
    if (oldControls) oldControls.remove();

    // Reuse an existing <video> when one already lives in the wrapper.
    // Creating a new element on every call (quality switch, track change)
    // would leak elements into the DOM, leave background fetches running
    // for the abandoned ones, and drop active fullscreen / volume state.
    let videoEl = videoWrapper.querySelector('video.video-element');
    const isNewElement = !videoEl;

    if (isNewElement) {
      // Build the element imperatively so we never inject the URL into HTML
      // (URLs come from Archive.org metadata which we don't trust to be
      // attribute-safe even after encodeURIComponent).
      videoEl = document.createElement('video');
      videoEl.className = 'video-element';
      videoEl.id = 'mainVideo';
      videoEl.controls = true;
      // `auto` lets the browser buffer enough to make seeking responsive
      // — `metadata` only fetched the moov atom, which forced a fresh
      // range request on every scrub and made the slider feel laggy.
      videoEl.preload = 'auto';
      videoEl.autoplay = true;
      videoEl.playsInline = true;
      videoWrapper.appendChild(videoEl);
    }

    // Swap the source. We rebuild the <source> child (rather than just
    // setting videoEl.src) because `.load()` only re-evaluates child
    // <source> elements when there is no `src` attribute on the video
    // itself.
    const wasMuted = videoEl.muted;
    videoEl.removeAttribute('src');
    videoEl.innerHTML = '';
    const sourceEl = document.createElement('source');
    sourceEl.src = url;
    if (mime) sourceEl.type = mime;
    videoEl.appendChild(sourceEl);
    videoEl.appendChild(document.createTextNode('Your browser does not support the video tag.'));

    if (!isNewElement) {
      try { videoEl.load(); } catch (e) { /* ignore */ }
      // The `autoplay` attribute only fires on fresh elements, so kick
      // playback explicitly when reusing. Swallow the rejection that
      // browsers throw when autoplay policies block the call — the user
      // can still hit play.
      const p = videoEl.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
      videoEl.muted = wasMuted;
    }

    this.videoControls = { video: videoEl };

    if (userVolume !== undefined) {
      videoEl.volume = userVolume;
    }

    return { metadata: actual.metadata, selectedFile: selected, videoFiles, videoElement: videoEl };
  }

  /**
   * Load video using Archive.org iframe embed (fallback)
   */
  loadIframeVideo(id, videoWrapper) {
    if (!videoWrapper) return;

    videoWrapper.innerHTML = '';
    const iframe = document.createElement('iframe');
    iframe.className = 'video-player';
    iframe.allowFullscreen = true;
    iframe.loading = 'lazy';
    iframe.title = 'Video player';
    iframe.src = `https://archive.org/embed/${id}?autoplay=1`;
    videoWrapper.appendChild(iframe);
  }

  /**
   * Get quality label from filename
   */
  getQualityLabel(filename) {
    const name = filename.toLowerCase();
    if (name.includes('1080p') || name.includes('1920x1080')) return 'HD 1080p';
    if (name.includes('720p') || name.includes('1280x720')) return 'HD 720p';
    if (name.includes('480p') || name.includes('854x480')) return 'SD 480p';
    if (name.includes('360p') || name.includes('640x360')) return 'SD 360p';
    if (name.includes('_h264')) return 'H.264';
    if (name.includes('_512kb')) return 'Medium Quality';
    if (name.includes('_archive')) return 'Archive Quality';
    if (name.includes('.mp4')) return 'MP4';
    return 'Standard';
  }

  /**
   * Get format label from file
   */
  getFormatLabel(file) {
    const format = (file.format || '').toLowerCase();
    const name = (file.name || '').toLowerCase();

    if (format.includes('webm') || name.includes('.webm')) return 'WebM';
    if (format.includes('ogv') || name.includes('.ogv')) return 'OGV';
    if (format.includes('avi') || name.includes('.avi')) return 'AVI';
    if (format.includes('mov') || name.includes('.mov')) return 'MOV';
    if (format.includes('mkv') || name.includes('.mkv')) return 'MKV';
    if (format.includes('flv') || name.includes('.flv')) return 'FLV';
    if (format.includes('wmv') || name.includes('.wmv')) return 'WMV';

    return format || 'Video';
  }

  /**
   * Get clean title from filename
   */
  getCleanTitle(filename, itemTitle) {
    if (!filename) return 'Untitled';
    let title = filename.replace(/\.[^/.]+$/, '');
    const id = itemTitle?.toLowerCase().replace(/[^a-z0-9]/g, '') || '';
    if (id && title.toLowerCase().startsWith(id)) title = title.slice(id.length);
    title = title.replace(/^[-_\s]+|[-_\s]+$/g, '').replace(/[-_]/g, ' ').replace(/\s+/g, ' ').trim();
    return title || 'Untitled';
  }

  /**
   * Build video URL for a specific file
   */
  getVideoUrl(id, fileName) {
    return `https://archive.org/download/${id}/${encodeURIComponent(fileName)}`;
  }

  /**
   * Get currently playing video ID
   */
  getCurrentlyPlaying() {
    return this.currentlyPlaying;
  }

  /**
   * Set currently playing video ID
   */
  setCurrentlyPlaying(id) {
    this.currentlyPlaying = id;
  }

  /**
   * Get video controls reference
   */
  getVideoControls() {
    return this.videoControls;
  }

  /**
   * Toggle play/pause on current video
   */
  togglePlayPause(playerContainer) {
    const v = playerContainer?.querySelector('video');
    if (v && v.src) {
      v.paused ? v.play() : v.pause();
    }
  }
}

export default VideoService;
