<?php
/**
 * Private RSS reader — self-contained index.php
 * ------------------------------------------------
 * All logic (backend + API) and UI (HTML/CSS/JS) in this single file.
 * Data is stored as JSON files in ./data/
 *
 * Requirements: PHP 7.4+ with ext-dom and ext-json. ext-curl is optional (fallback available).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // errors should never leak out as broken HTML/JSON

// ---------------------------------------------------------------------------
// CONFIGURATION
// ---------------------------------------------------------------------------

define('DATA_DIR', __DIR__ . '/data');
define('CACHE_DIR', DATA_DIR . '/cache');
define('FEEDS_FILE', DATA_DIR . '/feeds.json');
define('CACHE_TTL', 300);        // seconds before a feed is considered "stale" (5 min) — matches the auto-refresh
define('MAX_ITEMS_PER_FEED', 300); // cap on how many articles we store per feed
define('FETCH_TIMEOUT', 15);      // seconds
define('REFRESH_THROTTLE_SECONDS', 60); // min time between real curl refreshes (shared across all clients)
define('REFRESH_LOCK_FILE', DATA_DIR . '/last_refresh.txt');
define('SYNCPLAY_FILE', DATA_DIR . '/syncplay.json');

// ---------------------------------------------------------------------------
// BASIC FILE HANDLING
// ---------------------------------------------------------------------------

function ensure_data_dir(): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0775, true);
    }
    if (!file_exists(FEEDS_FILE)) {
        write_json_file(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    }
}

function read_json_file(string $path, $default = null) {
    if (!file_exists($path)) {
        return $default;
    }
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return $default;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $decoded = json_decode($raw ?: 'null', true);
    return $decoded === null ? $default : $decoded;
}

function write_json_file(string $path, $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $fh = fopen($tmp, 'w');
    flock($fh, LOCK_EX);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN);
    fclose($fh);
    rename($tmp, $path);
}

function load_feeds_db(): array {
    ensure_data_dir();
    $db = read_json_file(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    if (!isset($db['folders'])) $db['folders'] = [];
    if (!isset($db['feeds'])) $db['feeds'] = [];
    return $db;
}

function save_feeds_db(array $db): void {
    write_json_file(FEEDS_FILE, $db);
}

function cache_path(string $feedId): string {
    return CACHE_DIR . '/' . preg_replace('/[^a-f0-9]/', '', $feedId) . '.json';
}

function load_cache(string $feedId): ?array {
    return read_json_file(cache_path($feedId), null);
}

function save_cache(string $feedId, array $data): void {
    write_json_file(cache_path($feedId), $data);
}

// Serializes read-modify-write cycles on one feed's cache file so concurrent
// requests (mark_read, mark_all_read, refresh_feed) can't lose each other's updates.
function with_cache_lock(string $feedId, callable $fn) {
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0775, true);
    $lockPath = cache_path($feedId) . '.lock';
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) return $fn();
    flock($fh, LOCK_EX);
    $result = $fn();
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

// Atomic throttle: allows one real curl refresh (refresh_all) at most once
// per REFRESH_THROTTLE_SECONDS, regardless of how many clients/tabs call it
// simultaneously. Returns true and stamps the file with "now" if a fetch IS allowed,
// otherwise false (another client already fetched recently — the client still gets
// current JSON data from cache/tree, just no new curl calls are made).
function acquire_refresh_slot(): bool {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fh = fopen(REFRESH_LOCK_FILE, 'c+');
    if ($fh === false) return true; // if we can't lock: better an extra fetch than blocking everything
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $last = (int)trim($raw ?: '0');
    $now = time();
    if ($now - $last < REFRESH_THROTTLE_SECONDS) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return false;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)$now);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function gen_id(): string {
    return bin2hex(random_bytes(6));
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bad_request(string $msg): void {
    json_out(['error' => $msg], 400);
}

// ---------------------------------------------------------------------------
// HTTP FETCHING
// ---------------------------------------------------------------------------

function http_get(string $url): string {
    $ua = 'Mozilla/5.0 (compatible; PrivateRSSReader/1.0; +self-hosted)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => FETCH_TIMEOUT,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.8'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $errno !== 0) {
            throw new RuntimeException('Could not fetch URL: ' . ($err ?: 'unknown error'));
        }
        if ($httpCode >= 400) {
            throw new RuntimeException('Server responded with HTTP ' . $httpCode);
        }
        return $body;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => FETCH_TIMEOUT,
            'header' => "User-Agent: $ua\r\nAccept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.8\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Could not fetch URL (file_get_contents failed)');
    }
    return $body;
}

// Fetches multiple URLs in parallel via curl_multi (fallback: sequentially if curl is missing).
// Returns [url => ['body' => string|null, 'error' => string|null]].
function http_get_multi(array $urls): array {
    $urls = array_values(array_unique($urls));
    $results = [];

    if (empty($urls)) return $results;

    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $url) {
            try {
                $results[$url] = ['body' => http_get($url), 'error' => null];
            } catch (Throwable $e) {
                $results[$url] = ['body' => null, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    $ua = 'Mozilla/5.0 (compatible; PrivateRSSReader/1.0; +self-hosted)';
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => FETCH_TIMEOUT,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.8'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $url => $ch) {
        $body = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno !== 0 || $body === false) {
            $results[$url] = ['body' => null, 'error' => 'Could not fetch URL: ' . ($err ?: 'unknown error')];
        } elseif ($httpCode >= 400) {
            $results[$url] = ['body' => null, 'error' => 'Server responded with HTTP ' . $httpCode];
        } else {
            $results[$url] = ['body' => $body, 'error' => null];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function resolve_url(string $base, string $rel): string {
    if ($rel === '') return $base;
    if (parse_url($rel, PHP_URL_SCHEME) !== null) return $rel; // already absolute
    $baseParts = parse_url($base);
    if (!$baseParts) return $rel;
    $scheme = $baseParts['scheme'] ?? 'https';
    $host = $baseParts['host'] ?? '';
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    if (strpos($rel, '//') === 0) {
        return $scheme . ':' . $rel;
    }
    if (strpos($rel, '/') === 0) {
        return "$scheme://$host$port$rel";
    }
    $basePath = $baseParts['path'] ?? '/';
    $dir = substr($basePath, 0, strrpos($basePath, '/') + 1);
    return "$scheme://$host$port" . $dir . $rel;
}

// Try to find a feed URL if the user pasted a regular web page instead of the feed directly.
function discover_feed_url(string $html, string $baseUrl): ?string {
    if (preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            if (!preg_match('/type=["\'](application\/rss\+xml|application\/atom\+xml)["\']/i', $tag)) {
                continue;
            }
            if (preg_match('/href=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                $resolved = resolve_url($baseUrl, html_entity_decode($hrefMatch[1]));
                $scheme = parse_url($resolved, PHP_URL_SCHEME);
                if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true)) {
                    continue;
                }
                return $resolved;
            }
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// FEED PARSING (supports RSS 2.0/1.0 and Atom)
// ---------------------------------------------------------------------------

function xp_text(DOMXPath $xp, string $query, ?DOMNode $ctx = null): string {
    $nodes = $ctx ? $xp->query($query, $ctx) : $xp->query($query);
    if ($nodes === false || $nodes->length === 0) return '';
    return trim($nodes->item(0)->textContent);
}

function xp_html(DOMXPath $xp, DOMNode $ctx, array $queries): string {
    foreach ($queries as $q) {
        $nodes = $xp->query($q, $ctx);
        if ($nodes === false || $nodes->length === 0) continue;
        $node = $nodes->item(0);
        if ($node->hasChildNodes()) {
            $hasElementChild = false;
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) { $hasElementChild = true; break; }
            }
            $html = '';
            if ($hasElementChild) {
                // Real nested XML markup (e.g. Atom xhtml content) — serialize the actual node tree.
                foreach ($node->childNodes as $child) {
                    $html .= $node->ownerDocument->saveHTML($child);
                }
            } else {
                // Plain text or CDATA — feeds commonly store an HTML string here,
                // either CDATA-wrapped or entity-escaped. Either way the decoded
                // nodeValue IS the HTML source; saveHTML() would double-escape it.
                foreach ($node->childNodes as $child) {
                    $html .= $child->nodeValue;
                }
                // Some feed generators escape their HTML markup TWICE (once as
                // literal &lt;/&gt; inside the description text, then that text
                // gets XML-escaped again). After normal XML decoding above, what's
                // left still looks like "&lt;div...&gt;" — real tag chars, no "<".
                // Decode once more so it becomes actual markup instead of visible text.
                if (strpos($html, '<') === false && strpos($html, '&lt;') !== false) {
                    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
                }
            }
            $html = trim($html);
            if ($html !== '') return $html;
        }
        $text = trim($node->textContent);
        if ($text !== '') return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

/**
 * @return array{title:string, items:array<int,array{guid:string,title:string,link:string,date:string,content:string}>}
 */
function parse_feed_xml(string $xmlStr): array {
    $xmlStr = preg_replace('/^\xEF\xBB\xBF/', '', ltrim($xmlStr));
    $prevErrSetting = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xmlStr, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrSetting);

    if (!$ok || !$dom->documentElement) {
        throw new RuntimeException('Invalid or unrecognized feed XML');
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xp->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
    $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    $xp->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
    $xp->registerNamespace('media', 'http://search.yahoo.org/mrss/');

    $root = $dom->documentElement;
    $rootName = strtolower($root->localName ?? '');
    $items = [];
    $feedTitle = '';

    if ($rootName === 'feed') {
        // ---- Atom ----
        $feedTitle = xp_text($xp, 'atom:title', $root) ?: xp_text($xp, 'title', $root);
        $entries = $xp->query('atom:entry', $root);
        if ($entries->length === 0) $entries = $xp->query('entry', $root);

        foreach ($entries as $entry) {
            $title = xp_text($xp, 'atom:title', $entry) ?: xp_text($xp, 'title', $entry);
            $link = '';
            $linkNodes = $xp->query('atom:link', $entry);
            if ($linkNodes->length === 0) $linkNodes = $xp->query('link', $entry);
            foreach ($linkNodes as $ln) {
                $rel = $ln->getAttribute('rel');
                if ($rel === '' || $rel === 'alternate') { $link = $ln->getAttribute('href'); break; }
            }
            if ($link === '' && $linkNodes->length > 0) $link = $linkNodes->item(0)->getAttribute('href');

            $date = xp_text($xp, 'atom:updated', $entry) ?: xp_text($xp, 'atom:published', $entry)
                  ?: xp_text($xp, 'updated', $entry) ?: xp_text($xp, 'published', $entry);
            $content = xp_html($xp, $entry, ['atom:content', 'content', 'atom:summary', 'summary']);
            if ($content === '') {
                $desc = xp_text($xp, 'media:group/media:description', $entry) ?: xp_text($xp, 'media:description', $entry);
                if ($desc !== '') {
                    $content = nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'));
                }
            }
            $thumbNodes = $xp->query('media:group/media:thumbnail', $entry);
            if ($thumbNodes->length === 0) $thumbNodes = $xp->query('media:thumbnail', $entry);
            if ($thumbNodes->length > 0) {
                $thumbUrl = $thumbNodes->item(0)->getAttribute('url');
                if ($thumbUrl !== '') {
                    $content = '<p><img src="' . htmlspecialchars($thumbUrl, ENT_QUOTES) . '" alt=""></p>' . $content;
                }
            }
            $guid = xp_text($xp, 'atom:id', $entry) ?: xp_text($xp, 'id', $entry) ?: ($link ?: $title);

            if (stripos($title, '#short') !== false) continue;
            if (stripos($link, '/shorts') !== false) continue;

            $items[] = compact('guid', 'title', 'link', 'date', 'content');
        }
    } else {
        // ---- RSS 2.0 / RSS 1.0 (RDF) ----
        $channel = $xp->query('//channel')->item(0);
        $feedTitle = $channel ? xp_text($xp, 'title', $channel) : xp_text($xp, '//title');
        $itemNodes = $xp->query('//item');

        foreach ($itemNodes as $item) {
            $title = xp_text($xp, 'title', $item);
            $link = xp_text($xp, 'link', $item);
            $date = xp_text($xp, 'pubDate', $item) ?: xp_text($xp, 'dc:date', $item);
            $content = xp_html($xp, $item, ['content:encoded', 'description']);
            $guid = xp_text($xp, 'guid', $item) ?: ($link ?: $title);

            $items[] = compact('guid', 'title', 'link', 'date', 'content');
        }
    }

    return ['title' => trim($feedTitle), 'items' => $items];
}

