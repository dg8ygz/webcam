<?php
declare(strict_types=1);

// Kleine globale Helfer, die schon vor dem App-Autoloader verfügbar sein müssen.
// Sie normalisieren/validieren öffentliche relative Pfade, schreiben kleine
// Runtime-Dateien atomar und bereinigen den eng erlaubten Admin-HTML-Inhalt;
// Netzwerk- oder DB-Zugriffe finden hier nicht statt.
if (!function_exists('webcam_document_relative_prefix')) {
  /**
   * Berechnet den dokumentrelativen Weg zurück zur Anwendungswurzel.
   *
   * Ein Pfad ohne abschließenden Slash bezeichnet eine Datei bzw. Route auf
   * der aktuellen Ebene; mit Slash ist er ein Verzeichnis und benötigt daher
   * eine zusätzliche "../"-Stufe. So funktionieren dieselben Templates unter
   * /webcam/, /testx/ und hinter einem vertrauenswürdigen Proxy-Prefix.
   */
  function webcam_document_relative_prefix(string $baseUrl, ?string $requestUri = null): string
  {
    $requestPathRaw = parse_url($requestUri ?? ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPathRaw = is_string($requestPathRaw) ? $requestPathRaw : '';
    $requestPath = trim($requestPathRaw, '/');
    $requestSegments = $requestPath === '' ? [] : explode('/', $requestPath);
    $basePathValue = trim($baseUrl, '/');
    $baseSegments = $basePathValue === '' ? [] : explode('/', $basePathValue);
    $depthDifference = max(0, count($requestSegments) - count($baseSegments));
    $endsWithSlash = $requestPathRaw !== '' && str_ends_with($requestPathRaw, '/');
    if (!$endsWithSlash && $depthDifference > 0) {
      $depthDifference -= 1;
    }
    return $depthDifference > 0 ? str_repeat('../', $depthDifference) : './';
  }
}

if (!function_exists('webcam_ensure_public_csrf_cookie')) {
  /**
   * Stellt das öffentliche Double-Submit-Cookie auch vor einem Page-Cache-Hit
   * bereit. Der Rückgabewert ist der vorhandene oder neu erzeugte Token.
   */
  function webcam_ensure_public_csrf_cookie(): string
  {
    static $tokenForRequest = null;
    if (is_string($tokenForRequest)) {
      return $tokenForRequest;
    }

    $cookieName = 'webcam_csrf';
    $existing = $_COOKIE[$cookieName] ?? null;
    if (is_string($existing) && preg_match('/^[a-f0-9]{32,64}$/', $existing)) {
      $token = $existing;
    } else {
      $token = bin2hex(random_bytes(32));
      $_COOKIE[$cookieName] = $token;
    }
    $tokenForRequest = $token;

    if (!headers_sent()) {
      $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
      setcookie($cookieName, $token, [
        'expires' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
      ]);
    }
    return $token;
  }
}

if (!function_exists('webcam_atomic_write_file')) {
  /**
   * Ersetzt eine kleine Runtime-Datei erst, nachdem der vollständige Inhalt in
   * einer Temp-Datei desselben Verzeichnisses geschrieben wurde.
   */
  function webcam_atomic_write_file(string $path, string $contents): bool
  {
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
      return false;
    }

    try {
      $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    } catch (Throwable) {
      return false;
    }

    $handle = @fopen($temporary, 'x+b');
    if (!is_resource($handle)) {
      return false;
    }

    $published = false;
    try {
      $length = strlen($contents);
      $written = 0;
      while ($written < $length) {
        $result = fwrite($handle, substr($contents, $written));
        if ($result === false || $result === 0) {
          return false;
        }
        $written += $result;
      }
      if (!fflush($handle)) {
        return false;
      }
      if (!fclose($handle)) {
        $handle = null;
        return false;
      }
      $handle = null;

      $existingMode = is_file($path) ? @fileperms($path) : false;
      $mode = is_int($existingMode) ? ($existingMode & 0777) : 0664;
      @chmod($temporary, $mode);
      if (!@rename($temporary, $path)) {
        return false;
      }
      $published = true;
      return true;
    } finally {
      if (is_resource($handle)) {
        fclose($handle);
      }
      if (!$published && is_file($temporary)) {
        @unlink($temporary);
      }
    }
  }
}

