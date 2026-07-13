<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

$authUser = requireAuth();
$userId   = (int)$authUser['sub'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$noteId  = isset($body['note_id']) ? (int)$body['note_id']          : 0;
$message = isset($body['message']) ? trim((string)$body['message']) : '';
$history = isset($body['history']) && is_array($body['history'])
           ? $body['history']
           : [];

if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid note_id is required.']);
    exit;
}

if ($message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'message cannot be empty.']);
    exit;
}

if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'message must not exceed 2000 characters.']);
    exit;
}

$cleanHistory = [];
foreach ($history as $turn) {
    if (
        !is_array($turn) ||
        !isset($turn['role'], $turn['content']) ||
        !in_array($turn['role'], ['user', 'assistant', 'model'], true)
    ) {
        continue;
    }

    $role = $turn['role'] === 'model' ? 'assistant' : (string)$turn['role'];

    $cleanHistory[] = [
        'role'    => $role,
        'content' => mb_substr((string)$turn['content'], 0, 4000),
    ];
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, name, content FROM notes
         WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([':id' => $noteId, ':uid' => $userId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[chat_assistant.php] Note fetch: ' . $e->getMessage());
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
    'message'   => $message,
    'content'   => $note['content'],
    'note_name' => $note['name'],
    'history'   => $cleanHistory,
], JSON_UNESCAPED_UNICODE);

$pythonBin = getenv('PYTHON_BIN') ?: 'python3';
error_log('[chat] Using python: ' . $pythonBin);
$scriptPath = realpath(__DIR__ . '/../../ai/chat_assistant.py');

if (!$scriptPath) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Python script not found.']);
    exit;
}

$apiKey = getenv('OPENAI_API_KEY')
       ?: ($_ENV['OPENAI_API_KEY']    ?? '')
       ?: ($_SERVER['OPENAI_API_KEY'] ?? '');

$model  = getenv('OPENAI_MODEL')
       ?: ($_ENV['OPENAI_MODEL']    ?? '')
       ?: ($_SERVER['OPENAI_MODEL'] ?? 'gpt-4o-mini');

$descriptors = [
    0 => ['pipe', 'r'],   
    1 => ['pipe', 'w'],   
    2 => ['pipe', 'w'],   
];

$env = [
    'OPENAI_API_KEY'   => $apiKey,
    'OPENAI_MODEL'     => $model,
    'PYTHONIOENCODING' => 'utf-8',
    'PYTHONUNBUFFERED' => '1',
    'SYSTEMROOT'       => getenv('SYSTEMROOT') ?: 'C:\\Windows',
    'SYSTEMDRIVE'      => getenv('SYSTEMDRIVE') ?: 'C:',
    'PATH'             => getenv('PATH') ?: '',
    'TEMP'             => getenv('TEMP') ?: 'C:\\Windows\\Temp',
    'TMP'              => getenv('TMP')  ?: 'C:\\Windows\\Temp',
];

error_log('[chat] apiKey=' . substr($apiKey, 0, 12) . ' model=' . $model);
error_log('[chat] pythonBin=' . $pythonBin);
error_log('[chat] scriptPath=' . $scriptPath);

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

fwrite($pipes[0], $payload);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

if ($errors) {
    error_log('[chat_assistant.php] Python stderr: ' . $errors);
}

if ($output === null || trim($output) === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Chat script produced no output. Exit: ' . $exitCode]);
    exit;
}

$result = json_decode(trim($output), true);

if (!is_array($result) || empty($result['success'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $result['message'] ?? 'Chat assistant failed.',
    ]);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'reply' => $result['reply']]);
