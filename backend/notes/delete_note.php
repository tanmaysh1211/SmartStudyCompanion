<?php
/**
 * backend/notes/delete_note.php
 * ─────────────────────────────────────────────────────────────
 * DELETE /backend/notes/delete_note.php
 * Authorization: Bearer <token>
 * Content-Type: application/json
 *
 * Body:
 *   { "note_id": int }
 *
 * Behaviour:
 *   • Soft-delete: sets deleted_at = NOW() on the note row.
 *   • The physical file on disk is also removed (if it exists).
 *   • Associated quiz_results rows are NOT deleted so the
 *     Report page can still show historical scores (they are
 *     simply orphaned). Change to hard-delete if you prefer.
 *
 * Returns JSON:
 *   Success → { "success": true,  "message": "Note deleted." }
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

// ── Only DELETE ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
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

// ── Fetch note to verify ownership & get file path ────────────
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

// ── Note not found or not owned by this user ──────────────────
if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found.']);
    exit;
}

// ── Soft-delete in a transaction ──────────────────────────────
try {
    $pdo->beginTransaction();

    // Soft-delete the note
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

    // Optional: hard-delete quiz results for this note
    // Uncomment if you prefer to fully purge associated data:
    //
    // $pdo->prepare('DELETE FROM quiz_results WHERE note_id = :note_id')
    //     ->execute([':note_id' => $noteId]);

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[delete_note.php] Delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete note. Please try again.']);
    exit;
}

// ── Remove physical file from disk (best-effort) ──────────────
if (!empty($note['file_path'])) {
    // file_path stored relative to project root: 'uploads/<user_id>/<filename>'
    $absolutePath = realpath(
        dirname(__DIR__, 2) . '/' . ltrim($note['file_path'], '/')
    );

    // Security: ensure the path is inside the uploads directory
    $uploadsBase = realpath(dirname(__DIR__, 2) . '/uploads');

    if (
        $absolutePath &&
        $uploadsBase  &&
        str_starts_with($absolutePath, $uploadsBase) &&
        is_file($absolutePath)
    ) {
        if (!unlink($absolutePath)) {
            error_log('[delete_note.php] Could not delete file: ' . $absolutePath);
            // Non-fatal — the DB row is already soft-deleted
        }
    }
}

// ── Success ───────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    // 'message' => "Note "{$note['name']}" deleted successfully.",
    // ✅ FIXED
        'message' => 'Note "' . $note['name'] . '" deleted successfully.',
]);