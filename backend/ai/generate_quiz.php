<?php
/**
 * backend/ai/generate_quiz.php
 * ─────────────────────────────────────────────────────────────
 * POST /backend/ai/generate_quiz.php
 * Authorization: Bearer <token>
 * Content-Type: application/json
 *
 * Body:
 *   {
 *     "note_id"   : int,
 *     "count"     : int,    (optional, default 10)
 *     "difficulty": string  (optional: easy|medium|hard|mixed)
 *   }
 *
 * Shells out to ai/generate_quiz.py with the note content.
 * Quiz questions are NOT cached — each call generates fresh questions.
 *
 * Returns JSON:
 *   Success → { "success": true, "questions": [...], "count": int }
 *   Failure → { "success": false, "message": "..." }
 * ─────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// env.php MUST be first — before jwt_helper, db, cors, verify_token
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

// ── AFTER the require_once lines, ADD this block ──────────────
// Force environment variables that Apache/XAMPP doesn't load from .env
// Get your JWT_SECRET from your .env file and paste it here



// TEMP DEBUG - remove after fixing
$headers = getallheaders();
error_log('[DEBUG] Auth header: ' . ($headers['Authorization'] ?? $headers['authorization'] ?? 'NOT FOUND'));
error_log('[DEBUG] All headers: ' . json_encode($headers));

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
$noteId     = isset($body['note_id'])    ? (int)$body['note_id']             : 0;
$count      = isset($body['count'])      ? (int)$body['count']               : 10;
$difficulty = isset($body['difficulty']) ? trim((string)$body['difficulty'])  : 'mixed';

// ── Validate ──────────────────────────────────────────────────
if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid note_id is required.']);
    exit;
}

$count = max(2, min(30, $count));

$allowedDifficulties = ['easy', 'medium', 'hard', 'mixed'];
if (!in_array($difficulty, $allowedDifficulties, true)) {
    $difficulty = 'mixed';
}

// ── Fetch note ────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'SELECT id, name, content FROM notes
         WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([':id' => $noteId, ':uid' => $userId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[generate_quiz.php] Note fetch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found.']);
    exit;
}

// ── Build Python payload ──────────────────────────────────────
$payload = json_encode([
    'content'    => $note['content'],
    'note_name'  => $note['name'],
    'count'      => $count,
    'difficulty' => $difficulty,
], JSON_UNESCAPED_UNICODE);

// $pythonBin  = escapeshellcmd(getenv('PYTHON_BIN') ?: 'python');
// $scriptPath = escapeshellarg(realpath(__DIR__ . '/../../ai/generate_quiz.py'));
// $jsonArg    = escapeshellarg($payload);

// // ── Pass OPENAI_API_KEY into the Python process environment ───
// $apiKey = escapeshellarg(getenv('OPENAI_API_KEY') ?: '');
// $model  = escapeshellarg(getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
// // Temporary — add right before the $cmd line, remove after testing
// // error_log('[DEBUG] API Key set: ' . (getenv('OPENAI_API_KEY') ? 'YES' : 'NO'));
// // error_log('[DEBUG] Python bin: ' . $pythonBin);
// // error_log('[DEBUG] Script path: ' . $scriptPath);
// // $cmd    = "OPENAI_API_KEY={$apiKey} OPENAI_MODEL={$model} {$pythonBin} {$scriptPath} {$jsonArg} 2>/dev/null";

// $apiKey = getenv('OPENAI_API_KEY') ?: '';
// $model  = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

// // Windows-compatible: set env vars before calling Python
// $cmd = "set OPENAI_API_KEY=" . escapeshellarg($apiKey) . " && "
//      . "set OPENAI_MODEL=" . escapeshellarg($model) . " && "
//      . "{$pythonBin} {$scriptPath} {$jsonArg} 2>nul";

// $output = shell_exec($cmd);


// $pythonBin  = 'python';
// $scriptPath = realpath(__DIR__ . '/../../ai/generate_quiz.py');
// $apiKey     = getenv('OPENAI_API_KEY') ?: '';
// $model      = getenv('OPENAI_MODEL')   ?: 'gpt-4o-mini';

// // Write JSON to a temp file to avoid Windows shell quoting issues
// $tmpFile = tempnam(sys_get_temp_dir(), 'quiz_') . '.json';
// file_put_contents($tmpFile, $payload);


// $cmd    = "set OPENAI_API_KEY={$apiKey} && set OPENAI_MODEL={$model} && {$pythonBin} \"{$scriptPath}\" --file \"{$tmpFile}\" 2>nul";
// $output = shell_exec($cmd);

// @unlink($tmpFile); // cleanup



set_time_limit(180);

// $pythonBin  = 'C:\\xampp\\htdocs\\SmartStudyCompanion\\venv\\Scripts\\python.exe';
$pythonBin = getenv('PYTHON_BIN') ?: 'python3';
$scriptPath = realpath(__DIR__ . '/../../ai/generate_quiz.py');
$apiKey     = getenv('OPENAI_API_KEY') ?: '';
$model      = getenv('OPENAI_MODEL')   ?: 'gpt-4o-mini';

if (!$scriptPath) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Python script not found on server.']);
    exit;
}

// Verify venv python exists, fallback to system python
if (!file_exists($pythonBin)) {
    $pythonBin = 'python';
    error_log('[generate_quiz.php] venv python not found, falling back to system python');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'quiz_') . '.json';
$tmpErr  = tempnam(sys_get_temp_dir(), 'quiz_err_');
file_put_contents($tmpFile, $payload);

// PYTHONIOENCODING=utf-8 fixes Unicode crash; cmd /c required for && on Windows
$cmd = 'cmd /c "set "OPENAI_API_KEY=' . $apiKey . '" && '
     . 'set "OPENAI_MODEL=' . $model . '" && '
     . 'set "PYTHONIOENCODING=utf-8" && '
     . 'set "PYTHONUTF8=1" && '
     . '"' . $pythonBin . '" "' . $scriptPath . '" --file "' . $tmpFile . '" 2>"' . $tmpErr . '""';

error_log('[generate_quiz.php] CMD: ' . $cmd);
$output = shell_exec($cmd);

$stderr = @file_get_contents($tmpErr);
if ($stderr) error_log('[generate_quiz.php] Python stderr: ' . $stderr);

@unlink($tmpFile);
@unlink($tmpErr);


if ($output === null || trim($output) === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Quiz script produced no output.']);
    exit;
}

$result = json_decode(trim($output), true);

if (!is_array($result) || empty($result['success'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $result['message'] ?? 'Quiz generation failed.',
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'success'   => true,
    'questions' => $result['questions'],
    'count'     => count($result['questions']),
]);