if (!function_exists('webcam_atomic_write_json')) {
  /** @param array<string|int,mixed> $payload */
  function webcam_atomic_write_json(string $path, array $payload, int $flags = 0): bool
  {
    try {
      $json = json_encode($payload, $flags | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
      return false;
    }
    return webcam_atomic_write_file($path, $json . PHP_EOL);
  }
}

if (!function_exists('webcam_trusted_proxy_list')) {
  /** @return list<string> */
  function webcam_trusted_proxy_list(string|false|null $configured): array
  {
    if (!is_string($configured) || trim($configured) === '') {
      return ['127.0.0.1', '::1'];
    }

    $proxies = array_values(array_filter(
      array_map('trim', preg_split('/[\s,]+/', $configured) ?: []),
      static fn(string $proxy): bool => $proxy !== ''
    ));
    return $proxies ?: ['127.0.0.1', '::1'];
  }
}

if (!function_exists('webcam_ip_matches_proxy')) {
  function webcam_ip_matches_proxy(string $ip, string $proxy): bool
  {
    $ip = trim($ip);
    $proxy = trim($proxy);
    if (filter_var($ip, FILTER_VALIDATE_IP) === false || $proxy === '') {
      return false;
    }
    if (!str_contains($proxy, '/')) {
      return filter_var($proxy, FILTER_VALIDATE_IP) !== false && $proxy === $ip;
    }

    [$network, $bits] = array_pad(explode('/', $proxy, 2), 2, '');
    if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($bits)) {
      return false;
    }
    $ipBytes = inet_pton($ip);
    $networkBytes = inet_pton($network);
    if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
      return false;
    }

    $bitCount = (int) $bits;
    $maxBits = strlen($ipBytes) * 8;
    if ($bitCount < 0 || $bitCount > $maxBits) {
      return false;
    }
    $fullBytes = intdiv($bitCount, 8);
    $remainingBits = $bitCount % 8;
    if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
      return false;
    }
    if ($remainingBits === 0) {
      return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
  }
}

if (!function_exists('webcam_remote_is_trusted_proxy')) {
  /** @param list<string> $trustedProxies */
  function webcam_remote_is_trusted_proxy(array $server, array $trustedProxies): bool
  {
    $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
    foreach ($trustedProxies as $proxy) {
      if (webcam_ip_matches_proxy($remote, (string) $proxy)) {
        return true;
      }
    }
    return false;
  }
}

if (!function_exists('webcam_trusted_forwarded_prefix')) {
  /** @param list<string> $trustedProxies */
  function webcam_trusted_forwarded_prefix(array $server, array $trustedProxies): string
  {
    if (!webcam_remote_is_trusted_proxy($server, $trustedProxies)) {
      return '';
    }

    $prefix = trim((string) ($server['HTTP_X_FORWARDED_PREFIX'] ?? ''));
    if ($prefix === '' || strlen($prefix) > 256 || preg_match('/[\x00-\x20?#\\\\]/', $prefix)) {
      return '';
    }
    $prefix = '/' . ltrim($prefix, '/');
    $segments = explode('/', trim($prefix, '/'));
    foreach ($segments as $segment) {
      if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._~-]+$/', $segment)) {
        return '';
      }
    }
    return rtrim($prefix, '/');
  }
}

if (!function_exists('Webcam\\support_collect')) {
  /**
   * @template TValue
   * @param iterable<TValue> $items
   * @return array<int, TValue>
   */
  function support_collect(iterable $items): array
  {
    if (is_array($items)) {
      return array_values($items);
    }
    $result = [];
    foreach ($items as $item) {
      $result[] = $item;
    }
    return $result;
  }
}