// DOM-based allowlist sanitization of article HTML before display.
function sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $allowedTags = [
        'p' => [], 'br' => [], 'hr' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [],
        'blockquote' => [], 'pre' => [], 'code' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'span' => [], 'div' => [], 'sub' => [], 'sup' => [], 'small' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [],
        'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
        'video' => ['controls', 'poster', 'width', 'height'],
        'audio' => ['controls'],
        'source' => ['src', 'type'],
    ];

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $wrapped = '<?xml encoding="UTF-8"?><div id="__root__">' . $html . '</div>';
    $ok = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return '';

    $root = $dom->getElementsByTagName('div')->item(0);
    if (!$root || $root->getAttribute('id') !== '__root__') return '';

    sanitize_node_tree($root, $allowedTags);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return trim($out);
}

function is_safe_url(string $url, array $allowedSchemes): bool {
    $url = trim($url);
    if ($url === '') return false;
    // Browsers strip ASCII control chars before parsing scheme; without this a value
    // like "java\tscript:" shows no scheme here but still executes in the browser.
    $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
    if ($stripped === '') return false;
    if ($stripped[0] === '#' || $stripped[0] === '/') return true;
    $scheme = parse_url($stripped, PHP_URL_SCHEME);
    if ($scheme === null) return true;
    return in_array(strtolower($scheme), $allowedSchemes, true);
}

