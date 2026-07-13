<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

$headers = getallheaders();
error_log('[DEBUG] Auth header: ' . ($headers['Authorization'] ?? $headers['authorization'] ?? 'NOT FOUND'));
error_log('[DEBUG] All headers: ' . json_encode($headers));

$authUser = requireAuth();
$userId   = (int)$authUser['sub'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$noteId     = isset($body['note_id'])    ? (int)$body['note_id']             : 0;
$count      = isset($body['count'])      ? (int)$body['count']               : 10;
$difficulty = isset($body['difficulty']) ? trim((string)$body['difficulty'])  : 'mixed';

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

$payload = json_encode([
    'content'    => $note['content'],
    'note_name'  => $note['name'],
    'count'      => $count,
    'difficulty' => $difficulty,
], JSON_UNESCAPED_UNICODE);

set_time_limit(180);
$pythonBin = getenv('PYTHON_BIN') ?: 'python3';
$scriptPath = realpath(__DIR__ . '/../../ai/generate_quiz.py');
$apiKey     = getenv('OPENAI_API_KEY') ?: '';
$model      = getenv('OPENAI_MODEL')   ?: 'gpt-4o-mini';

if (!$scriptPath) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Python script not found on server.']);
    exit;
}

if (!file_exists($pythonBin)) {
    $pythonBin = 'python';
    error_log('[generate_quiz.php] venv python not found, falling back to system python');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'quiz_') . '.json';
$tmpErr  = tempnam(sys_get_temp_dir(), 'quiz_err_');
file_put_contents($tmpFile, $payload);

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
