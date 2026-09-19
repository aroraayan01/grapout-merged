<?php
/**
 * Stands in for the legacy site's .htaccess, for PHP's built-in server.
 *
 * Rather than transcribe forty-odd RewriteRules and let them drift, this reads
 * the real .htaccess and applies them: pattern, target, captures and query
 * string. So /sign-in reaches login.php and /price-list reaches price.php for
 * the same reason they do on the server — because that is what the file says.
 *
 * Two kinds of rule are deliberately ignored: anything pointing at an absolute
 * URL (the https:// and www canonical redirects, which would send a local
 * preview to the live site), and the https-only guards, which have no meaning
 * on a laptop.
 */

$root = dirname(__DIR__); // the merged site's document root
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rel = ltrim($path, '/');

/** Serve a page and tell the built-in server we handled it. */
function serve(string $file, string $query = ''): bool
{
    if ($query !== '') {
        $_SERVER['QUERY_STRING'] = $query;
        parse_str($query, $extra);
        $_GET = array_merge($_GET, $extra);
        $_REQUEST = array_merge($_REQUEST, $extra);
    }
    require $file;

    return true;
}

// A real file — stylesheet, image, script, or a .php asked for by name — wins
// over every rule, so an asset is never swallowed by a page rule.
if ($rel !== '' && is_file("$root/$rel")) {
    if (substr($rel, -4) === '.php') {
        return serve("$root/$rel");
    }

    return false; // the built-in server sends it with the right type
}

// The one page that is not a rewrite: the Contact Us page added alongside the
// consulting site, which lives on the server as its own rule.
if ($rel === 'office' && is_file("$root/grapoutoffice.php")) {
    return serve("$root/grapoutoffice.php");
}

foreach (rules("$root/.htaccess") as [$pattern, $target, $flags]) {
    if (! preg_match('#' . str_replace('#', '\#', $pattern) . '#', $rel, $m)) {
        continue;
    }

    // $1, $2 … as Apache fills them.
    $filled = preg_replace_callback('/\$(\d)/', fn ($d) => $m[(int) $d[1]] ?? '', $target);
    [$file, $query] = array_pad(explode('?', $filled, 2), 2, '');

    if (stripos($flags, 'R') !== false) {
        header('Location: /' . ltrim($file, '/') . ($query !== '' ? "?$query" : ''), true, 302);

        return true;
    }

    $file = $root . '/' . ltrim($file, '/');
    if (is_file($file)) {
        return serve($file, $query);
    }
}

// Not in the file, but the shape most of those rules have anyway.
if ($rel !== '' && is_file("$root/$rel.php")) {
    return serve("$root/$rel.php");
}

http_response_code(404);
echo '<!doctype html><meta charset=utf-8><title>404</title>'
   . '<body style="font:15px/1.6 system-ui;padding:48px;max-width:40rem">'
   . '<h1 style="font-size:1.3rem">404</h1>'
   . '<p>No page at <code>' . htmlspecialchars($path) . '</code> on the legacy site.</p>'
   . '<p style="color:#666">This is the local stand-in for grapout.com\'s Apache rules. '
   . 'It reads the real .htaccess, so a page missing here is missing there too — '
   . 'unless the rule points at an absolute URL, which is skipped on purpose.</p>';

return true;

/**
 * The usable RewriteRules, in file order.
 *
 * @return list<array{0:string,1:string,2:string}> pattern, target, flags
 */
function rules(string $htaccess): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    if (! is_file($htaccess)) {
        return $cache;
    }

    foreach (file($htaccess, FILE_IGNORE_NEW_LINES) as $line) {
        if (! preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $m)) {
            continue;
        }
        // Off-site redirects would walk a local preview onto the live site.
        if (preg_match('#^(https?:)?//#i', $m[2])) {
            continue;
        }
        $cache[] = [$m[1], $m[2], $m[3] ?? ''];
    }

    return $cache;
}