function sanitize_node_tree(DOMNode $node, array $allowedTags): void {
    $child = $node->firstChild;
    while ($child !== null) {
        $next = $child->nextSibling;

        if ($child->nodeType === XML_COMMENT_NODE) {
            $node->removeChild($child);
            $child = $next;
            continue;
        }

        if ($child->nodeType === XML_ELEMENT_NODE) {
            /** @var DOMElement $child */
            $tag = strtolower($child->nodeName);

            if ($tag === 'script' || $tag === 'style') {
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            if (!isset($allowedTags[$tag])) {
                sanitize_node_tree($child, $allowedTags);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            if ($child->hasAttributes()) {
                $toRemove = [];
                foreach ($child->attributes as $attr) {
                    $aname = strtolower($attr->name);
                    if (stripos($aname, 'on') === 0) { $toRemove[] = $attr->name; continue; }
                    if (!in_array($aname, $allowedTags[$tag], true)) { $toRemove[] = $attr->name; continue; }
                    if ($aname === 'href' && !is_safe_url($attr->value, ['http', 'https', 'mailto'])) {
                        $toRemove[] = $attr->name;
                    }
                    if ($aname === 'src' && !is_safe_url($attr->value, ['http', 'https'])) {
                        $toRemove[] = $attr->name;
                    }
                }
                foreach ($toRemove as $aname) $child->removeAttribute($aname);
            }

            if ($tag === 'a') {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            sanitize_node_tree($child, $allowedTags);
        }

        $child = $next;
    }
}

function normalize_timestamp(string $date): int {
    if ($date === '') return 0;
    $ts = strtotime($date);
    return $ts === false ? 0 : $ts;
}

/**
 * Fetches and parses a feed, merges with the previous cache (to preserve read status),
 * and writes a new cache file. Also updates feed metadata (title, last fetched, any error).
 */
function refresh_feed(array &$feedMeta, ?string $prefetchedBody = null, ?string $prefetchError = null): array {
    $url = $feedMeta['url'];
    try {
        if ($prefetchedBody !== null) {
            $body = $prefetchedBody;
        } elseif ($prefetchError !== null) {
            throw new RuntimeException($prefetchError);
        } else {
            $body = http_get($url);
        }
        try {
            $parsed = parse_feed_xml($body);
        } catch (Throwable $xmlErr) {
            $discovered = discover_feed_url($body, $url);
            if ($discovered === null) throw $xmlErr;
            $body = http_get($discovered);
            $parsed = parse_feed_xml($body);
            $feedMeta['url'] = $discovered; // save the actual feed URL we found
        }
    } catch (Throwable $e) {
        $feedMeta['error'] = $e->getMessage();
        $feedMeta['last_fetched'] = time();
        $cached = load_cache($feedMeta['id']);
        return $cached['items'] ?? [];
    }

    $newItems = with_cache_lock($feedMeta['id'], function () use ($feedMeta, $parsed) {
        $existing = load_cache($feedMeta['id']);
        $existingByGuid = [];
        if ($existing && isset($existing['items'])) {
            foreach ($existing['items'] as $it) {
                $existingByGuid[$it['guid']] = $it;
            }
        }

        $items = [];
        $seenGuids = [];
        foreach ($parsed['items'] as $it) {
            $ts = normalize_timestamp($it['date']);
            $prev = $existingByGuid[$it['guid']] ?? null;
            $items[] = [
                'id' => substr(md5($it['guid']), 0, 16),
                'guid' => $it['guid'],
                'title' => $it['title'] !== '' ? $it['title'] : '(No title)',
                'link' => is_safe_url($it['link'], ['http', 'https']) ? $it['link'] : '',
                'date' => $it['date'],
                'timestamp' => $ts,
                'content' => sanitize_html($it['content']),
                'read' => $prev['read'] ?? false,
            ];
            $seenGuids[$it['guid']] = true;
        }

        foreach ($existingByGuid as $guid => $prev) {
            if (!isset($seenGuids[$guid])) {
                $items[] = $prev;
            }
        }

        usort($items, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        if (count($items) > MAX_ITEMS_PER_FEED) {
            $items = array_slice($items, 0, MAX_ITEMS_PER_FEED);
        }

        save_cache($feedMeta['id'], ['items' => $items, 'fetched_at' => time()]);
        return $items;
    });

    $feedMeta['last_fetched'] = time();
    $feedMeta['error'] = null;
    if ($parsed['title'] !== '' && empty($feedMeta['custom_title'])) {
        $feedMeta['title'] = $parsed['title'];
    }

    return $newItems;
}

function feed_is_stale(array $feedMeta): bool {
    if (empty($feedMeta['last_fetched'])) return true;
    return (time() - $feedMeta['last_fetched']) > CACHE_TTL;
}

function get_items_for_feed(array &$feedMeta, bool $forceRefresh): array {
    if ($forceRefresh || feed_is_stale($feedMeta)) {
        return refresh_feed($feedMeta);
    }
    $cached = load_cache($feedMeta['id']);
    if ($cached === null) {
        return refresh_feed($feedMeta);
    }
    return $cached['items'];
}

function unread_count_for_feed(string $feedId): int {
    $cached = load_cache($feedId);
    if (!$cached || empty($cached['items'])) return 0;
    $n = 0;
    foreach ($cached['items'] as $it) if (empty($it['read'])) $n++;
    return $n;
}

function find_feed(array &$db, string $id): ?int {
    foreach ($db['feeds'] as $i => $f) {
        if ($f['id'] === $id) return $i;
    }
    return null;
}

function find_folder(array &$db, string $id): ?int {
    foreach ($db['folders'] as $i => $f) {
        if ($f['id'] === $id) return $i;
    }
    return null;
}

// ---------------------------------------------------------------------------
// API-ROUTER
// ---------------------------------------------------------------------------

if (isset($_GET['api'])) {
    $action = $_GET['api'];
    ensure_data_dir();

    // Syncplay har eget litet låsfilssystem — får aldrig vänta på feeds-låset,
    // eftersom det pollas ~var 1.5s av varje ansluten klient.
    if ($action === 'syncplay_get' || $action === 'syncplay_set') {
        $syncLockFh = @fopen(SYNCPLAY_FILE . '.lock', 'c+');
        if ($syncLockFh !== false) flock($syncLockFh, LOCK_EX);

        if ($action === 'syncplay_get') {
            $syncOut = read_json_file(SYNCPLAY_FILE, [
                'active' => false, 'video_id' => null, 'playing' => false,
                'position' => 0, 'server_time' => microtime(true), 'client_id' => null, 'rev' => 0,
            ]);
            $syncOut['now'] = microtime(true); // serverns klocka just NU, för klientens offset-uträkning
            json_out($syncOut);
        }

        $videoId = (string)($_POST['video_id'] ?? '');
        $active = ($_POST['active'] ?? '1') === '1';
        $playing = ($_POST['playing'] ?? '1') === '1';
        $position = (float)($_POST['position'] ?? 0);
        $clientId = (string)($_POST['client_id'] ?? '');
        $isHeartbeat = ($_POST['heartbeat'] ?? '0') === '1';
        if ($active && $videoId === '') bad_request('video_id required.');
        $syncData = read_json_file(SYNCPLAY_FILE, ['rev' => 0]);

        // Hjärtslag är ingen användarhandling. Kommer det efter att någon
        // annan klient tagit över (paus/seek/stäng från annan enhet) ska
        // det ignoreras — annars skriver den gamla "källan" sin gamla
        // status ovanpå den nya.
        if ($isHeartbeat && $clientId !== ($syncData['client_id'] ?? null)) {
            json_out(['ok' => true, 'rev' => $syncData['rev'] ?? 0]);
        }

        $syncData['active'] = $active;
        if ($active) $syncData['video_id'] = $videoId;
        $syncData['playing'] = $playing;
        $syncData['position'] = $position;
        $syncData['server_time'] = microtime(true);
        $syncData['client_id'] = $clientId;
        $syncData['rev'] = ($syncData['rev'] ?? 0) + 1;
        write_json_file(SYNCPLAY_FILE, $syncData);
        json_out(['ok' => true, 'rev' => $syncData['rev']]);
    }

    $feedsLockFh = @fopen(FEEDS_FILE . '.lock', 'c+');
    if ($feedsLockFh !== false) flock($feedsLockFh, LOCK_EX);
    $db = load_feeds_db();

    try {
        switch ($action) {

            case 'tree': {
                $folders = [];
                foreach ($db['folders'] as $folder) {
                    $folders[] = [
                        'id' => $folder['id'],
                        'name' => $folder['name'],
                    ];
                }
                $feeds = [];
                foreach ($db['feeds'] as $feed) {
                    $feeds[] = [
                        'id' => $feed['id'],
                        'title' => $feed['title'],
                        'url' => $feed['url'],
                        'folder_id' => $feed['folder_id'],
                        'error' => $feed['error'] ?? null,
                        'unread' => unread_count_for_feed($feed['id']),
                    ];
                }
                json_out(['folders' => $folders, 'feeds' => $feeds]);
            }

            case 'add_folder': {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') bad_request('The folder must have a name.');
                $db['folders'][] = ['id' => gen_id(), 'name' => $name];
                save_feeds_db($db);
                json_out(['ok' => true]);
            }

            case 'rename_folder': {
                $id = (string)($_POST['id'] ?? '');
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') bad_request('The name cannot be empty.');
                $idx = find_folder($db, $id);
                if ($idx === null) bad_request('Folder not found.');
                $db['folders'][$idx]['name'] = $name;
                save_feeds_db($db);
                json_out(['ok' => true]);
            }

            case 'reorder_folders': {
                $ids = array_filter(explode(',', (string)($_POST['ids'] ?? '')), fn($v) => $v !== '');
                $byId = [];
                foreach ($db['folders'] as $f) $byId[$f['id']] = $f;
                $reordered = [];
                foreach ($ids as $id) {
                    if (isset($byId[$id])) { $reordered[] = $byId[$id]; unset($byId[$id]); }
                }
                foreach ($byId as $rest) $reordered[] = $rest; // any remaining ones in existing order
                $db['folders'] = $reordered;
                save_feeds_db($db);
                json_out(['ok' => true]);
            }

            case 'delete_folder': {
                $id = (string)($_POST['id'] ?? '');
                $idx = find_folder($db, $id);
                if ($idx === null) bad_request('Folder not found.');
                array_splice($db['folders'], $idx, 1);
                foreach ($db['feeds'] as &$f) {
                    if ($f['folder_id'] === $id) $f['folder_id'] = null;
                }
                unset($f);
                save_feeds_db($db);
                json_out(['ok' => true]);
            }

            case 'add_feed': {
                $url = trim((string)($_POST['url'] ?? ''));
                $folderId = $_POST['folder_id'] ?? null;
                $folderId = ($folderId === '' || $folderId === 'null') ? null : $folderId;
                if ($url === '' || !preg_match('#^https?://#i', $url)) {
                    bad_request('Enter a valid http(s) URL.');
                }
                foreach ($db['feeds'] as $f) {
                    if ($f['url'] === $url) bad_request('That feed has already been added.');
                }

                $feed = [
                    'id' => gen_id(),
                    'title' => parse_url($url, PHP_URL_HOST) ?: $url,
                    'url' => $url,
                    'folder_id' => $folderId,
                    'custom_title' => false,
                    'last_fetched' => null,
                    'error' => null,
                ];

                $items = refresh_feed($feed);
                $db['feeds'][] = $feed;
                save_feeds_db($db);
                json_out(['ok' => true, 'feed' => $feed, 'item_count' => count($items)]);
            }

            case 'rename_feed': {
                $id = (string)($_POST['id'] ?? '');
                $title = trim((string)($_POST['title'] ?? ''));
                $url = trim((string)($_POST['url'] ?? ''));
                if ($title === '') bad_request('The name cannot be empty.');
                if ($url !== '' && !preg_match('#^https?://#i', $url)) bad_request('Enter a valid http(s) URL.');
                $idx = find_feed($db, $id);
                if ($idx === null) bad_request('Feed not found.');
                $db['feeds'][$idx]['title'] = $title;
                $db['feeds'][$idx]['custom_title'] = true;
                if ($url !== '' && $url !== $db['feeds'][$idx]['url']) {
                    foreach ($db['feeds'] as $f) {
                        if ($f['id'] !== $id && $f['url'] === $url) bad_request('That feed URL is already added.');
                    }
                    $db['feeds'][$idx]['url'] = $url;
                    $db['feeds'][$idx]['last_fetched'] = null;
                    $db['feeds'][$idx]['error'] = null;
                }
                save_feeds_db($db);
                json_out(['ok' => true]);
            }

            case 'move_feed': {
                $id = (string)($_POST['id'] ?? '');
                $folderId = $_POST['folder_id'] ?? null;
                $folderId = ($folderId === '' || $folderId === 'null') ? null : $folderId;
                $beforeId = (string)($_POST['before_id'] ?? '');
                $idx = find_feed($db, $id);
                if ($idx === null) bad_request('Feed not found.');
                $feed = $db['feeds'][$idx];
                $feed['folder_id'] = $folderId;
                array_splice($db['feeds'], $idx, 1);
                $insertAt = null;
                if ($beforeId !== '') {
                    foreach ($db['feeds'] as $i => $f) {
                        if ($f['id'] === $beforeId) { $insertAt = $i; break; }
                    }
                }
                if ($insertAt === null) {
                    $db['feeds'][] = $feed;
                } else {
                    array_splice($db['feeds'], $insertAt, 0, [$feed]);
                }
                save_feeds_db($db);
                json_out(['ok' => true]);
            }

            case 'delete_feed': {
                $id = (string)($_POST['id'] ?? '');
                $idx = find_feed($db, $id);
                if ($idx === null) bad_request('Feed not found.');
                array_splice($db['feeds'], $idx, 1);
                save_feeds_db($db);
                @unlink(cache_path($id));
                json_out(['ok' => true]);
            }

            case 'items': {
                $id = (string)($_GET['feed_id'] ?? '');
                $refresh = ($_GET['refresh'] ?? '0') === '1';
                $idx = find_feed($db, $id);
                if ($idx === null) bad_request('Feed not found.');
                $items = get_items_for_feed($db['feeds'][$idx], $refresh);
                save_feeds_db($db); // save any updated metadata (last_fetched/error/title)
                json_out([
                    'ok' => true,
                    'feed' => ['id' => $db['feeds'][$idx]['id'], 'title' => $db['feeds'][$idx]['title'], 'error' => $db['feeds'][$idx]['error']],
                    'items' => $items,
                ]);
            }

            case 'folder_items': {
                $folderId = (string)($_GET['folder_id'] ?? '');
                $refresh = ($_GET['refresh'] ?? '0') === '1';

                if ($folderId === 'all') {
                    $matchFeeds = $db['feeds'];
                } elseif ($folderId === 'root') {
                    $matchFeeds = array_values(array_filter($db['feeds'], fn($f) => $f['folder_id'] === null));
                } else {
                    $matchFeeds = array_values(array_filter($db['feeds'], fn($f) => $f['folder_id'] === $folderId));
                }

                $allItems = [];
                foreach ($matchFeeds as &$feedMeta) {
                    $idx = find_feed($db, $feedMeta['id']);
                    $items = get_items_for_feed($db['feeds'][$idx], $refresh);
                    foreach ($items as $it) {
                        $it['feed_id'] = $feedMeta['id'];
                        $it['feed_title'] = $feedMeta['title'];
                        $allItems[] = $it;
                    }
                }
                unset($feedMeta);
                save_feeds_db($db);

                usort($allItems, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
                json_out(['ok' => true, 'items' => $allItems]);
            }

            case 'mark_read': {
                $feedId = (string)($_POST['feed_id'] ?? '');
                $itemId = (string)($_POST['item_id'] ?? '');
                $read = ($_POST['read'] ?? '1') === '1';
                $found = with_cache_lock($feedId, function () use ($feedId, $itemId, $read) {
                    $cached = load_cache($feedId);
                    if ($cached === null) return false;
                    foreach ($cached['items'] as &$it) {
                        if ($it['id'] === $itemId) { $it['read'] = $read; break; }
                    }
                    unset($it);
                    save_cache($feedId, $cached);
                    return true;
                });
                if (!$found) bad_request('No cache for the feed.');
                json_out(['ok' => true]);
            }

            case 'mark_all_read': {
                $scope = (string)($_POST['scope'] ?? 'feed'); // feed | folder | all
                $targetId = (string)($_POST['id'] ?? '');

                $markFeedRead = function (string $feedId) {
                    with_cache_lock($feedId, function () use ($feedId) {
                        $cached = load_cache($feedId);
                        if ($cached !== null) {
                            foreach ($cached['items'] as &$it) $it['read'] = true;
                            unset($it);
                            save_cache($feedId, $cached);
                        }
                    });
                };

                if ($scope === 'feed') {
                    $markFeedRead($targetId);
                } else {
                    if ($scope === 'all') {
                        $matchFeeds = $db['feeds'];
                    } elseif ($targetId === 'root') {
                        $matchFeeds = array_filter($db['feeds'], fn($f) => $f['folder_id'] === null);
                    } else {
                        $matchFeeds = array_filter($db['feeds'], fn($f) => $f['folder_id'] === $targetId);
                    }
                    foreach ($matchFeeds as $f) {
                        $markFeedRead($f['id']);
                    }
                }
                json_out(['ok' => true]);
            }

            case 'refresh_all': {
                if (!acquire_refresh_slot()) {
                    // Another client already fetched recently (within REFRESH_THROTTLE_SECONDS).
                    // No new curl calls are made — the client fetches current data via 'tree' as usual.
                    json_out(['ok' => true, 'throttled' => true, 'results' => []]);
                }

                $urls = [];
                foreach ($db['feeds'] as $feedMeta) {
                    $urls[$feedMeta['url']] = true;
                }
                $fetched = http_get_multi(array_keys($urls));

                $results = [];
                foreach ($db['feeds'] as &$feedMeta) {
                    $res = $fetched[$feedMeta['url']] ?? ['body' => null, 'error' => 'Internal error: no fetch result'];
                    try {
                        refresh_feed($feedMeta, $res['body'], $res['error']);
                        $results[] = ['id' => $feedMeta['id'], 'ok' => empty($feedMeta['error'])];
                    } catch (Throwable $e) {
                        $results[] = ['id' => $feedMeta['id'], 'ok' => false, 'error' => $e->getMessage()];
                    }
                }
                unset($feedMeta);
                save_feeds_db($db);
                json_out(['ok' => true, 'throttled' => false, 'results' => $results]);
            }

            case 'search': {
                $q = trim((string)($_GET['q'] ?? ''));
                if ($q === '') json_out(['ok' => true, 'items' => []]);
                $qLower = mb_strtolower($q);
                $allItems = [];
                foreach ($db['feeds'] as $feedMeta) {
                    $cached = load_cache($feedMeta['id']);
                    if (!$cached || empty($cached['items'])) continue;
                    foreach ($cached['items'] as $it) {
                        $haystack = mb_strtolower($it['title'] . ' ' . strip_tags($it['content']));
                        if (mb_strpos($haystack, $qLower) !== false) {
                            $it['feed_id'] = $feedMeta['id'];
                            $it['feed_title'] = $feedMeta['title'];
                            $allItems[] = $it;
                        }
                    }
                }
                usort($allItems, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
                json_out(['ok' => true, 'items' => $allItems]);
            }

            default:
                bad_request('Unknown API action.');
        }
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
    exit;
}

// ---------------------------------------------------------------------------
// FRONTEND (HTML/CSS/JS)
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSS Reader</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22><rect width=%2216%22 height=%2216%22 rx=%223%22 fill=%22%23e3a857%22/><circle cx=%224.3%22 cy=%2211.7%22 r=%221.6%22 fill=%22%2314161c%22/><path d=%22M3 6.2a6.8 6.8 0 016.8 6.8%22 fill=%22none%22 stroke=%22%2314161c%22 stroke-width=%221.8%22 stroke-linecap=%22round%22/><path d=%22M3 2.3a10.7 10.7 0 0110.7 10.7%22 fill=%22none%22 stroke=%22%2314161c%22 stroke-width=%221.8%22 stroke-linecap=%22round%22/></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #14161c;
  --bg-panel: #191c24;
  --bg-elevated: #20242e;
  --bg-hover: #262b37;
  --border: #2a2f3b;
  --text: #e7e9ee;
  --text-dim: #949cb0;
  --text-faint: #5b6274;
  --accent: #e3a857;
  --accent-soft: rgba(227, 168, 87, 0.14);
  --accent-soft-strong: rgba(227, 168, 87, 0.28);
  --danger: #d9705f;
  --radius: 7px;
  --sans: 'Inter', system-ui, -apple-system, sans-serif;
  --serif: 'Source Serif 4', Georgia, serif;
  --mono: 'JetBrains Mono', ui-monospace, monospace;
}

* { box-sizing: border-box; }
html, body { height: 100%; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  font-size: 14px;
  overflow: hidden;
}
button, input, select { font-family: inherit; font-size: inherit; }
::selection { background: var(--accent-soft-strong); }
::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #333947; border-radius: 10px; border: 2px solid var(--bg); }
::-webkit-scrollbar-thumb:hover { background: #414859; }

a { color: var(--accent); }

:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

/* ---------- layout ---------- */
#app {
  display: flex;
  height: 100vh;
  width: 100vw;
}

#sidebar {
  width: 168px;
  min-width: 140px;
  max-width: 480px;
  flex-shrink: 0;
  background: var(--bg-panel);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
}

#resizer-v {
  width: 5px;
  flex-shrink: 0;
  cursor: col-resize;
  background: transparent;
  position: relative;
}
#resizer-v:hover, #resizer-v.dragging { background: var(--accent-soft); }

#main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  height: 100vh;
}

#article-list-pane {
  height: 45%;
  min-height: 80px;
  display: flex;
  flex-direction: column;
  border-bottom: 1px solid var(--border);
}

#resizer-h {
  height: 5px;
  cursor: row-resize;
  background: transparent;
}
#resizer-h:hover, #resizer-h.dragging { background: var(--accent-soft); }

#article-content-pane {
  flex: 1;
  min-height: 80px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* ---------- sidebar header ---------- */
.sidebar-header {
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
}
.sidebar-actions { display: flex; gap: 8px; }
.search-box {
  margin-top: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 6px 9px;
}
.search-box svg { flex-shrink: 0; color: var(--text-faint); }
.search-box input {
  border: none; background: transparent; color: var(--text);
  width: 100%; outline: none; font-size: 12.5px;
}
.search-box input::placeholder { color: var(--text-faint); }
.btn {
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text);
  border-radius: var(--radius);
  padding: 7px 12px;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background .12s, border-color .12s;
}
.btn:hover { background: var(--bg-hover); border-color: #3a4152; }
.btn:active { transform: translateY(1px); }
.btn.primary { background: var(--accent); color: #201405; border-color: var(--accent); }
.btn.primary:hover { background: #eab568; }
.btn.ghost { background: transparent; }
.btn.small { padding: 4px 8px; font-size: 11.5px; }
.btn.danger-text { color: var(--danger); }
.btn.icon-only { width: 30px; height: 30px; padding: 0; justify-content: center; flex-shrink: 0; }

/* ---------- tree ---------- */
#tree-scroll { flex: 1; overflow-y: auto; padding: 10px 8px 20px; }

.special-row, .tree-folder, .tree-feed {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 7px 8px;
  border-radius: var(--radius);
  cursor: pointer;
  color: var(--text-dim);
  position: relative;
  user-select: none;
  white-space: nowrap;
}
.special-row:hover, .tree-folder:hover, .tree-feed:hover { background: var(--bg-hover); color: var(--text); }
.special-row.active, .tree-folder.active, .tree-feed.active {
  background: var(--accent-soft);
  color: var(--text);
}
.special-row.active::before, .tree-feed.active::before {
  content: "";
  position: absolute; left: -8px; top: 4px; bottom: 4px; width: 3px;
  background: var(--accent); border-radius: 2px;
}

.tree-icon { flex-shrink: 0; opacity: .85; }
.tree-label { overflow: hidden; text-overflow: ellipsis; flex: 1; font-size: 13px; }
.unread-badge {
  font-family: var(--mono);
  font-size: 10.5px;
  color: var(--accent);
  background: var(--accent-soft);
  padding: 1px 6px;
  border-radius: 20px;
  flex-shrink: 0;
}
.tree-feed.error .tree-label { color: var(--danger); }
.tree-feed .fav { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }

.folder-group { margin-bottom: 2px; }
.folder-children {
  margin-left: 15px;
  padding-left: 10px;
  border-left: 1px solid var(--border);
}
.folder-children.collapsed { display: none; }
.chevron { transition: transform .12s; flex-shrink: 0; opacity: .6; }
.chevron.open { transform: rotate(90deg); }

.tree-item-actions {
  display: none;
  gap: 2px;
  position: absolute;
  right: 6px;
  background: var(--bg-elevated);
  border-radius: 6px;
}
.tree-folder:hover .tree-item-actions, .tree-feed:hover .tree-item-actions { display: flex; }

.tree-folder.dragging, .tree-feed.dragging { opacity: .4; }
.tree-folder.drop-into { background: var(--accent-soft); outline: 1px dashed var(--accent); }
.tree-folder.drop-above, .tree-feed.drop-above { box-shadow: inset 0 2px 0 0 var(--accent); }
.tree-folder.drop-below, .tree-feed.drop-below { box-shadow: inset 0 -2px 0 0 var(--accent); }
.icon-btn {
  border: none; background: transparent; color: var(--text-faint);
  cursor: pointer; padding: 3px; border-radius: 4px; display: flex;
}
.icon-btn:hover { color: var(--text); background: var(--bg-hover); }

.empty-sidebar {
  color: var(--text-faint);
  font-size: 12.5px;
  padding: 16px 10px;
  line-height: 1.6;
}

/* ---------- article list ---------- */
.pane-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.pane-title {
  font-weight: 600;
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pane-title .count { color: var(--text-faint); font-weight: 400; font-family: var(--mono); font-size: 12px; margin-left: 8px; }
.toolbar-actions { display: flex; gap: 6px; flex-shrink: 0; }

#article-list { flex: 1; overflow-y: auto; }
.article-row {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 9px 16px;
  border-bottom: 1px solid #1e2129;
  cursor: pointer;
  position: relative;
}
.article-row:hover { background: var(--bg-elevated); }
.article-row.selected { background: var(--accent-soft); }
.article-row.selected::before {
  content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--accent);
}
.unread-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--accent); flex-shrink: 0;
}
.article-row.is-read .unread-dot { background: transparent; }
.article-row-title {
  flex: 1; min-width: 0;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-size: 13.5px;
}
.article-row.is-read .article-row-title { color: var(--text-dim); font-weight: 400; }
.article-row:not(.is-read) .article-row-title { color: var(--text); font-weight: 600; }
.article-row-feed {
  color: var(--text-faint); font-size: 11.5px; font-family: var(--mono);
  flex-shrink: 0; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.article-row-date {
  color: var(--text-faint); font-size: 11.5px; font-family: var(--mono); flex-shrink: 0; width: 108px; text-align: right;
}

@media (max-width: 780px) {
  .article-row-title { flex: 6 1 0; }
  .article-row-feed { flex: 4 1 0; max-width: none; }
  .article-row-date { display: none; }
}

.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  height: 100%; color: var(--text-faint); text-align: center; padding: 40px; gap: 10px;
}
.empty-state h3 { color: var(--text-dim); font-weight: 600; margin: 0; font-size: 15px; }
.empty-state p { margin: 0; font-size: 13px; max-width: 320px; line-height: 1.6; }

/* ---------- article content ---------- */
#article-content-scroll { flex: 1; overflow-y: auto; }
#article-content-inner { max-width: 720px; margin: 0 auto; padding: 34px 40px 80px; }
.article-header h1 {
  font-family: var(--serif);
  font-size: 26px;
  font-weight: 600;
  line-height: 1.3;
  margin: 0 0 10px;
}
.article-header h1 a { color: var(--text); text-decoration: none; }
.article-header h1 a:hover { color: var(--accent); }
.article-meta {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--text-faint);
  margin-bottom: 26px;
  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.article-meta a { color: var(--text-dim); text-decoration: none; }
.article-meta a:hover { color: var(--accent); }
.article-body {
  font-family: var(--serif);
  font-size: 16.5px;
  line-height: 1.75;
  color: #d7dae1;
}
.article-body img, .article-body video { max-width: 100%; height: auto; border-radius: 6px; }
.article-body img { cursor: zoom-in; }
.article-body a { color: var(--accent); }
.article-body pre { background: var(--bg-elevated); padding: 12px; border-radius: 6px; overflow-x: auto; font-family: var(--mono); font-size: 13px; }
.article-body blockquote { border-left: 3px solid var(--border); margin: 0; padding-left: 16px; color: var(--text-dim); }

/* ---------- modal ---------- */
.modal-backdrop {
  position: fixed; inset: 0; background: rgba(10,11,15,0.6);
  display: flex; align-items: center; justify-content: center; z-index: 50;
}
.modal {
  background: var(--bg-panel); border: 1px solid var(--border); border-radius: 10px;
  width: 380px; max-width: 90vw; padding: 20px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.4);
}
.modal h2 { margin: 0 0 16px; font-size: 15px; font-weight: 600; }
.field { margin-bottom: 14px; }
.field label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 6px; }
.field input, .field select {
  width: 100%; background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text);
  border-radius: 6px; padding: 8px 10px;
}
.field input:focus, .field select:focus { border-color: var(--accent); }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.modal-error { color: var(--danger); font-size: 12.5px; margin-top: 8px; min-height: 16px; }

