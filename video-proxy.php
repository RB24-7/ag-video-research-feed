<?php
declare(strict_types=1);

set_time_limit(0);
ignore_user_abort(true);

function fail_with_status(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function string_ends_with(string $value, string $suffix): bool {
    if ($suffix === '') {
        return true;
    }
    return substr($value, -strlen($suffix)) === $suffix;
}

function string_contains(string $value, string $needle): bool {
    return $needle === '' || strpos($value, $needle) !== false;
}

function is_allowed_video_host(string $host): bool {
    $host = strtolower($host);
    return $host === 'dropbox.com'
        || $host === 'www.dropbox.com'
        || string_ends_with($host, '.dropbox.com')
        || $host === 'dropboxusercontent.com'
        || string_ends_with($host, '.dropboxusercontent.com');
}

function normalize_dropbox_url(string $url): string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return $url;
    }

    $host = strtolower($parts['host']);
    if (!string_ends_with($host, 'dropbox.com')) {
        return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    unset($query['dl']);
    $query['raw'] = '1';

    $scheme = $parts['scheme'] ?? 'https';
    $path = $parts['path'] ?? '';
    $rebuilt = $scheme . '://' . $parts['host'] . $path;
    if ($query) {
        $rebuilt .= '?' . http_build_query($query);
    }
    return $rebuilt;
}

$url = $_GET['url'] ?? '';
if (!is_string($url) || trim($url) === '') {
    fail_with_status(400, 'Missing video url.');
}

$url = normalize_dropbox_url($url);
$parts = parse_url($url);
if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
    fail_with_status(400, 'Invalid video url.');
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    fail_with_status(400, 'Unsupported video url scheme.');
}

if (!is_allowed_video_host($parts['host'])) {
    fail_with_status(403, 'Video host is not allowed.');
}

if (!function_exists('curl_init')) {
    fail_with_status(500, 'Server PHP cURL extension is required for video proxying.');
}

$headers = [
    'User-Agent: ag-video-research-feed/1.0',
    'Accept: video/mp4,video/*,*/*;q=0.8',
];

if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=\d*-\d*(,\d*-\d*)?$/', $_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_BUFFERSIZE => 1024 * 256,
    CURLOPT_HEADERFUNCTION => static function ($curl, string $header): int {
        $length = strlen($header);
        $line = trim($header);

        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $matches)) {
            $status = (int) $matches[1];
            if ($status >= 200 && $status < 600) {
                http_response_code($status);
            }
            return $length;
        }

        if (!string_contains($line, ':')) {
            return $length;
        }

        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $lower = strtolower($name);
        if (in_array($lower, ['content-range', 'accept-ranges', 'etag', 'last-modified'], true)) {
            header($name . ': ' . $value, true);
        }

        return $length;
    },
    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk): int {
        echo $chunk;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        return strlen($chunk);
    },
]);

$ok = curl_exec($ch);
$error = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($ok === false || $status >= 400) {
    fail_with_status($status >= 400 ? $status : 502, $error ?: 'Could not stream video.');
}
