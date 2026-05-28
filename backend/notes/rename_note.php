<?php
/**
 * backend/notes/rename_note.php
 * ─────────────────────────────────────────────────────────────
 * PUT /backend/notes/rename_note.php
 * Authorization: Bearer <token>
 * Content-Type: application/json
 *
 * Body:
 *   { "note_id": int, "new_name": "string" }
 *
 * Returns JSON:
 *   Success → { "success": true, "message": "...", "note": { id, name } }
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

// ── Only PUT ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!$body || !is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

// ── Extract & validate inputs ─────────────────────────────────
$noteId  = isset($body['note_id'])  ? (int)$body['note_id']           : 0;
$newName = isset($body['new_name']) ? trim((string)$body['new_name']) : '';

// note_id
if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid note_id is required.']);
    exit;
}

// new_name — required, length bounds
if ($newName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'new_name cannot be empty.']);
    exit;
}

if (mb_strlen($newName) < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Note name must be at least 1 character.']);
    exit;
}

if (mb_strlen($newName) > 120) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Note name must not exceed 120 characters.']);
    exit;
}

// Strip any HTML/script tags that slipped through
$newName = strip_tags($newName);

// ── Verify the note exists and is owned by this user ──────────
try {
    $check = $pdo->prepare(
        'SELECT id, name
         FROM   notes
         WHERE  id         = :note_id
           AND  user_id    = :user_id
           AND  deleted_at IS NULL
         LIMIT  1'
    );
    $check->execute([
        ':note_id' => $noteId,
        ':user_id' => $userId,
    ]);
    $note = $check->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[rename_note.php] Fetch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found.']);
    exit;
}

// ── No-op check: name is unchanged ───────────────────────────
if ($note['name'] === $newName) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Note name is already "' . $newName . '" — no change needed.',
        'note'    => [
            'id'   => (int)$note['id'],
            'name' => $note['name'],
        ],
    ]);
    exit;
}

// ── Check for duplicate name under the same user (optional) ──
// Comment this block out if you allow duplicate names.
try {
    $dupCheck = $pdo->prepare(
        'SELECT id FROM notes
         WHERE  user_id    = :user_id
           AND  name       = :name
           AND  id        != :note_id
           AND  deleted_at IS NULL
         LIMIT  1'
    );
    $dupCheck->execute([
        ':user_id' => $userId,
        ':name'    => $newName,
        ':note_id' => $noteId,
    ]);

    if ($dupCheck->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "You already have a note named "{$newName}". Please choose a different name.",
        ]);
        exit;
    }

} catch (PDOException $e) {
    error_log('[rename_note.php] Duplicate check error: ' . $e->getMessage());
    // Non-fatal — proceed with rename
}

// ── Perform rename ────────────────────────────────────────────
try {
    $update = $pdo->prepare(
        'UPDATE notes
         SET    name       = :new_name,
                updated_at = NOW()
         WHERE  id         = :note_id
           AND  user_id    = :user_id
           AND  deleted_at IS NULL'
    );
    $update->execute([
        ':new_name' => $newName,
        ':note_id'  => $noteId,
        ':user_id'  => $userId,
    ]);

    if ($update->rowCount() === 0) {
        // Row matched but nothing changed — treat as success
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No changes were made.',
            'note'    => ['id' => $noteId, 'name' => $newName],
        ]);
        exit;
    }

} catch (PDOException $e) {
    error_log('[rename_note.php] Update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to rename note. Please try again.']);
    exit;
}

// ── Success ───────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => "Note renamed to "{$newName}" successfully.",
    'note'    => [
        'id'   => $noteId,
        'name' => $newName,
    ],
]);