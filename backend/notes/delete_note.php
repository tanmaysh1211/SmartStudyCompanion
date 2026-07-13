<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

$authUser = requireAuth();
$userId   = (int)$authUser['sub'];

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!$body || !is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$noteId = isset($body['note_id']) ? (int)$body['note_id'] : 0;

if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid note_id is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, name, file_path
         FROM   notes
         WHERE  id         = :note_id
           AND  user_id    = :user_id
           AND  deleted_at IS NULL
         LIMIT  1'
    );
    $stmt->execute([
        ':note_id' => $noteId,
        ':user_id' => $userId,
    ]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[delete_note.php] Fetch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $del = $pdo->prepare(
        'UPDATE notes
         SET    deleted_at = NOW()
         WHERE  id = :note_id AND user_id = :user_id'
    );
    $del->execute([
        ':note_id' => $noteId,
        ':user_id' => $userId,
    ]);

    if ($del->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Note not found or already deleted.']);
        exit;
    }
    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[delete_note.php] Delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete note. Please try again.']);
    exit;
}

if (!empty($note['file_path'])) {
    $absolutePath = realpath(
        dirname(__DIR__, 2) . '/' . ltrim($note['file_path'], '/')
    );

    $uploadsBase = realpath(dirname(__DIR__, 2) . '/uploads');

    if (
        $absolutePath &&
        $uploadsBase  &&
        str_starts_with($absolutePath, $uploadsBase) &&
        is_file($absolutePath)
    ) {
        if (!unlink($absolutePath)) {
            error_log('[delete_note.php] Could not delete file: ' . $absolutePath);
        }
    }
}

http_response_code(200);
echo json_encode([
    'success' => true,
        'message' => 'Note "' . $note['name'] . '" deleted successfully.',
]);
