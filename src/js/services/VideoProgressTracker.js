/**
 * VideoProgressTracker Service
 * Tracks user's video watching progress for resume functionality
 */

import { CONFIG } from '../config.js';
import { safeParseJSON } from '../utils/helpers.js';

export class VideoProgressTracker {
  constructor() {
    this.progress = safeParseJSON(localStorage.getItem('videoProgress')) || {};
    this.cleanupOldProgress();
  }

  saveProgress(videoId, currentTime, duration) {
    if (!videoId || !duration) return;

    this.progress[videoId] = {
      currentTime,
      duration,
      percentage: (currentTime / duration) * 100,
      timestamp: Date.now()
    };

    try {
      localStorage.setItem('videoProgress', JSON.stringify(this.progress));
    } catch (e) {
      console.warn('Failed to save progress:', e);
    }
  }

  getProgress(videoId) {
    return this.progress[videoId] || null;
  }

  cleanupOldProgress() {
    const cutoff = Date.now() - (CONFIG.VIDEO_PROGRESS_CLEANUP_DAYS * 24 * 60 * 60 * 1000);
    let cleaned = false;

    Object.keys(this.progress).forEach(id => {
      const entry = this.progress[id];
      if (!entry || !entry.timestamp || entry.timestamp < cutoff) {
        delete this.progress[id];
        cleaned = true;
      }
    });

    if (cleaned) {
      try {
        localStorage.setItem('videoProgress', JSON.stringify(this.progress));
      } catch (e) {
        console.warn('Failed to cleanup progress:', e);
      }
    }
  }

  clearProgress(videoId) {
    if (videoId) {
      delete this.progress[videoId];
    } else {
      this.progress = {};
    }
    try {
      localStorage.setItem('videoProgress', JSON.stringify(this.progress));
    } catch (e) {
      console.warn('Failed to clear progress:', e);
    }
  }
}

export default VideoProgressTracker;
