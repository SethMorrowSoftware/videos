<?php
/**
 * MailService
 *
 * Sends transactional email (verification, password reset, admin alerts).
 * No Composer → no PHPMailer. Two backends:
 *
 *   1. SMTP (preferred)    — see env vars below.
 *   2. PHP mail() fallback — used when SMTP_* vars aren't configured.
 *
 * Environment variables:
 *   MAIL_FROM              e.g. "noreply@yourdomain.com"
 *   MAIL_FROM_NAME         e.g. "Archive Film Club"
 *   SMTP_HOST              e.g. "smtp.example.com"
 *   SMTP_PORT              e.g. 587
 *   SMTP_USERNAME          SMTP auth username
 *   SMTP_PASSWORD          SMTP auth password
 *   SMTP_ENCRYPTION        "tls" (STARTTLS, default) or "ssl"
 *
 * Templates live as simple methods on this class — no templating engine.
 * If that starts feeling limiting, swap them for files under views/mail/.
 */
class MailService {

    /**
     * Send a password-reset email.
     */
    public function sendPasswordReset(array $user, string $rawToken): bool {
        $link = $this->baseUrl() . '/reset-password.php?token=' . urlencode($rawToken);
        $name = htmlspecialchars($user['display_name'] ?: $user['username']);
        $html = "<p>Hi $name,</p>
                 <p>Someone requested a password reset for your account on " . $this->siteName() . ".</p>
                 <p><a href=\"$link\">Reset your password</a> (link expires in 2 hours)</p>
                 <p>If this wasn't you, you can ignore this email.</p>";
        $text = "Hi $name,\n\n"
              . "Someone requested a password reset for your account.\n\n"
              . "Reset link: $link\n\n"
              . "(expires in 2 hours)\n\n"
              . "If this wasn't you, you can ignore this email.\n";

        return $this->send(
            $user['email'],
            'Reset your password',
            $html,
            $text
        );
    }

    /**
     * Send an email-verification email.
     */
    public function sendEmailVerification(array $user, string $rawToken): bool {
        $link = $this->baseUrl() . '/verify-email.php?token=' . urlencode($rawToken);
        $name = htmlspecialchars($user['display_name'] ?: $user['username']);
        $html = "<p>Welcome, $name!</p>
                 <p>Please confirm your email address for " . $this->siteName() . ".</p>
                 <p><a href=\"$link\">Verify email</a></p>";
        $text = "Welcome, $name!\n\n"
              . "Please confirm your email address.\n\n"
              . "Verification link: $link\n";

        return $this->send($user['email'], 'Verify your email', $html, $text);
    }

    // =====================================================
    // TRANSPORT
    // =====================================================

    /**
     * Send an HTML+text multipart email. Returns true on success.
     */
    public function send(string $to, string $subject, string $html, string $text): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("[MailService] Invalid recipient: $to");
            return false;
        }

        if ($this->hasSmtpConfig()) {
            try {
                return $this->sendViaSmtp($to, $subject, $html, $text);
            } catch (Throwable $e) {
                error_log('[MailService/SMTP] ' . $e->getMessage());
                // Fall through to mail() fallback
            }
        }

        return $this->sendViaMail($to, $subject, $html, $text);
    }

    private function hasSmtpConfig(): bool {
        return getenv('SMTP_HOST') && getenv('SMTP_PORT');
    }

    private function sendViaMail(string $to, string $subject, string $html, string $text): bool {
        $boundary = bin2hex(random_bytes(16));
        $from = $this->fromHeader();
        $headers = "From: $from\r\n"
                 . "Reply-To: $from\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=utf-8\r\n"
              . "Content-Transfer-Encoding: 7bit\r\n\r\n"
              . $text . "\r\n\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=utf-8\r\n"
              . "Content-Transfer-Encoding: 7bit\r\n\r\n"
              . $html . "\r\n\r\n"
              . "--$boundary--\r\n";

        return @mail($to, $subject, $body, $headers);
    }

    /**
     * Minimal SMTP client. Supports PLAIN/LOGIN auth and STARTTLS.
     * Not battle-hardened for high volume — if you send a lot of mail,
     * switch to a transactional service like Postmark or Mailgun via
     * their HTTP API (which also doesn't need Composer).
     */
    private function sendViaSmtp(string $to, string $subject, string $html, string $text): bool {
        $host = getenv('SMTP_HOST');
        $port = (int)getenv('SMTP_PORT');
        $username = getenv('SMTP_USERNAME') ?: '';
        $password = getenv('SMTP_PASSWORD') ?: '';
        $encryption = strtolower((string)(getenv('SMTP_ENCRYPTION') ?: 'tls'));

        $transport = $encryption === 'ssl' ? "ssl://$host" : $host;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 15);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 15);

        $read = function() use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function(string $cmd) use ($fp) {
            fwrite($fp, $cmd . "\r\n");
        };
        $expect = function(string $code) use ($read) {
            $response = $read();
            if (strpos($response, $code) !== 0) {
                throw new RuntimeException("SMTP unexpected response: $response");
            }
            return $response;
        };

        $expect('220');
        $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $write("EHLO $hostname");
        $expect('250');

        if ($encryption === 'tls') {
            $write('STARTTLS');
            $expect('220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS failed');
            }
            $write("EHLO $hostname");
            $expect('250');
        }

        if ($username !== '') {
            $write('AUTH LOGIN');
            $expect('334');
            $write(base64_encode($username));
            $expect('334');
            $write(base64_encode($password));
            $expect('235');
        }

        $from = getenv('MAIL_FROM') ?: ('noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $write("MAIL FROM:<$from>");
        $expect('250');
        $write("RCPT TO:<$to>");
        $expect('250');
        $write('DATA');
        $expect('354');

        $boundary = bin2hex(random_bytes(16));
        $fromHeader = $this->fromHeader();
        $headers = "From: $fromHeader\r\n"
                 . "To: <$to>\r\n"
                 . "Subject: $subject\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
              . $text . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=utf-8\r\n\r\n"
              . $html . "\r\n"
              . "--$boundary--\r\n";

        fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
        $expect('250');
        $write('QUIT');
        fclose($fp);

        return true;
    }

    private function fromHeader(): string {
        $from = getenv('MAIL_FROM') ?: ('noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $name = getenv('MAIL_FROM_NAME') ?: $this->siteName();
        return "\"" . addslashes($name) . "\" <$from>";
    }

    private function siteName(): string {
        return getenv('MAIL_FROM_NAME') ?: 'Archive Film Club';
    }

    private function baseUrl(): string {
        $envUrl = getenv('APP_URL');
        if ($envUrl) return rtrim($envUrl, '/');

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$scheme://$host";
    }
}
