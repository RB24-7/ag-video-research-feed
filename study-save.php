<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Use POST.');
}

if (!function_exists('curl_init')) {
    json_fail(500, 'Server PHP cURL extension is required.');
}

load_env_file(__DIR__ . '/.env');

$supabaseUrl = rtrim(env_value('SUPABASE_URL'), '/');
$supabaseKey = env_value('SUPABASE_SERVICE_ROLE_KEY') ?: env_value('SUPABASE_SERVICE_KEY');
$eventsTable = env_value('SUPABASE_EVENTS_TABLE') ?: 'video_events';
$responsesTable = env_value('SUPABASE_RESPONSES_TABLE') ?: 'study_responses';

if (!$supabaseUrl || !$supabaseKey) {
    json_fail(503, 'Supabase is not configured on this server.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    json_fail(400, 'Invalid JSON payload.');
}

$manifest = load_manifest(__DIR__ . '/data/video-manifest.json');
$validation = build_validation_index($manifest);

$participantId = clean_text($payload['participantId'] ?? '');
$studyLabel = clean_text($payload['studyLabel'] ?? 'pilot');
$sessionId = clean_text($payload['sessionId'] ?? '');

if (!$participantId || !$sessionId) {
    json_fail(400, 'Missing participantId or sessionId.');
}

$eventRows = sanitize_events($payload['events'] ?? [], $participantId, $studyLabel, $sessionId);
$responseRows = sanitize_responses($payload['responses'] ?? [], $validation, $participantId, $studyLabel, $sessionId);

if ($eventRows) {
    supabase_upsert($supabaseUrl, $supabaseKey, $eventsTable, $eventRows);
}
if ($responseRows) {
    supabase_upsert($supabaseUrl, $supabaseKey, $responsesTable, $responseRows);
}

echo json_encode([
    'ok' => true,
    'eventsSaved' => count($eventRows),
    'responsesSaved' => count($responseRows),
], JSON_UNESCAPED_SLASHES);

function env_value(string $key): string {
    $value = getenv($key);
    return is_string($value) ? trim($value) : '';
}

function load_env_file(string $path): void {
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || getenv($key) !== false) continue;
        $value = preg_replace('/^[\'"]|[\'"]$/', '', $value);
        putenv($key . '=' . $value);
    }
}

function load_manifest(string $path): array {
    if (!is_file($path)) {
        json_fail(500, 'Missing data/video-manifest.json.');
    }
    $manifest = json_decode((string) file_get_contents($path), true);
    if (!is_array($manifest)) {
        json_fail(500, 'Invalid video manifest.');
    }
    return $manifest;
}

function build_validation_index(array $manifest): array {
    $videos = [];
    foreach (($manifest['sets'] ?? []) as $set) {
        if (!is_array($set)) continue;
        $setId = clean_text($set['studySetId'] ?? $set['id'] ?? '');
        if (!$setId) continue;

        $seedId = slug_id($setId . '-seed');
        $videos[$seedId] = [
            'videoId' => $seedId,
            'studySetId' => $setId,
            'seedId' => $seedId,
            'round' => 1,
            'modelName' => '',
            'title' => clean_text($set['title'] ?? ''),
            'keywords' => build_keyword_map($seedId, $set),
        ];

        $variants = is_array($set['variants'] ?? null) ? $set['variants'] : [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) continue;
            $variantId = slug_id($setId . '-variant-' . ($index + 1));
            $videos[$variantId] = [
                'videoId' => $variantId,
                'studySetId' => $setId,
                'seedId' => $seedId,
                'round' => 2,
                'modelName' => clean_text($variant['modelName'] ?? $variant['model'] ?? $variant['shortLabel'] ?? ''),
                'title' => clean_text(($variant['shortLabel'] ?? $variant['modelName'] ?? 'Generated') . ' variant'),
                'keywords' => build_keyword_map($variantId, $variant, $set),
            ];
        }
    }
    return $videos;
}

function build_keyword_map(string $videoId, array $source, ?array $fallbackSource = null): array {
    $details = read_keyword_details($source);
    if (!$details && $fallbackSource) {
        $details = read_keyword_details($fallbackSource);
    }

    $map = [];
    foreach ($details as $index => $keyword) {
        if (!is_array($keyword)) continue;
        $text = clean_text($keyword['text'] ?? '');
        if ($text === '') continue;
        $key = slug_id($text);
        $map[$key] = [
            'id' => slug_id($videoId) . ':' . $key,
            'text' => $text,
            'confidence' => numeric_or_null($keyword['confidence'] ?? null),
            'source' => clean_text($keyword['source'] ?? 'pipeline'),
            'rank' => numeric_or_null($keyword['rank'] ?? ($index + 1)),
        ];
    }
    return $map;
}

function read_keyword_details(array $source): array {
    if (is_array($source['questionKeywordDetails'] ?? null)) {
        return $source['questionKeywordDetails'];
    }
    if (is_array($source['keywordDetails'] ?? null)) {
        return $source['keywordDetails'];
    }

    $keywords = [];
    $rawKeywords = [];
    if (is_array($source['questionKeywords'] ?? null)) {
        $rawKeywords = $source['questionKeywords'];
    } elseif (is_array($source['keywords'] ?? null)) {
        $rawKeywords = $source['keywords'];
    }

    foreach ($rawKeywords as $index => $keyword) {
        $keywords[] = [
            'text' => (string) $keyword,
            'confidence' => null,
            'source' => 'manifest',
            'rank' => $index + 1,
        ];
    }
    return $keywords;
}

