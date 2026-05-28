<?php
/**
 * Shared site footer.
 *
 * Always-present attribution + reporting/legal links for a site that streams
 * third-party content from the Internet Archive. Mirrors the links in the
 * first-load disclaimer modal (partials/head-common.php) but is persistent, so
 * the "we don't host this — report to the Archive" posture is visible on every
 * page rather than only in the one-time modal. Styles live in styles.css
 * (.afc-footer), which every content page loads.
 */
?>
<footer class="afc-footer" role="contentinfo">
  <div class="afc-footer-inner">
    <p class="afc-footer-note">
      Films are streamed from the
      <a href="https://archive.org" target="_blank" rel="noopener">Internet Archive</a>.
      Archive Film Club does not host this content.
    </p>
    <nav class="afc-footer-links" aria-label="Reporting and legal">
      <a href="https://help.archive.org/help/problems-or-errors/" target="_blank" rel="noopener">Report a problem</a>
      <a href="https://help.archive.org/help/how-do-i-request-to-remove-something-from-archive-org/" target="_blank" rel="noopener">Request removal / DMCA</a>
      <a href="https://archive.org/about/terms.php" target="_blank" rel="noopener">Terms of Use</a>
    </nav>
  </div>
</footer>