if (!function_exists('Webcam\\sanitize_admin_html')) {
  function sanitize_admin_html(string $html): string
  {
    $html = trim($html);
    if ($html === '') {
      return '';
    }

    $allowedTagList = [
      'p',
      'br',
      'strong',
      'em',
      'ul',
      'ol',
      'li',
      'a',
      'h2',
      'h3',
      'h4',
      'blockquote',
      'span',
      'div',
      'img',
    ];

    if (!class_exists('DOMDocument')) {
      $allowed = '<' . implode('><', $allowedTagList) . '>';
      $stripped = strip_tags($html, $allowed);
      // No DOMDocument: remove all attributes to avoid javascript: URLs.
      $stripped = preg_replace('/<([a-z0-9]+)(?:\\s[^>]*)?>/i', '<$1>', $stripped) ?? $stripped;
      return trim($stripped);
    }

    $allowedTags = [
      'p' => ['class'],
      'br' => [],
      'strong' => ['class'],
      'em' => ['class'],
      'ul' => ['class'],
      'ol' => ['class'],
      'li' => ['class'],
      'a' => ['href', 'title', 'target', 'rel', 'class'],
      'h2' => ['class'],
      'h3' => ['class'],
      'h4' => ['class'],
      'blockquote' => ['class'],
      'span' => ['class'],
      'div' => ['class'],
      'img' => ['src', 'alt', 'title', 'width', 'height', 'class'],
    ];
    $globalAttrs = ['class'];

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) {
      return '';
    }

    $sanitizeUrl = static function (string $value, array $allowedSchemes, bool $allowRelative = true): ?string {
      $value = trim($value);
      if ($value === '') {
        return null;
      }
      $collapsed = strtolower((string) preg_replace('/[\\x00-\\x20]+/', '', $value));
      if (preg_match('#^(javascript|data|vbscript):#', $collapsed)) {
        return null;
      }
      if (str_starts_with($value, '#')) {
        return $value;
      }
      if (str_starts_with($value, '//')) {
        return (in_array('http', $allowedSchemes, true) || in_array('https', $allowedSchemes, true)) ? $value : null;
      }
      if ($allowRelative && (str_starts_with($value, '/') || str_starts_with($value, './') || str_starts_with($value, '../'))) {
        return $value;
      }
      if ($allowRelative && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
        return $value;
      }
      $parts = parse_url($value);
      $scheme = strtolower((string) ($parts['scheme'] ?? ''));
      if ($scheme === '') {
        return $allowRelative ? $value : null;
      }
      return in_array($scheme, $allowedSchemes, true) ? $value : null;
    };

    $sanitizeNode = null;
    $sanitizeNode = static function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $globalAttrs, $sanitizeUrl): void {
      for ($child = $node->firstChild; $child !== null; ) {
        // Den ursprünglichen Nachbarn merken: Beim Entfernen eines Wrappers
        // werden dessen bereits geprüfte Kinder davor in den Elternknoten
        // verschoben. Danach kann die Schleife gefahrlos dort weiterlaufen.
        $next = $child->nextSibling;
        if ($child instanceof DOMElement) {
          $tag = strtolower($child->nodeName);
          if (!array_key_exists($tag, $allowedTags)) {
            // Unbekannte Elemente werden nicht einfach übersprungen. Zuerst
            // wird ihr kompletter Inhalt bereinigt, erst danach bleibt nur der
            // harmlose Text beziehungsweise erlaubtes HTML ohne den Wrapper.
            $sanitizeNode($child);
            while ($child->firstChild) {
              $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            $child = $next;
            continue;
          }

          $allowedAttrs = array_unique(array_merge($globalAttrs, $allowedTags[$tag]));
          if ($child->hasAttributes()) {
            foreach (iterator_to_array($child->attributes) as $attr) {
              $attrName = strtolower($attr->nodeName);
              if (!in_array($attrName, $allowedAttrs, true)) {
                $child->removeAttribute($attr->nodeName);
              }
            }
          }

          if ($tag === 'a') {
            $href = trim($child->getAttribute('href'));
            $safeHref = $sanitizeUrl($href, ['http', 'https', 'mailto', 'tel'], true);
            if ($safeHref === null) {
              // Der Link selbst ist unsicher. Sein sichtbarer Inhalt darf
              // erhalten bleiben, muss aber vor dem Entpacken ebenfalls durch
              // dieselbe rekursive Prüfung laufen.
              $sanitizeNode($child);
              while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
              }
              $node->removeChild($child);
              $child = $next;
              continue;
            }
            $child->setAttribute('href', $safeHref);
            if (!$child->hasAttribute('rel')) {
              $child->setAttribute('rel', 'noopener noreferrer');
            }
            if (!$child->hasAttribute('target')) {
              $child->setAttribute('target', '_blank');
            }
          }

          if ($tag === 'img') {
            $src = trim($child->getAttribute('src'));
            $safeSrc = $sanitizeUrl($src, ['http', 'https'], true);
            if ($safeSrc === null) {
              $node->removeChild($child);
              $child = $next;
              continue;
            }
            $child->setAttribute('src', $safeSrc);
            $child->removeAttribute('onload');
            $child->removeAttribute('onerror');
          }
        }

        if ($child->hasChildNodes()) {
          $sanitizeNode($child);
        }
        $child = $next;
      }
    };

    $sanitizeNode($root);

    $output = '';
    foreach ($root->childNodes as $child) {
      $output .= $doc->saveHTML($child);
    }

    return trim($output);
  }
}