.toast {
  position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
  background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text);
  padding: 10px 18px; border-radius: 8px; font-size: 13px; z-index: 100;
  box-shadow: 0 10px 30px rgba(0,0,0,0.35);
}

/* ---------- context menu ---------- */
.ctx-menu {
  position: fixed; z-index: 200; min-width: 180px;
  background: var(--bg-panel); border: 1px solid var(--border); border-radius: 8px;
  padding: 4px; box-shadow: 0 10px 30px rgba(0,0,0,0.4);
}
.ctx-menu-item {
  padding: 8px 10px; font-size: 13px; color: var(--text); border-radius: 5px;
  cursor: pointer; white-space: nowrap;
}
.ctx-menu-item:hover { background: var(--bg-hover); }
.ctx-menu-sep { height: 1px; background: var(--border); margin: 4px 2px; }

.spinner {
  width: 14px; height: 14px; border-radius: 50%;
  border: 2px solid var(--text-faint); border-top-color: var(--accent);
  animation: spin .7s linear infinite; display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }

#btn-toggle-sidebar { display: none; }
#sidebar-backdrop { display: none; }

@media (max-width: 780px) {
  #sidebar {
    position: absolute; z-index: 20; height: 100%;
    top: 0; left: 0;
    transform: translateX(-100%);
    transition: transform .2s ease;
    box-shadow: 2px 0 16px rgba(0,0,0,.45);
  }
  #sidebar.open { transform: translateX(0); }
  #btn-toggle-sidebar { display: inline-flex; }
  #sidebar-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 19;
  }
  #sidebar-backdrop.open { display: block; }
}

#syncplay-overlay {
  position: fixed; inset: 0; z-index: 50;
  background: rgba(10, 11, 15, 0.88);
  display: flex; align-items: center; justify-content: center;
  padding: 4vh 4vw;
}
#syncplay-overlay .syncplay-box {
  width: 100%; max-width: 1100px; aspect-ratio: 16 / 9;
  position: relative; box-shadow: 0 20px 60px rgba(0,0,0,.5); border-radius: var(--radius);
  overflow: hidden; background: #000;
}
#syncplay-overlay .syncplay-box iframe { width: 100%; height: 100%; border: 0; }
#syncplay-overlay .syncplay-close {
  position: absolute; top: 10px; right: 10px; z-index: 5;
  width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
  background: rgba(20, 22, 28, 0.85); color: var(--text); border: 1px solid var(--border);
  border-radius: 50%; cursor: pointer; font-size: 16px; line-height: 1;
}
#syncplay-overlay .syncplay-close:hover { background: var(--bg-hover); }

#image-overlay {
  position: fixed; inset: 0; z-index: 60;
  background: rgba(10, 11, 15, 0.9);
  display: flex; align-items: center; justify-content: center;
  padding: 4vh 4vw;
  cursor: zoom-out;
}
#image-overlay img {
  max-width: 100%; max-height: 100%;
  border-radius: 4px;
  box-shadow: 0 20px 60px rgba(0,0,0,.5);
  cursor: default;
}
#image-overlay .image-overlay-close {
  position: absolute; top: 16px; right: 16px; z-index: 5;
  width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
  background: rgba(20, 22, 28, 0.85); color: var(--text); border: 1px solid var(--border);
  border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1;
}
#image-overlay .image-overlay-close:hover { background: var(--bg-hover); }
</style>
</head>
<body>