function sanitize_events($events, string $participantId, string $studyLabel, string $sessionId): array {
    if (!is_array($events)) return [];
    $rows = [];
    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $eventId = clean_text($event['id'] ?? '');
        $videoId = clean_text($event['videoId'] ?? 'unknown');
        $eventType = clean_text($event['event'] ?? '');
        if (!$eventType) continue;

        $rows[] = [
            'id' => $eventId ?: hash('sha256', $sessionId . ':' . $videoId . ':' . $eventType . ':' . clean_text($event['createdAt'] ?? microtime(true))),
            'participant_id' => clean_text($event['participantId'] ?? $participantId),
            'study_label' => clean_text($event['studyLabel'] ?? $studyLabel),
            'session_id' => clean_text($event['sessionId'] ?? $sessionId),
            'video_id' => $videoId,
            'event_type' => $eventType,
            'event_value' => isset($event['value']) ? clean_text($event['value']) : null,
            'metadata' => is_array($event['metadata'] ?? null) ? $event['metadata'] : new stdClass(),
            'created_at' => clean_datetime($event['createdAt'] ?? null),
        ];
    }
    return $rows;
}

function sanitize_responses($responses, array $validation, string $participantId, string $studyLabel, string $sessionId): array {
    if (!is_array($responses)) return [];
    $rows = [];
    foreach ($responses as $response) {
        if (!is_array($response)) continue;
        $videoId = clean_text($response['videoId'] ?? '');
        if (!$videoId || !isset($validation[$videoId])) {
            json_fail(422, 'Response references an unknown video: ' . $videoId);
        }

        $video = $validation[$videoId];
        $keywordDetails = validate_keywords($response, $video);
        $selectedKeywords = [];
        foreach ($keywordDetails as $keyword) {
            $selectedKeywords[] = $keyword['text'];
        }
        $likeReasons = array_values(array_filter(array_map('clean_text', is_array($response['likeReasons'] ?? null) ? $response['likeReasons'] : [])));
        $dislikeReasons = array_values(array_filter(array_map('clean_text', is_array($response['dislikeReasons'] ?? null) ? $response['dislikeReasons'] : [])));

        $rows[] = [
            'id' => hash('sha256', $sessionId . ':' . $videoId),
            'participant_id' => clean_text($response['participantId'] ?? $participantId),
            'study_label' => clean_text($response['studyLabel'] ?? $studyLabel),
            'session_id' => clean_text($response['sessionId'] ?? $sessionId),
            'video_id' => $videoId,
            'video_title' => clean_text($response['videoTitle'] ?? $video['title']),
            'study_set_id' => $video['studySetId'],
            'seed_id' => clean_text($response['seedId'] ?? $video['seedId']),
            'model_name' => clean_text($response['modelName'] ?? $video['modelName']),
            'round' => (int) ($response['round'] ?? $video['round']),
            'liked' => array_key_exists('liked', $response) ? bool_or_null($response['liked']) : null,
            'like_reasons' => $likeReasons,
            'dislike_reasons' => $dislikeReasons,
            'selected_keywords' => $selectedKeywords,
            'keyword_details' => $keywordDetails,
            'max_watch_percent' => numeric_or_null($response['maxWatchPercent'] ?? null),
            'responded_at' => clean_datetime($response['respondedAt'] ?? null),
            'submitted_at' => clean_datetime($response['submittedAt'] ?? null),
            'raw_response' => $response,
        ];
    }
    return $rows;
}

function validate_keywords(array $response, array $video): array {
    $rawKeywords = [];
    if (is_array($response['keywordDetails'] ?? null)) {
        foreach ($response['keywordDetails'] as $keyword) {
            if (!is_array($keyword)) continue;
            $rawKeywords[] = [
                'id' => clean_text($keyword['id'] ?? ''),
                'text' => clean_text($keyword['text'] ?? ''),
            ];
        }
    } elseif (is_array($response['keywords'] ?? null)) {
        foreach ($response['keywords'] as $keyword) {
            $rawKeywords[] = [
                'id' => '',
                'text' => clean_text($keyword),
            ];
        }
    }

    $validated = [];
    foreach ($rawKeywords as $keyword) {
        $keywordText = clean_text($keyword['text'] ?? '');
        if ($keywordText === '') continue;
        $key = slug_id($keywordText);
        if (!isset($video['keywords'][$key])) {
            json_fail(422, 'Keyword "' . $keywordText . '" is not valid for video ' . $video['videoId'] . '.');
        }
        $expected = $video['keywords'][$key];
        $submittedId = clean_text($keyword['id'] ?? '');
        if ($submittedId !== '' && $submittedId !== $expected['id']) {
            json_fail(422, 'Keyword "' . $keywordText . '" does not match video ' . $video['videoId'] . '.');
        }
        $validated[] = $expected;
    }
    return $validated;
}

function supabase_upsert(string $baseUrl, string $key, string $table, array $rows): void {
    $url = $baseUrl . '/rest/v1/' . rawurlencode($table) . '?on_conflict=id';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates,return=minimal',
        ],
        CURLOPT_POSTFIELDS => json_encode($rows, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        json_fail(502, 'Supabase save failed: ' . ($error ?: (string) $body));
    }
}

function clean_text($value): string {
    return trim((string) $value);
}

function slug_id(string $value): string {
    $slug = strtolower($value);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'video';
}

function numeric_or_null($value) {
    if ($value === null || $value === '') return null;
    return is_numeric($value) ? (float) $value : null;
}

function bool_or_null($value) {
    if ($value === null || $value === '') return null;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
}

function clean_datetime($value): string {
    $text = clean_text($value ?? '');
    if ($text === '') return gmdate('c');
    $timestamp = strtotime($text);
    return $timestamp ? gmdate('c', $timestamp) : gmdate('c');
}

function json_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}
