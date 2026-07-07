<?php
/**
 * backend/ai/generate_summary.php
 * ─────────────────────────────────────────────────────────────
 * POST /backend/ai/generate_summary.php
 * Authorization: Bearer <token>
 * Content-Type: application/json
 *
 * Body: { "note_id": int, "regenerate": bool (optional) }
 *
 * Pipes JSON to ai/generate_summary.py via stdin (proc_open).
 * Caches result in ai_summaries table.
 *
 * Returns JSON:
 *   Success → { "success": true, "summary": "...",
 *               "word_count": int, "cached": bool, "model": string }
 *   Failure → { "success": false, "message": "..." }
 * ─────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

// ── Auth guard ────────────────────────────────────────────────
$authUser = requireAuth();
$userId   = (int)$authUser['sub'];

// ── Only POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────
$body       = json_decode(file_get_contents('php://input'), true);
$noteId     = isset($body['note_id'])    ? (int)$body['note_id']     : 0;
$regenerate = isset($body['regenerate']) ? (bool)$body['regenerate'] : false;

if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid note_id is required.']);
    exit;
}

// ── Fetch note (ownership check) ──────────────────────────────
try {
    $stmt = $pdo->prepare(
        'SELECT id, name, content FROM notes
         WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([':id' => $noteId, ':uid' => $userId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[generate_summary.php] Note fetch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found or access denied.']);
    exit;
}

// ── Check cache (skip if regenerate=true) ─────────────────────
if (!$regenerate) {
    try {
        $cacheStmt = $pdo->prepare(
            'SELECT summary, word_count FROM ai_summaries WHERE note_id = :nid LIMIT 1'
        );
        $cacheStmt->execute([':nid' => $noteId]);
        $cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cached = false;
    }

    if ($cached && !empty($cached['summary'])) {
        http_response_code(200);
        echo json_encode([
            'success'    => true,
            'summary'    => $cached['summary'],
            'word_count' => (int)($cached['word_count'] ?? 0),
            'cached'     => true,
            'model'      => 'cached',
        ]);
        exit;
    }
}

// ── Config ────────────────────────────────────────────────────
$apiKey = getenv('OPENAI_API_KEY')
       ?: ($_ENV['OPENAI_API_KEY']    ?? '')
       ?: ($_SERVER['OPENAI_API_KEY'] ?? '');

$model  = getenv('OPENAI_MODEL')
       ?: ($_ENV['OPENAI_MODEL']    ?? '')
       ?: ($_SERVER['OPENAI_MODEL'] ?? 'gpt-4o-mini');

// Hardcoded venv Python path — Apache doesn't inherit shell env
// $pythonBin  = 'C:\\xampp\\htdocs\\SmartStudyCompanion\\venv\\Scripts\\python.exe';
$pythonBin = getenv('PYTHON_BIN') ?: 'python3';
$scriptPath = realpath(__DIR__ . '/../../ai/generate_summary.py');

if (!$apiKey) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'OPENAI_API_KEY is not configured on the server.',
    ]);
    exit;
}

if (!$scriptPath || !is_file($scriptPath)) {
    error_log('[generate_summary.php] Script not found: ai/generate_summary.py');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Summary script not found on server.']);
    exit;
}

// ── Build JSON payload ────────────────────────────────────────
$payload = json_encode([
    'content'   => $note['content'],
    'note_name' => $note['name'],
    'max_words' => 600,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payload === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to encode note content.']);
    exit;
}

// ── Launch Python via proc_open with stdin ────────────────────
$descriptors = [
    0 => ['pipe', 'r'],   // stdin  — we write JSON here
    1 => ['pipe', 'w'],   // stdout — we read reply here
    2 => ['pipe', 'w'],   // stderr — captured for debugging
];

$env = [
    'OPENAI_API_KEY'   => $apiKey,
    'OPENAI_MODEL'     => $model,
    'PYTHONIOENCODING' => 'utf-8',
    'PYTHONUNBUFFERED' => '1',
    'PYTHONUTF8'       => '1',
    'SYSTEMROOT'       => getenv('SYSTEMROOT') ?: 'C:\\Windows',
    'SYSTEMDRIVE'      => getenv('SYSTEMDRIVE') ?: 'C:',
    'PATH'             => getenv('PATH') ?: '',
    'TEMP'             => getenv('TEMP') ?: 'C:\\Windows\\Temp',
    'TMP'              => getenv('TMP')  ?: 'C:\\Windows\\Temp',
];

error_log('[generate_summary.php] Starting python: ' . $pythonBin);
error_log('[generate_summary.php] Script: ' . $scriptPath);

$process = proc_open(
    [$pythonBin, $scriptPath],
    $descriptors,
    $pipes,
    null,
    $env
);

if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to start Python process.']);
    exit;
}

// Write payload to stdin then close it
fwrite($pipes[0], $payload);
fclose($pipes[0]);

// Read output with timeout
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$output    = '';
$stderr    = '';
$startTime = time();
$timeout   = 90; // seconds
$timedOut  = false;

while (true) {
    $chunk = fread($pipes[1], 8192);
    if ($chunk !== false && $chunk !== '') { $output .= $chunk; }

    $errChunk = fread($pipes[2], 4096);
    if ($errChunk !== false && $errChunk !== '') { $stderr .= $errChunk; }

    $status = proc_get_status($process);
    if (!$status['running']) {
        $output .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        break;
    }

    if ((time() - $startTime) >= $timeout) {
        $timedOut = true;
        proc_terminate($process, 9);
        break;
    }

    usleep(100_000); // 100ms polling
}

fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

if (!empty(trim($stderr))) {
    error_log('[generate_summary.php] Python stderr: ' . substr($stderr, 0, 1000));
}

// ── Handle timeout ────────────────────────────────────────────
if ($timedOut) {
    http_response_code(504);
    echo json_encode([
        'success' => false,
        'message' => "Request timed out after {$timeout}s. Please try again.",
    ]);
    exit;
}

// ── Parse output ──────────────────────────────────────────────
$trimmedOutput = trim($output);

if ($trimmedOutput === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'AI process returned no output. Check PHP error log.',
    ]);
    exit;
}

$result = json_decode($trimmedOutput, true);

if (!is_array($result)) {
    error_log('[generate_summary.php] Non-JSON output: ' . substr($trimmedOutput, 0, 300));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unexpected output format from AI.']);
    exit;
}

// ── Handle Python-level failure ───────────────────────────────
if (empty($result['success'])) {
    $pythonMsg  = $result['message'] ?? 'Summary generation failed.';
    error_log('[generate_summary.php] Python failure: ' . $pythonMsg);

    $userMessage = $pythonMsg;
    $statusCode  = 500;

    if (str_contains($pythonMsg, 'OPENAI_API_KEY') || str_contains($pythonMsg, 'api key')) {
        $userMessage = 'Invalid or missing OpenAI API key.';
        $statusCode  = 401;
    } elseif (str_contains($pythonMsg, 'rate limit') || str_contains($pythonMsg, 'quota')) {
        $userMessage = 'OpenAI rate limit reached. Please wait a moment and try again.';
        $statusCode  = 429;
    } elseif (str_contains($pythonMsg, 'too short')) {
        $userMessage = 'Note content is too short to summarise.';
        $statusCode  = 400;
    }

    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $userMessage]);
    exit;
}

// ── Extract summary ───────────────────────────────────────────
$summary   = trim((string)($result['summary']    ?? ''));
$wordCount = (int)($result['word_count'] ?? str_word_count($summary));

if ($summary === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'AI returned an empty summary.']);
    exit;
}

// ── Cache the result ──────────────────────────────────────────
try {
    $upsert = $pdo->prepare(
        'INSERT INTO ai_summaries (note_id, user_id, summary, word_count, created_at)
         VALUES (:nid, :uid, :sum, :wc, NOW())
         ON DUPLICATE KEY UPDATE
           summary    = VALUES(summary),
           word_count = VALUES(word_count),
           updated_at = NOW()'
    );
    $upsert->execute([':nid' => $noteId, ':uid' => $userId, ':sum' => $summary, ':wc' => $wordCount]);
} catch (PDOException $e) {
    error_log('[generate_summary.php] Cache upsert: ' . $e->getMessage());
    // Non-fatal — still return the summary
}

// ── Success ───────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success'    => true,
    'summary'    => $summary,
    'word_count' => $wordCount,
    'cached'     => false,
    'model'      => $model,
]);