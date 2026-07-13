<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

$authUser = requireAuth();
$userId   = (int)$authUser['sub'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$noteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid note ID is required (?id=<int>).']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            n.id,
            n.name,
            n.content,
            n.file_type,
            n.file_size,
            DATE_FORMAT(n.upload_date, '%m/%d/%Y') AS upload_date,
            DATE_FORMAT(n.created_at,  '%Y-%m-%d %H:%i:%s') AS created_at,
            n.file_path,
            (SELECT COUNT(*)
             FROM   quiz_results qr
             WHERE  qr.note_id = n.id) AS quiz_count,
            (SELECT ROUND(AVG(qr.percent), 1)
             FROM   quiz_results qr
             WHERE  qr.note_id = n.id) AS avg_quiz_score
         FROM  notes n
         WHERE n.id        = :note_id
           AND n.user_id   = :user_id
           AND n.deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute([
        ':note_id' => $noteId,
        ':user_id' => $userId,
    ]);

    $note = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[get_note_by_id.php] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found.']);
    exit;
}

$note['content_preview'] = mb_substr(trim($note['content']), 0, 500);
if (mb_strlen($note['content']) > 500) {
    $note['content_preview'] .= '…';
}

$note['id']             = (int)$note['id'];
$note['quiz_count']     = (int)($note['quiz_count'] ?? 0);
$note['avg_quiz_score'] = $note['avg_quiz_score'] !== null
    ? (float)$note['avg_quiz_score']
    : null;

try {
    $log = $pdo->prepare(
        'UPDATE notes SET last_accessed_at = NOW() WHERE id = :id'
    );
    $log->execute([':id' => $noteId]);
} catch (PDOException $e) {
    // Non-fatal
    error_log('[get_note_by_id.php] last_accessed update failed: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'note'    => $note,
]);