<div id="app">
  <div id="sidebar-backdrop"></div>
  <div id="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-actions">
        <button class="btn primary icon-only" id="btn-add-feed" title="Lägg till flöde"><svg width="14" height="14" viewBox="0 0 16 16"><circle cx="4" cy="12" r="1.6" fill="currentColor"/><path d="M2 6.5a7.5 7.5 0 017.5 7.5M2 2a12 12 0 0112 12" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M12 2v4M10 4h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></button>
        <button class="btn icon-only" id="btn-add-folder" title="Lägg till mapp"><svg width="14" height="14" viewBox="0 0 16 16"><path d="M1.5 3.5h4l1.5 2h7.5v7a1 1 0 01-1 1h-11a1 1 0 01-1-1v-8a1 1 0 011-1z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 7.5v4M6 9.5h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></button>
        <button class="btn ghost icon-only" id="btn-refresh-all" title="Uppdatera alla flöden"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><polyline points="15.33 2.67 15.33 6.67 11.33 6.67"/><polyline points="0.67 13.33 0.67 9.33 4.67 9.33"/><path d="M2.34 6a6 6 0 0 1 9.9-2.24L15.33 6.67M0.67 9.33L3.09 12.24A6 6 0 0 0 13.66 10"/></svg></button>
      </div>
      <div class="search-box">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="7" cy="7" r="5"/><path d="M11 11l3.5 3.5" stroke-linecap="round"/></svg>
        <input type="text" id="search-input" placeholder="Search articles…">
      </div>
    </div>
    <div id="tree-scroll"></div>
  </div>

  <div id="resizer-v"></div>

  <div id="main">
    <div id="article-list-pane">
      <div class="pane-toolbar">
        <button class="btn ghost icon-only" id="btn-toggle-sidebar" title="Visa flöden"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><path d="M2 4h12M2 8h12M2 12h12"/></svg></button>
        <div class="pane-title" id="list-title">Select a folder or feed<span class="count" id="list-count"></span></div>
        <div class="toolbar-actions">
          <button class="btn small ghost" id="btn-mark-all-read" style="display:none;">Mark all as read</button>
        </div>
      </div>
      <div id="article-list"></div>
    </div>

    <div id="resizer-h"></div>

    <div id="article-content-pane">
      <div id="article-content-scroll">
        <div class="empty-state" id="content-empty">
          <h3>No article selected</h3>
          <p>Click an article in the list above to read it here.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  const state = {
    folders: [],
    feeds: [],
    collapsed: JSON.parse(localStorage.getItem('rss_collapsed') || 'null') || {},
    selection: null,       // { type: 'all'|'root'|'folder'|'feed', id }
    items: [],
    selectedItemId: null,
  };

  const el = (sel) => document.querySelector(sel);
  const treeScroll = el('#tree-scroll');
  const articleList = el('#article-list');
  const searchInput = el('#search-input');
  const listTitle = el('#list-title');
  const listCount = el('#list-count');
  const contentScroll = el('#article-content-scroll');
  const btnMarkAllRead = el('#btn-mark-all-read');
  const btnToggleSidebar = el('#btn-toggle-sidebar');
  const sidebarBackdrop = el('#sidebar-backdrop');

  function esc(s) {
    return (s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function fmtDate(ts) {
    if (!ts) return '';
    const d = new Date(ts * 1000);
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    if (sameDay) return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
  }

  function fmtDateLong(ts) {
    if (!ts) return 'Unknown date';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
  }

  function toast(msg) {
    const t = document.createElement('div');
    t.className = 'toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3200);
  }

  async function api(action, opts = {}) {
    const { method = 'GET', params = {}, body = null } = opts;
    let url = '?api=' + encodeURIComponent(action);
    for (const k in params) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    const fetchOpts = { method };
    if (body) {
      const form = new URLSearchParams();
      for (const k in body) form.append(k, body[k] == null ? '' : body[k]);
      fetchOpts.body = form;
    }
    const res = await fetch(url, fetchOpts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Something went wrong.');
    return data;
  }

  // ---------------- Tree ----------------

  function saveCollapsed() {
    localStorage.setItem('rss_collapsed', JSON.stringify(state.collapsed));
  }

  let lastTreeSignature = null;

  function computeTreeSignature() {
    return JSON.stringify(state.folders.map(f => [f.id, f.name])) + '|' +
           JSON.stringify(state.feeds.map(f => [f.id, f.title, f.folder_id, f.error || null, f.url]));
  }

  function applyActiveSelection() {
    if (window.innerWidth <= 780) closeSidebarDrawer();
    treeScroll.querySelectorAll('.tree-folder.active, .tree-feed.active, .special-row.active').forEach(el => el.classList.remove('active'));
    if (!state.selection) return;
    if (state.selection.type === 'all') {
      treeScroll.querySelector('.special-row[data-id="all"]')?.classList.add('active');
    } else if (state.selection.type === 'folder') {
      treeScroll.querySelector(`.tree-folder[data-id="${state.selection.id}"]`)?.classList.add('active');
    } else if (state.selection.type === 'feed') {
      treeScroll.querySelector(`.tree-feed[data-id="${state.selection.id}"]`)?.classList.add('active');
    }
  }

  function setBadge(rowEl, count) {
    if (!rowEl) return;
    let badge = rowEl.querySelector(':scope > .unread-badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'unread-badge';
        const actions = rowEl.querySelector(':scope > .tree-item-actions');
        rowEl.insertBefore(badge, actions || null);
      }
      badge.textContent = count;
    } else if (badge) {
      badge.remove();
    }
  }

  const faviconLink = document.querySelector('link[rel="icon"]');
  const faviconBaseHref = faviconLink ? faviconLink.getAttribute('href') : '';

  function updateFaviconBadge(count) {
    if (!faviconLink) return;
    if (!count || count <= 0) {
      faviconLink.setAttribute('href', faviconBaseHref);
      return;
    }
    const label = count > 99 ? '99+' : String(count);
    const fontSize = label.length > 2 ? 5.2 : 7;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" rx="3" fill="#e3a857"/><circle cx="4.3" cy="11.7" r="1.6" fill="#14161c"/><path d="M3 6.2a6.8 6.8 0 016.8 6.8" fill="none" stroke="#14161c" stroke-width="1.8" stroke-linecap="round"/><path d="M3 2.3a10.7 10.7 0 0110.7 10.7" fill="none" stroke="#14161c" stroke-width="1.8" stroke-linecap="round"/><circle cx="8" cy="8" r="5.5" fill="#4caf50" stroke="#14161c" stroke-width="0.8"/><text x="8" y="8.2" text-anchor="middle" dominant-baseline="central" font-family="Arial, sans-serif" font-size="${fontSize}" font-weight="700" fill="#14161c">${label}</text></svg>`;
    faviconLink.setAttribute('href', 'data:image/svg+xml,' + encodeURIComponent(svg));
  }

  function updateUnreadBadges() {
    setBadge(treeScroll.querySelector('.special-row[data-id="all"]'), totalUnread());
    for (const folder of state.folders) {
      const children = state.feeds.filter(f => f.folder_id === folder.id);
      const unread = children.reduce((s, f) => s + (f.unread || 0), 0);
      setBadge(treeScroll.querySelector(`.tree-folder[data-id="${folder.id}"]`), unread);
    }
    for (const f of state.feeds) {
      setBadge(treeScroll.querySelector(`.tree-feed[data-id="${f.id}"]`), f.unread || 0);
    }
  }

  function applyTreeData(data) {
    state.folders = data.folders;
    state.feeds = data.feeds;
    const sig = computeTreeSignature();
    if (sig !== lastTreeSignature || !treeScroll.firstChild) {
      lastTreeSignature = sig;
      renderTree();
    } else {
      updateUnreadBadges();
      applyActiveSelection();
    }
    updateFaviconBadge(totalUnread());
  }

  async function loadTree() {
    const data = await api('tree');
    applyTreeData(data);
  }

  function totalUnread() {
    return state.feeds.reduce((sum, f) => sum + (f.unread || 0), 0);
  }

  function renderTree() {
    const rootFeeds = state.feeds.filter(f => !f.folder_id);
    let html = '';

    html += specialRow('all', 'All articles', totalUnread(), iconLayers());

    for (const folder of state.folders) {
      const children = state.feeds.filter(f => f.folder_id === folder.id);
      const unread = children.reduce((s, f) => s + (f.unread || 0), 0);
      const isOpen = !state.collapsed[folder.id];
      html += `<div class="folder-group">
        <div class="tree-folder ${state.selection?.type==='folder' && state.selection.id===folder.id ? 'active':''}" data-type="folder" data-id="${folder.id}" draggable="true">
          <svg class="chevron ${isOpen?'open':''}" width="10" height="10" viewBox="0 0 10 10"><path d="M2 1l5 4-5 4" stroke="currentColor" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="tree-icon">${iconFolder()}</span>
          <span class="tree-label">${esc(folder.name)}</span>
          ${unread > 0 ? `<span class="unread-badge">${unread}</span>` : ''}
          <span class="tree-item-actions">
            <button class="icon-btn" data-action="rename-folder" data-id="${folder.id}" title="Rename">${iconEdit()}</button>
            <button class="icon-btn" data-action="delete-folder" data-id="${folder.id}" title="Delete folder">${iconTrash()}</button>
          </span>
        </div>
        <div class="folder-children ${isOpen?'':'collapsed'}" data-folder-children="${folder.id}">
          ${children.map(f => feedRow(f)).join('') || '<div class="empty-sidebar">No feeds here yet.</div>'}
        </div>
      </div>`;
    }

    html += rootFeeds.map(f => feedRow(f)).join('');

    if (state.folders.length === 0 && state.feeds.length === 0) {
      html += `<div class="empty-sidebar">No feeds yet.<br>Click the <b>+</b> icon above to add your first RSS/Atom feed.</div>`;
    }

    treeScroll.innerHTML = html;
  }

  function feedRow(f) {
    const active = state.selection?.type === 'feed' && state.selection.id === f.id;
    let host = '';
    try { host = new URL(f.url).hostname; } catch (e) { host = ''; }
    const favicon = host ? `https://www.google.com/s2/favicons?domain=${encodeURIComponent(host)}&sz=32` : '';
    return `<div class="tree-feed ${active?'active':''} ${f.error ? 'error':''}" data-type="feed" data-id="${f.id}" draggable="true">
      ${favicon ? `<img class="fav" src="${favicon}" onerror="this.style.visibility='hidden'" alt="">` : `<span class="tree-icon">${iconFeed()}</span>`}
      <span class="tree-label" title="${esc(f.error || f.title)}">${esc(f.title)}</span>
      ${f.unread > 0 ? `<span class="unread-badge">${f.unread}</span>` : ''}
      <span class="tree-item-actions">
        <button class="icon-btn" data-action="rename-feed" data-id="${f.id}" title="Rename">${iconEdit()}</button>
        <button class="icon-btn" data-action="delete-feed" data-id="${f.id}" title="Delete feed">${iconTrash()}</button>
      </span>
    </div>`;
  }

  function specialRow(id, label, unread, icon) {
    const active = state.selection?.type === 'all' && id === 'all';
    return `<div class="special-row ${active?'active':''}" data-type="all" data-id="${id}">
      <span class="tree-icon">${icon}</span>
      <span class="tree-label">${label}</span>
      ${unread > 0 ? `<span class="unread-badge">${unread}</span>` : ''}
    </div>`;
  }

  function iconFolder() { return `<svg width="14" height="14" viewBox="0 0 16 16"><path d="M1.5 3.5h4l1.5 2h7.5v7a1 1 0 01-1 1h-11a1 1 0 01-1-1v-8a1 1 0 011-1z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>`; }
  function iconFeed() { return `<svg width="14" height="14" viewBox="0 0 16 16"><circle cx="4" cy="12" r="1.6" fill="currentColor"/><path d="M2 6.5a7.5 7.5 0 017.5 7.5M2 2a12 12 0 0112 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`; }
  function iconLayers() { return `<svg width="14" height="14" viewBox="0 0 16 16"><path d="M8 2l6.5 3.3L8 8.6 1.5 5.3 8 2z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M1.5 8.7L8 12l6.5-3.3M1.5 11.7L8 15l6.5-3.3" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>`; }
  function iconEdit() { return `<svg width="14" height="14" viewBox="0 0 16 16"><path d="M11 2l3 3-8 8-3.5 1 1-3.5 8-8z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>`; }
  function iconTrash() { return `<svg width="14" height="14" viewBox="0 0 16 16"><path d="M3 4.5h10M6 4.5V3a1 1 0 011-1h2a1 1 0 011 1v1.5M4.5 4.5l.6 8.5a1 1 0 001 1h3.8a1 1 0 001-1l.6-8.5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>`; }

  treeScroll.addEventListener('click', async (e) => {
    const actionBtn = e.target.closest('[data-action]');
    if (actionBtn) {
      e.stopPropagation();
      const action = actionBtn.dataset.action;
      const id = actionBtn.dataset.id;
      if (action === 'rename-folder') return renameFolder(id);
      if (action === 'delete-folder') return deleteFolder(id);
      if (action === 'rename-feed') return renameFeed(id);
      if (action === 'delete-feed') return deleteFeed(id);
      return;
    }

    const folderRow = e.target.closest('.tree-folder');
    if (folderRow) {
      const id = folderRow.dataset.id;
      const chevron = e.target.closest('.chevron');
      const childrenEl = treeScroll.querySelector(`[data-folder-children="${id}"]`);
      if (chevron || (state.selection?.type === 'folder' && state.selection.id === id)) {
        state.collapsed[id] = !state.collapsed[id];
        saveCollapsed();
        childrenEl.classList.toggle('collapsed', !!state.collapsed[id]);
        folderRow.querySelector('.chevron').classList.toggle('open', !state.collapsed[id]);
        if (chevron) return;
      }
      selectFolder(id, folderRow.querySelector('.tree-label').textContent);
      return;
    }

    const feedRowEl = e.target.closest('.tree-feed');
    if (feedRowEl) {
      selectFeed(feedRowEl.dataset.id);
      return;
    }

    const specialRowEl = e.target.closest('.special-row');
    if (specialRowEl) {
      selectAll();
    }
  });

  // ---------------- Drag & drop (folders/feeds) ----------------

  let dragged = null; // { type: 'folder'|'feed', id }

  function clearDropMarkers() {
    treeScroll.querySelectorAll('.drop-above, .drop-below, .drop-into').forEach(el => {
      el.classList.remove('drop-above', 'drop-below', 'drop-into');
    });
  }

  treeScroll.addEventListener('dragstart', (e) => {
    const row = e.target.closest('.tree-folder, .tree-feed');
    if (!row) return;
    dragged = { type: row.dataset.type, id: row.dataset.id };
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragged.id);
    row.classList.add('dragging');
  });

  treeScroll.addEventListener('dragend', () => {
    treeScroll.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    clearDropMarkers();
    dragged = null;
  });

  treeScroll.addEventListener('dragover', (e) => {
    if (!dragged) return;
    e.preventDefault();
    clearDropMarkers();

    const folderRow = e.target.closest('.tree-folder');
    const feedRow = e.target.closest('.tree-feed');

    if (dragged.type === 'feed' && folderRow && folderRow.dataset.id !== dragged.id) {
      folderRow.classList.add('drop-into');
      return;
    }
    if (feedRow && feedRow.dataset.id !== dragged.id && dragged.type === 'feed') {
      const rect = feedRow.getBoundingClientRect();
      const before = (e.clientY - rect.top) < rect.height / 2;
      feedRow.classList.add(before ? 'drop-above' : 'drop-below');
      return;
    }
    if (dragged.type === 'folder' && folderRow && folderRow.dataset.id !== dragged.id) {
      const rect = folderRow.getBoundingClientRect();
      const before = (e.clientY - rect.top) < rect.height / 2;
      folderRow.classList.add(before ? 'drop-above' : 'drop-below');
    }
  });

  treeScroll.addEventListener('drop', async (e) => {
    if (!dragged) return;
    e.preventDefault();
    clearDropMarkers();
    const { type, id } = dragged;
    dragged = null;

    const folderRow = e.target.closest('.tree-folder');
    const feedRow = e.target.closest('.tree-feed');

    try {
      if (type === 'feed') {
        if (folderRow && folderRow.dataset.id !== id) {
          await api('move_feed', { method: 'POST', body: { id, folder_id: folderRow.dataset.id } });
        } else if (feedRow && feedRow.dataset.id !== id) {
          const rect = feedRow.getBoundingClientRect();
          const before = (e.clientY - rect.top) < rect.height / 2;
          const parentFolder = feedRow.closest('[data-folder-children]');
          const targetFolderId = parentFolder ? parentFolder.dataset.folderChildren : null;
          const beforeId = before ? feedRow.dataset.id : (feedRow.nextElementSibling?.dataset.id || '');
          await api('move_feed', { method: 'POST', body: { id, folder_id: targetFolderId, before_id: beforeId } });
        } else {
          await api('move_feed', { method: 'POST', body: { id, folder_id: null } });
        }
      } else if (type === 'folder' && folderRow && folderRow.dataset.id !== id) {
        const rect = folderRow.getBoundingClientRect();
        const before = (e.clientY - rect.top) < rect.height / 2;
        const order = [...treeScroll.querySelectorAll('.tree-folder')].map(el => el.dataset.id);
        const draggedIdx = order.indexOf(id);
        if (draggedIdx !== -1) order.splice(draggedIdx, 1);
        let targetIdx = order.indexOf(folderRow.dataset.id);
        if (!before) targetIdx += 1;
        order.splice(targetIdx, 0, id);
        await api('reorder_folders', { method: 'POST', body: { ids: order.join(',') } });
      } else {
        return;
      }
      await loadTree();
    } catch (err) {
      toast('Could not move: ' + err.message);
    }
  });

  // ---------------- Selection & article list ----------------

  let searchDebounceTimer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchDebounceTimer);
    const q = searchInput.value.trim();
    if (q === '') {
      if (state.selection?.type === 'search') selectAll();
      return;
    }
    searchDebounceTimer = setTimeout(() => selectSearch(q), 300);
  });
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      searchInput.value = '';
      searchInput.blur();
      if (state.selection?.type === 'search') selectAll();
    }
  });

  async function selectSearch(query, opts = {}) {
    state.selection = { type: 'search', query };
    applyActiveSelection();
    listTitle.innerHTML = `Search: "${esc(query)}"`;
    btnMarkAllRead.style.display = 'none';
    await loadItems(() => api('search', { params: { q: query } }), opts);
  }

  async function selectAll(opts = {}) {
    searchInput.value = '';
    state.selection = { type: 'all', id: 'all' };
    applyActiveSelection();
    listTitle.innerHTML = 'All articles';
    btnMarkAllRead.style.display = 'inline-flex';
    btnMarkAllRead.onclick = () => markAllRead('all', 'all');
    await loadItems(() => api('folder_items', { params: { folder_id: 'all' } }), opts);
  }

  async function selectFolder(id, name, opts = {}) {
    searchInput.value = '';
    state.selection = { type: 'folder', id };
    applyActiveSelection();
    listTitle.innerHTML = esc(name);
    btnMarkAllRead.style.display = 'inline-flex';
    btnMarkAllRead.onclick = () => markAllRead('folder', id);
    await loadItems(() => api('folder_items', { params: { folder_id: id } }), opts);
  }

  async function selectFeed(id, opts = {}) {
    searchInput.value = '';
    state.selection = { type: 'feed', id };
    applyActiveSelection();
    const feed = state.feeds.find(f => f.id === id);
    listTitle.innerHTML = esc(feed ? feed.title : '');
    btnMarkAllRead.style.display = 'inline-flex';
    btnMarkAllRead.onclick = () => markAllRead('feed', id);
    await loadItems(async () => {
      const data = await api('items', { params: { feed_id: id } });
      return { items: data.items, error: data.feed?.error };
    }, opts);
  }

  let loadToken = 0;
  async function loadItems(fetcher, opts = {}) {
    const silent = !!opts.silent;
    const myToken = ++loadToken;
    if (!silent) {
      articleList.innerHTML = `<div class="empty-state"><span class="spinner"></span></div>`;
    }
    try {
      const data = await fetcher();
      if (myToken !== loadToken) return;
      state.items = data.items || [];
      listCount.textContent = state.items.length ? `${state.items.length} articles` : '';
      renderArticleList();
      if (data.error) toast('Could not refresh feed: ' + data.error);
      refreshUnreadCountsQuietly();
    } catch (err) {
      if (myToken !== loadToken) return;
      if (!silent) {
        articleList.innerHTML = `<div class="empty-state"><h3>Could not fetch</h3><p>${esc(err.message)}</p></div>`;
      }
    }
  }

  async function refreshUnreadCountsQuietly() {
    try {
      const data = await api('tree');
      applyTreeData(data);
    } catch (e) { /* silent error, not critical */ }
  }

  function articleRowHTML(it) {
    return `<div class="article-row ${it.read ? 'is-read' : ''} ${state.selectedItemId === it.id ? 'selected' : ''}" data-id="${it.id}" data-feed-id="${it.feed_id || state.selection.id}">
        <span class="unread-dot"></span>
        <span class="article-row-title">${esc(it.title)}</span>
        ${it.feed_title ? `<span class="article-row-feed">${esc(it.feed_title)}</span>` : ''}
        <span class="article-row-date">${fmtDate(it.timestamp)}</span>
      </div>`;
  }

  function renderArticleList() {
    if (state.items.length === 0) {
      articleList.innerHTML = `<div class="empty-state"><h3>No articles</h3><p>There's nothing to show here yet.</p></div>`;
      return;
    }

    // If the list previously showed a spinner/empty state (not article rows), start fresh.
    if (!articleList.querySelector('.article-row') && articleList.children.length) {
      articleList.innerHTML = '';
    }

    const existing = new Map();
    articleList.querySelectorAll('.article-row').forEach(el => existing.set(el.dataset.id, el));

    const tmp = document.createElement('div');
    let anchor = null;

    for (const it of state.items) {
      const id = String(it.id);
      tmp.innerHTML = articleRowHTML(it).trim();
      const freshEl = tmp.firstElementChild;
      let rowEl = existing.get(id);

      if (rowEl) {
        existing.delete(id);
        if (rowEl.outerHTML !== freshEl.outerHTML) {
          rowEl.className = freshEl.className;
          rowEl.innerHTML = freshEl.innerHTML;
        }
      } else {
        rowEl = freshEl;
      }

      const wantedNext = anchor ? anchor.nextSibling : articleList.firstChild;
      if (wantedNext !== rowEl) articleList.insertBefore(rowEl, wantedNext);
      anchor = rowEl;
    }

    existing.forEach(el => el.remove());
  }

  function selectItemById(id) {
    const item = state.items.find(it => it.id === id);
    if (!item) return;
    const feedId = item.feed_id || state.selection.id;
    state.selectedItemId = id;
    const wasUnread = !item.read;
    if (wasUnread) item.read = true;
    renderArticleList();
    showArticle(item, feedId);
    const rowEl = articleList.querySelector(`.article-row[data-id="${id}"]`);
    if (rowEl) rowEl.scrollIntoView({ block: 'nearest' });
    if (wasUnread) {
      api('mark_read', { method: 'POST', body: { feed_id: feedId, item_id: id, read: '1' } })
        .then(refreshUnreadCountsQuietly)
        .catch(() => {});
    }
  }

  articleList.addEventListener('click', (e) => {
    const row = e.target.closest('.article-row');
    if (!row) return;
    selectItemById(row.dataset.id);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;
    if (!state.items || state.items.length === 0) return;
    e.preventDefault();
    let idx = state.items.findIndex(it => it.id === state.selectedItemId);
    if (e.key === 'ArrowDown') {
      idx = idx === -1 ? 0 : Math.min(idx + 1, state.items.length - 1);
    } else {
      idx = idx === -1 ? 0 : Math.max(idx - 1, 0);
    }
    selectItemById(state.items[idx].id);
  });

  // ---------------- Right-click menu (article list) ----------------

  let ctxMenuEl = null;

  function closeCtxMenu() {
    if (ctxMenuEl) { ctxMenuEl.remove(); ctxMenuEl = null; }
  }
  document.addEventListener('click', closeCtxMenu);
  document.addEventListener('scroll', closeCtxMenu, true);
  window.addEventListener('blur', closeCtxMenu);

  articleList.addEventListener('contextmenu', (e) => {
    const row = e.target.closest('.article-row');
    if (!row) return;
    e.preventDefault();
    closeCtxMenu();

    const id = row.dataset.id;
    const feedId = row.dataset.feedId;
    const item = state.items.find(it => it.id === id);
    if (!item) return;

    ctxMenuEl = document.createElement('div');
    ctxMenuEl.className = 'ctx-menu';
    ctxMenuEl.innerHTML = `
      <div class="ctx-menu-item" data-act="open">Open in new tab</div>
      <div class="ctx-menu-item" data-act="copy">Copy link</div>
      <div class="ctx-menu-sep"></div>
      <div class="ctx-menu-item" data-act="unread">Mark as unread</div>
    `;
    document.body.appendChild(ctxMenuEl);
    const x = Math.min(e.clientX, window.innerWidth - ctxMenuEl.offsetWidth - 8);
    const y = Math.min(e.clientY, window.innerHeight - ctxMenuEl.offsetHeight - 8);
    ctxMenuEl.style.left = x + 'px';
    ctxMenuEl.style.top = y + 'px';

    ctxMenuEl.addEventListener('click', async (ev) => {
      const act = ev.target.closest('[data-act]')?.dataset.act;
      closeCtxMenu();
      if (act === 'open') {
        window.open(item.link, '_blank', 'noopener,noreferrer');
      } else if (act === 'copy') {
        try {
          await navigator.clipboard.writeText(item.link);
          toast('Link copied.');
        } catch (err) { toast('Could not copy the link.'); }
      } else if (act === 'unread') {
        item.read = false;
        renderArticleList();
        try {
          await api('mark_read', { method: 'POST', body: { feed_id: feedId, item_id: id, read: '0' } });
          refreshUnreadCountsQuietly();
        } catch (err) { toast('Could not mark as unread: ' + err.message); }
      }
    });
  });

  function extractYoutubeId(item) {
    const link = item.link || '';
    let m = link.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];
    const content = item.content || '';
    m = content.match(/youtube(?:-nocookie)?\.com\/embed\/([a-zA-Z0-9_-]{11})/);
    return m ? m[1] : null;
  }

  function showArticle(item, feedId) {
    const ytId = extractYoutubeId(item);
    contentScroll.innerHTML = `
      <div id="article-content-inner">
        <div class="article-header">
          <h1><a href="${esc(item.link)}" target="_blank" rel="noopener noreferrer">${esc(item.title)}</a></h1>
          <div class="article-meta">
            <span>${esc(item.feed_title || (state.feeds.find(f=>f.id===feedId)?.title) || '')}</span>
            <span>·</span>
            <span>${fmtDateLong(item.timestamp)}</span>
            <span>·</span>
            <a href="${esc(item.link)}" target="_blank" rel="noopener noreferrer">Open original ↗</a>
            ${ytId ? `<span>·</span><button class="btn small ghost" id="btn-syncplay" data-yt="${ytId}">▶ Syncplay</button>` : ''}
          </div>
        </div>
        <div class="article-body">${item.content || '<p style="color:var(--text-faint)">No content could be fetched for this article.</p>'}</div>
      </div>
    `;
    const syncBtn = document.getElementById('btn-syncplay');
    if (syncBtn) syncBtn.addEventListener('click', () => startSyncplay(syncBtn.dataset.yt));
  }

  contentScroll.addEventListener('click', (e) => {
    const img = e.target.closest('.article-body img');
    if (!img) return;
    openImageOverlay(img.src, img.alt || '');
  });

  function openImageOverlay(src, alt) {
    if (document.getElementById('image-overlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'image-overlay';
    overlay.innerHTML = `
      <button class="image-overlay-close" id="image-overlay-close-btn" title="Stäng">✕</button>
      <img src="${esc(src)}" alt="${esc(alt)}">
    `;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeImageOverlay(); });
    document.getElementById('image-overlay-close-btn').addEventListener('click', closeImageOverlay);
    const escHandler = (e) => { if (e.key === 'Escape') closeImageOverlay(); };
    overlay._escHandler = escHandler;
    document.addEventListener('keydown', escHandler);
  }

  function closeImageOverlay() {
    const overlay = document.getElementById('image-overlay');
    if (!overlay) return;
    if (overlay._escHandler) document.removeEventListener('keydown', overlay._escHandler);
    overlay.remove();
  }

  async function markAllRead(scope, id) {
    state.items.forEach(it => it.read = true);
    renderArticleList();
    try {
      await api('mark_all_read', { method: 'POST', body: { scope, id } });
      refreshUnreadCountsQuietly();
    } catch (e) { toast('Could not mark as read: ' + e.message); }
  }

  // ---------------- Modals ----------------

  function openModal(html) {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML = `<div class="modal">${html}</div>`;
    document.body.appendChild(backdrop);
    backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) backdrop.remove(); });
    const escHandler = (e) => {
      if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', escHandler); }
    };
    document.addEventListener('keydown', escHandler);
    const autoInput = backdrop.querySelector('input[autofocus]');
    if (autoInput) setTimeout(() => autoInput.select(), 0);
    return backdrop;
  }

  function folderOptions(selectedId) {
    return `<option value="">(no folder)</option>` + state.folders.map(f =>
      `<option value="${f.id}" ${f.id === selectedId ? 'selected' : ''}>${esc(f.name)}</option>`).join('');
  }

  el('#btn-add-feed').addEventListener('click', () => {
    const currentFolder = state.selection?.type === 'folder' ? state.selection.id : '';
    const backdrop = openModal(`
      <h2>Add feed</h2>
      <div class="field"><label>Web address (RSS/Atom, or the page's URL)</label><input type="text" id="m-url" placeholder="https://example.com/feed.xml" autofocus></div>
      <div class="field"><label>Folder</label><select id="m-folder">${folderOptions(currentFolder)}</select></div>
      <div class="modal-error" id="m-error"></div>
      <div class="modal-actions">
        <button class="btn ghost" id="m-cancel">Cancel</button>
        <button class="btn primary" id="m-submit">Add</button>
      </div>
    `);
    const submit = async () => {
      const url = backdrop.querySelector('#m-url').value.trim();
      const folder_id = backdrop.querySelector('#m-folder').value;
      const errEl = backdrop.querySelector('#m-error');
      const btn = backdrop.querySelector('#m-submit');
      if (!url) { errEl.textContent = 'Enter a URL.'; return; }
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Fetching…';
      try {
        await api('add_feed', { method: 'POST', body: { url, folder_id } });
        backdrop.remove();
        await loadTree();
        toast('Feed added.');
      } catch (e) {
        errEl.textContent = e.message;
        btn.disabled = false; btn.textContent = 'Add';
      }
    };
    backdrop.querySelector('#m-submit').addEventListener('click', submit);
    backdrop.querySelector('#m-cancel').addEventListener('click', () => backdrop.remove());
    backdrop.querySelector('#m-url').addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
  });

  el('#btn-add-folder').addEventListener('click', () => {
    const backdrop = openModal(`
      <h2>New folder</h2>
      <div class="field"><label>Name</label><input type="text" id="m-name" placeholder="e.g. News" autofocus></div>
      <div class="modal-error" id="m-error"></div>
      <div class="modal-actions">
        <button class="btn ghost" id="m-cancel">Cancel</button>
        <button class="btn primary" id="m-submit">Create</button>
      </div>
    `);
    const submit = async () => {
      const name = backdrop.querySelector('#m-name').value.trim();
      if (!name) { backdrop.querySelector('#m-error').textContent = 'Enter a name.'; return; }
      try {
        await api('add_folder', { method: 'POST', body: { name } });
        backdrop.remove();
        await loadTree();
      } catch (e) { backdrop.querySelector('#m-error').textContent = e.message; }
    };
    backdrop.querySelector('#m-submit').addEventListener('click', submit);
    backdrop.querySelector('#m-cancel').addEventListener('click', () => backdrop.remove());
    backdrop.querySelector('#m-name').addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
  });

  el('#btn-refresh-all').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    const original = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span>';
    try {
      const res = await api('refresh_all', { method: 'POST' });
      await loadTree();
      if (state.selection) reselectCurrent();
      toast(res.throttled ? 'Recently updated by another session — showing latest data.' : 'All feeds have been updated.');
    } catch (e) { toast('Error while refreshing: ' + e.message); }
    btn.disabled = false; btn.innerHTML = original;
  });

  function reselectCurrent() {
    if (!state.selection) return;
    if (state.selection.type === 'all') selectAll({ silent: true });
    else if (state.selection.type === 'folder') {
      const f = state.folders.find(x => x.id === state.selection.id);
      selectFolder(state.selection.id, f ? f.name : '', { silent: true });
    } else if (state.selection.type === 'feed') selectFeed(state.selection.id, { silent: true });
    else if (state.selection.type === 'search') selectSearch(state.selection.query, { silent: true });
  }

  async function renameFolder(id) {
    const folder = state.folders.find(f => f.id === id);
    const backdrop = openModal(`
      <h2>Rename folder</h2>
      <div class="field"><label>Name</label><input type="text" id="m-name" value="${esc(folder?.name || '')}" autofocus></div>
      <div class="modal-error" id="m-error"></div>
      <div class="modal-actions">
        <button class="btn ghost" id="m-cancel">Cancel</button>
        <button class="btn primary" id="m-submit">Save</button>
      </div>
    `);
    backdrop.querySelector('#m-submit').addEventListener('click', async () => {
      const name = backdrop.querySelector('#m-name').value.trim();
      if (!name) { backdrop.querySelector('#m-error').textContent = 'Enter a name.'; return; }
      try { await api('rename_folder', { method: 'POST', body: { id, name } }); backdrop.remove(); await loadTree(); }
      catch (e) { backdrop.querySelector('#m-error').textContent = e.message; }
    });
    backdrop.querySelector('#m-cancel').addEventListener('click', () => backdrop.remove());
  }

  async function deleteFolder(id) {
    const folder = state.folders.find(f => f.id === id);
    if (!confirm(`Delete the folder "${folder?.name || ''}"? The feeds in it will be moved to the root level.`)) return;
    await api('delete_folder', { method: 'POST', body: { id } });
    if (state.selection?.type === 'folder' && state.selection.id === id) { state.selection = null; listTitle.textContent = 'Select a folder or feed'; articleList.innerHTML=''; btnMarkAllRead.style.display='none'; }
    await loadTree();
  }

  async function renameFeed(id) {
    const feed = state.feeds.find(f => f.id === id);
    const backdrop = openModal(`
      <h2>Rename feed</h2>
      <div class="field"><label>Name</label><input type="text" id="m-name" value="${esc(feed?.title || '')}" autofocus></div>
      <div class="field"><label>Feed URL</label><input type="text" id="m-url" value="${esc(feed?.url || '')}"></div>
      <div class="modal-error" id="m-error"></div>
      <div class="modal-actions">
        <button class="btn ghost" id="m-cancel">Cancel</button>
        <button class="btn primary" id="m-submit">Save</button>
      </div>
    `);
    backdrop.querySelector('#m-submit').addEventListener('click', async () => {
      const title = backdrop.querySelector('#m-name').value.trim();
      const url = backdrop.querySelector('#m-url').value.trim();
      if (!title) { backdrop.querySelector('#m-error').textContent = 'Enter a name.'; return; }
      try { await api('rename_feed', { method: 'POST', body: { id, title, url } }); backdrop.remove(); await loadTree(); }
      catch (e) { backdrop.querySelector('#m-error').textContent = e.message; }
    });
    backdrop.querySelector('#m-cancel').addEventListener('click', () => backdrop.remove());
  }

  async function deleteFeed(id) {
    const feed = state.feeds.find(f => f.id === id);
    if (!confirm(`Delete the feed "${feed?.title || ''}"? This cannot be undone.`)) return;
    await api('delete_feed', { method: 'POST', body: { id } });
    if (state.selection?.type === 'feed' && state.selection.id === id) { state.selection = null; listTitle.textContent = 'Select a folder or feed'; articleList.innerHTML=''; btnMarkAllRead.style.display='none'; }
    await loadTree();
  }

  // ---------------- Mobile drawer ----------------

  function openSidebarDrawer() {
    el('#sidebar').classList.add('open');
    sidebarBackdrop.classList.add('open');
  }
  function closeSidebarDrawer() {
    el('#sidebar').classList.remove('open');
    sidebarBackdrop.classList.remove('open');
  }
  btnToggleSidebar.addEventListener('click', openSidebarDrawer);
  sidebarBackdrop.addEventListener('click', closeSidebarDrawer);

  // ---------------- Resizers ----------------

  (function setupResizers() {
    const sidebar = el('#sidebar');
    const resizerV = el('#resizer-v');
    const savedWidth = localStorage.getItem('rss_sidebar_width');
    if (savedWidth) sidebar.style.width = savedWidth + 'px';
    let draggingV = false;
    resizerV.addEventListener('mousedown', (e) => { e.preventDefault(); draggingV = true; resizerV.classList.add('dragging'); document.body.style.cursor='col-resize'; document.body.style.userSelect='none'; });
    window.addEventListener('mousemove', (e) => {
      if (draggingV) {
        const w = Math.min(480, Math.max(140, e.clientX));
        sidebar.style.width = w + 'px';
      }
    });
    window.addEventListener('mouseup', () => {
      if (draggingV) localStorage.setItem('rss_sidebar_width', sidebar.offsetWidth);
      draggingV = false; resizerV.classList.remove('dragging'); document.body.style.cursor=''; document.body.style.userSelect='';
    });

    const listPane = el('#article-list-pane');
    const resizerH = el('#resizer-h');
    const savedHeight = localStorage.getItem('rss_list_pane_height');
    if (savedHeight) listPane.style.height = savedHeight + 'px';
    let draggingH = false;
    resizerH.addEventListener('mousedown', (e) => { e.preventDefault(); draggingH = true; resizerH.classList.add('dragging'); document.body.style.cursor='row-resize'; document.body.style.userSelect='none'; });
    window.addEventListener('mousemove', (e) => {
      if (draggingH) {
        const mainTop = el('#main').getBoundingClientRect().top;
        const h = Math.min(window.innerHeight - mainTop - 100, Math.max(80, e.clientY - mainTop));
        listPane.style.height = h + 'px';
      }
    });
    window.addEventListener('mouseup', () => {
      if (draggingH) localStorage.setItem('rss_list_pane_height', listPane.offsetHeight);
      draggingH = false; resizerH.classList.remove('dragging'); document.body.style.cursor=''; document.body.style.userSelect='';
    });
  })();

  // ---------------- Init ----------------

  loadTree().then(() => {
    // Open "All articles" as the start view if there are feeds.
    if (state.feeds.length > 0) selectAll();
  }).catch(err => {
    treeScroll.innerHTML = `<div class="empty-sidebar">Could not load: ${esc(err.message)}</div>`;
  });

  // ---------------- Syncplay (YouTube, synced across connected clients) ----------------

  const syncplayClientId = Math.random().toString(36).slice(2) + Date.now().toString(36);
  let syncplayRev = -1;
  let syncplayPlayer = null;
  let syncplayReady = false;
  let syncplaySuppressUntil = 0; // ignorera lokala onStateChange-eko från våra egna seeks tills denna tidpunkt
  let syncplayCorrectionTargetState = null; // vilket state EKOT av vår senaste rättning förväntas landa i — skiljer äkta eko från en ny, äkta åtgärd i samma fönster
  let syncplayLocalActionUntil = 0; // vi agerade precis själva (play/paus/seek/stäng) — hindra en redan pågående poll med gammal data från att slåss mot vår färska ändring
  let syncplayYtApiPromise = null;
  let syncplayClockOffset = null; // uppskattad (server_time - klienttid), sekunder
  let syncplayPendingState = null; // senaste fjärr-state innan spelaren var redo
  let syncplayCurrentVideoId = null; // vilket videoId som är öppet just nu — används av heartbeat
  let syncplayLastServerClientId = null; // client_id från senaste poll — vem som är "källa" just nu
  let syncplayEstimatedOneWayLatencyMs = 50; // rullande skattning, halva RTT
  let syncplayNudging = false; // hysteres: undviker att farten studsar runt tröskeln
  let syncplayManualOffsetMs = parseInt(localStorage.getItem('rss_syncplay_offset_ms') || '0', 10) || 0; // fast kalibrering — kompenserar skärmens egen avkodnings-/panellatens, inte nätverksdrift

  function loadYoutubeApi() {
    if (syncplayYtApiPromise) return syncplayYtApiPromise;
    syncplayYtApiPromise = new Promise((resolve) => {
      if (window.YT && window.YT.Player) { resolve(); return; }
      const prevCb = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = () => { if (prevCb) prevCb(); resolve(); };
      const tag = document.createElement('script');
      tag.src = 'https://www.youtube.com/iframe_api';
      document.head.appendChild(tag);
    });
    return syncplayYtApiPromise;
  }

  function syncplayExpectedPosition(data) {
    const clientNow = Date.now() / 1000;
    const serverNow = syncplayClockOffset === null ? data.now : clientNow + syncplayClockOffset;
    const elapsed = data.playing ? Math.max(0, serverNow - data.server_time) : 0;
    // Manuell offset kompenserar FAST latens i just DEN HÄR skärmens
    // avkodnings-/panelkedja (t.ex. TV-processing) — sånt koden aldrig kan
    // mäta, för det händer efter att bilden lämnat webbläsaren.
    return data.position + elapsed + (syncplayManualOffsetMs / 1000);
  }

  function syncplayFormatOffset(ms) {
    if (ms === 0) return '0 ms';
    return (ms > 0 ? '+' : '') + ms + ' ms';
  }

  function syncplayAdjustOffset(deltaMs) {
    syncplayManualOffsetMs = Math.max(-1000, Math.min(1000, syncplayManualOffsetMs + deltaMs));
    localStorage.setItem('rss_syncplay_offset_ms', String(syncplayManualOffsetMs));
    const label = document.getElementById('syncplay-offset-label');
    if (label) label.textContent = syncplayFormatOffset(syncplayManualOffsetMs);
  }

  let syncplayLastSeekAt = 0;

  // Körs på VARJE poll (inte bara vid ny rev) så drift korrigeras löpande
  // under uppspelning, inte bara direkt efter någons play/paus/spola.
  // Seek-cooldown förhindrar att vi hackar sönder uppspelningen genom
  // att ständigt seeka mot en keyframe som i sig ligger en bit fel.

  const SYNCPLAY_SEEK_DRIFT = 2;      // sekunder — över denna gräns: hård seek
  const SYNCPLAY_NUDGE_START = 0.08;  // sekunder — börja knuffa farten över denna gräns
  const SYNCPLAY_NUDGE_STOP = 0.03;   // sekunder — sluta knuffa under denna gräns (hysteres mot studs)

  // YouTube-spelaren accepterar bara vissa uppspelningshastigheter (oftast
  // 0.75/1/1.25 osv). Kan inte be om t.ex. 1.05x — tar närmaste tillgängliga
  // rate över/under 1x istället.
  function syncplayPickNudgeRate(speedUp) {
    let rates = [0.75, 1, 1.25];
    try {
      const avail = syncplayPlayer.getAvailablePlaybackRates();
      if (avail && avail.length) rates = avail;
    } catch (e) { /* fall tillbaka på standardlistan */ }
    const candidates = speedUp ? rates.filter(r => r > 1) : rates.filter(r => r < 1);
    if (!candidates.length) return 1;
    return speedUp ? Math.min(...candidates) : Math.max(...candidates);
  }

  function syncplayApplyState(data) {
    if (!syncplayPlayer || !syncplayReady) { syncplayPendingState = data; return; }
    const pState = syncplayPlayer.getPlayerState();
    if (pState === YT.PlayerState.BUFFERING) return; // slåss inte mot en pågående seek
    const expected = syncplayExpectedPosition(data);
    const current = syncplayPlayer.getCurrentTime();
    const targetState = data.playing ? YT.PlayerState.PLAYING : YT.PlayerState.PAUSED;

    // Alla klienter (även den som senast styrde videon) seekar mot samma
    // beräknade tidsstämpel. Då snappar alla till SAMMA keyframe istället
    // för att bara mottagaren kvantiseras medan källan förblir okvantiserad
    // — vilket annars gör att de aldrig riktigt möts.
    const drift = expected - current; // positiv = vi ligger efter, negativ = vi ligger före
    const canSeek = Date.now() - syncplayLastSeekAt > 2500;
    if (Math.abs(drift) > SYNCPLAY_SEEK_DRIFT && canSeek) {
      // Stor drift: bara då är en hörbar seek värt det.
      syncplayLastSeekAt = Date.now();
      syncplaySuppressUntil = Date.now() + 2500; // seek + buffringstid
      syncplayCorrectionTargetState = targetState; // vad EKOT förväntas bli — skiljer eko från en riktig ny åtgärd
      syncplayPlayer.seekTo(expected, true);
      syncplayPlayer.setPlaybackRate(1);
      syncplayNudging = false;
    } else {
      // Hysteres: starta knuff över START, sluta först under STOP.
      // Utan glapp studsar farten fram och tillbaka runt en enda tröskel.
      if (Math.abs(drift) > SYNCPLAY_NUDGE_START) syncplayNudging = true;
      else if (Math.abs(drift) < SYNCPLAY_NUDGE_STOP) syncplayNudging = false;

      if (syncplayNudging) {
        syncplayPlayer.setPlaybackRate(syncplayPickNudgeRate(drift > 0));
      } else {
        syncplayPlayer.setPlaybackRate(1);
      }
    }
    if (data.playing && pState !== YT.PlayerState.PLAYING) {
      syncplaySuppressUntil = Math.max(syncplaySuppressUntil, Date.now() + 1200);
      syncplayCorrectionTargetState = targetState;
      syncplayPlayer.playVideo();
    }
    if (!data.playing && pState !== YT.PlayerState.PAUSED) {
      syncplaySuppressUntil = Math.max(syncplaySuppressUntil, Date.now() + 1200);
      syncplayCorrectionTargetState = targetState;
      syncplayPlayer.pauseVideo();
    }
  }

  async function openSyncplayOverlay(videoId, startMuted = true) {
    if (document.getElementById('syncplay-overlay')) return;
    await loadYoutubeApi();
    syncplayReady = false;
    syncplayCurrentVideoId = videoId;
    const overlay = document.createElement('div');
    overlay.id = 'syncplay-overlay';
    overlay.innerHTML = `
      <div class="syncplay-box">
        <button class="syncplay-close" id="syncplay-close-btn" title="Stäng för alla">✕</button>
        <div id="syncplay-offset-ctrl" style="position:absolute;bottom:10px;right:10px;z-index:6;background:rgba(20,22,28,.75);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:4px 6px;font-size:11px;display:flex;align-items:center;gap:4px;">
          <button id="syncplay-offset-minus" title="Justera bakåt" style="background:transparent;border:none;color:#fff;cursor:pointer;font-size:13px;padding:2px 6px;">−</button>
          <span id="syncplay-offset-label">${syncplayFormatOffset(syncplayManualOffsetMs)}</span>
          <button id="syncplay-offset-plus" title="Justera framåt" style="background:transparent;border:none;color:#fff;cursor:pointer;font-size:13px;padding:2px 6px;">+</button>
        </div>
        <div id="syncplay-player"></div>
      </div>
    `;
    document.body.appendChild(overlay);
    document.getElementById('syncplay-close-btn').addEventListener('click', () => {
      syncplayLocalActionUntil = Date.now() + 1200;
      syncplaySet({ active: false, video_id: videoId, playing: false, position: 0 });
      closeSyncplayOverlay();
    });
    document.getElementById('syncplay-offset-minus').addEventListener('click', () => syncplayAdjustOffset(-20));
    document.getElementById('syncplay-offset-plus').addEventListener('click', () => syncplayAdjustOffset(20));
    syncplayPlayer = new YT.Player('syncplay-player', {
      videoId,
      playerVars: { enablejsapi: 1, autoplay: 1, mute: startMuted ? 1 : 0, playsinline: 1 },
      events: {
        onReady: () => {
          syncplayReady = true;
          if (syncplayPendingState) { syncplayApplyState(syncplayPendingState); syncplayPendingState = null; }
        },
        onStateChange: (e) => {
          // Svälj bara om det ser ut som EKOT av vår egen senaste rättning
          // (samma state vi själva precis tvingade fram). En äkta åtgärd åt
          // andra hållet ska alltid slippa igenom, även mitt i fönstret —
          // annars kan bara källan någonsin styra videon.
          if (Date.now() < syncplaySuppressUntil && e.data === syncplayCorrectionTargetState) return;
          if (e.data === YT.PlayerState.PLAYING || e.data === YT.PlayerState.PAUSED) {
            syncplayLocalActionUntil = Date.now() + 1200;
            syncplaySet({
              active: true, video_id: videoId,
              playing: e.data === YT.PlayerState.PLAYING,
              position: syncplayPlayer.getCurrentTime(),
            });
          }
        },
      },
    });
  }

  function closeSyncplayOverlay() {
    const overlay = document.getElementById('syncplay-overlay');
    if (overlay) overlay.remove();
    syncplayPlayer = null;
    syncplayReady = false;
    syncplayPendingState = null;
    syncplayCurrentVideoId = null;
  }

  async function syncplaySet(opts) {
    // Position mäts lokalt FÖRE fetch, men servern stämplar server_time vid
    // MOTTAGANDET. Utan kompensation ligger varje följare konsekvent en
    // nätverkslatens FÖRE värdens riktiga tidpunkt.
    const latencyCompensatedPosition = opts.playing
      ? (opts.position || 0) + (syncplayEstimatedOneWayLatencyMs / 1000)
      : (opts.position || 0);
    try {
      const res = await api('syncplay_set', { method: 'POST', body: {
        active: opts.active ? '1' : '0',
        video_id: opts.video_id || '',
        playing: opts.playing ? '1' : '0',
        position: String(latencyCompensatedPosition),
        client_id: syncplayClientId,
        heartbeat: opts.heartbeat ? '1' : '0',
      }});
      syncplayRev = res.rev;
    } catch (e) { toast('Syncplay error: ' + e.message); }
  }

  function startSyncplay(videoId) {
    syncplayLocalActionUntil = Date.now() + 1200;
    syncplaySet({ active: true, video_id: videoId, playing: true, position: 0 });
    // Riktig klick-gest just nu -> webbläsaren tillåter autoplay MED ljud.
    openSyncplayOverlay(videoId, false);
  }

  async function pollSyncplay() {
    const reqStart = Date.now();
    let data;
    try { data = await api('syncplay_get'); } catch (e) { return; }
    const reqEnd = Date.now();

    // Kompensera för tur-och-retur: anta server_time gäller vid request-MITTEN,
    // inte vid svarets ankomst — annars byggs halva RTT in som permanent fel.
    const midClientTime = (reqStart + reqEnd) / 2000;
    const offsetSample = data.now - midClientTime;
    syncplayClockOffset = syncplayClockOffset === null ? offsetSample : (syncplayClockOffset * 0.8 + offsetSample * 0.2);
    syncplayEstimatedOneWayLatencyMs = syncplayEstimatedOneWayLatencyMs * 0.8 + ((reqEnd - reqStart) / 2) * 0.2;

    syncplayRev = data.rev;
    syncplayLastServerClientId = data.client_id;

    // Vi agerade precis själva (play/paus/seek/stäng) — invänta att den
    // egna skrivningen hinner landa innan vi reagerar på polldata.
    if (Date.now() < syncplayLocalActionUntil) return;

    if (!data.active) { closeSyncplayOverlay(); return; }
    if (!document.getElementById('syncplay-overlay')) { await openSyncplayOverlay(data.video_id); }

    // Källan (den som senast styrde videon) korrigerar aldrig sig själv —
    // det var det som orsakade loopen. Bara mottagande klienter synkas mot den.
    if (data.client_id === syncplayClientId) return;

    syncplayApplyState(data);
  }

  setInterval(pollSyncplay, 250); // tätare poll = kortare fönster innan drift upptäcks

  const SYNCPLAY_HEARTBEAT_MS = 500; // matchar tätare poll, håller server-state färsk

  // Håller server-state färsk medan videon spelas, inte bara vid play/paus/seek.
  // Utan denna extrapolerar följarna rakt fram tills nästa event — buffrar
  // källan syns det inte förrän långt senare och drift byggs upp obemärkt.
  function syncplayHeartbeat() {
    if (!syncplayPlayer || !syncplayReady || !syncplayCurrentVideoId) return;
    if (syncplayLastServerClientId !== syncplayClientId) return; // bara källan hjärtslår
    if (syncplayPlayer.getPlayerState() !== YT.PlayerState.PLAYING) return;
    syncplaySet({
      active: true,
      video_id: syncplayCurrentVideoId,
      playing: true,
      position: syncplayPlayer.getCurrentTime(),
      heartbeat: true,
    });
  }
  setInterval(syncplayHeartbeat, SYNCPLAY_HEARTBEAT_MS);

  // Auto-refresh all feeds in the background every 5 minutes.
  async function autoRefreshAll() {
    try {
      await api('refresh_all', { method: 'POST' });
      await loadTree();
      if (state.selection) reselectCurrent();
    } catch (e) { /* silent error — will retry in 5 min */ }
  }
  setInterval(autoRefreshAll, 5 * 60 * 1000);

})();
</script>
</body>
</html>
