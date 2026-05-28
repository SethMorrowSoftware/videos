<?php
/**
 * 500 internal-server-error page. Wired up via ErrorDocument in .htaccess.
 *
 * Kept deliberately minimal -- this gets served when something in the
 * normal request path failed, so the LESS we depend on the better. No
 * DB access, no settings lookup, no JS.
 */
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex" />
  <title>Something went wrong</title>
  <style>
    html, body { margin: 0; padding: 0; background: #0a0a0b; color: #eee;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh; }
    main { min-height: 100vh; display: flex; align-items: center; justify-content: center;
      padding: 2rem 1rem; text-align: center; }
    .card { max-width: 32rem; }
    h1 { font-size: clamp(3rem, 12vw, 6rem); margin: 0 0 .5rem; color: #ff5050; line-height: 1; }
    h2 { font-size: 1.3rem; margin: 0 0 .75rem; }
    p { color: #8a8a92; margin: 0 0 1.25rem; line-height: 1.55; }
    a { display: inline-block; padding: .7em 1.3em; border-radius: 6px;
      background: #ff0000; color: #fff; text-decoration: none; font-weight: 500; }
    a:hover { background: #cc0000; }
  </style>
</head>
<body>
  <main>
    <div class="card">
      <h1>500</h1>
      <h2>Something went wrong on our end.</h2>
      <p>Sorry about that. The error has been logged. Please try again in a moment.</p>
      <a href="/">Back to home</a>
    </div>
  </main>
</body>
</html>
