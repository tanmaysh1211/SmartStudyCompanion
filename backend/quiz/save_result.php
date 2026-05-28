<?php
/**
 * backend/quiz/save_result.php
 * ─────────────────────────────────────────────────────────────
 * POST /backend/quiz/save_result.php
 * Authorization: Bearer <token>
 * Content-Type: application/json
 *
 * Request body:
 *   {
 *     "note_id" : int,   // which note the quiz was generated from
 *     "score"   : int,   // number of correct answers (e.g. 7)
 *     "total"   : int    // total number of questions  (e.g. 10)
 *   }
 *
 * The server recalculates `percent` — never trusting the client value.
 *
 * Returns JSON:
 *   Success → {
 *       "success"   : true,
 *       "result_id" : int,
 *       "percent"   : int,
 *       "label"     : string,   // "Outstanding" | "Great" | "Good" | "Keep Practicing"
 *       "message"   : string
 *   }
 *   Failure → { "success": false, "message": string }
 * ─────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

// ── Auth guard ────────────────────────────────────────────────
$authUser = requireAuth();          // exits with 401 if token invalid
$userId   = (int)$authUser['sub'];

// ── Only POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing JSON body.']);
    exit;
}

// ── Extract inputs ────────────────────────────────────────────
$noteId = isset($body['note_id']) ? (int)$body['note_id'] : 0;
$score  = isset($body['score'])   ? (int)$body['score']   : -1;
$total  = isset($body['total'])   ? (int)$body['total']   : 0;

// ── Validate: note_id ─────────────────────────────────────────
if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'note_id must be a positive integer.']);
    exit;
}

// ── Validate: score ───────────────────────────────────────────
if ($score < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'score must be 0 or greater.']);
    exit;
}

// ── Validate: total ───────────────────────────────────────────
if ($total <= 0 || $total > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'total must be between 1 and 100.']);
    exit;
}

if ($score > $total) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'score cannot exceed total.']);
    exit;
}

// ── Recalculate percent server-side ──────────────────────────
$percent = (int)round(($score / $total) * 100);
$percent = max(0, min(100, $percent));  // clamp 0-100

// ── Verify note exists and belongs to this user ───────────────
try {
    $noteStmt = $pdo->prepare(
        'SELECT id, name
         FROM   notes
         WHERE  id         = :note_id
           AND  user_id    = :user_id
           AND  deleted_at IS NULL
         LIMIT  1'
    );
    $noteStmt->execute([':note_id' => $noteId, ':user_id' => $userId]);
    $note = $noteStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[save_result.php] Note lookup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

if (!$note) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Note not found or access denied.']);
    exit;
}

// ── Insert quiz result row ────────────────────────────────────
try {
    $insert = $pdo->prepare(
        'INSERT INTO quiz_results
           (user_id, note_id, score, total, percent, created_at)
         VALUES
           (:user_id, :note_id, :score, :total, :percent, NOW())'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':note_id' => $noteId,
        ':score'   => $score,
        ':total'   => $total,
        ':percent' => $percent,
    ]);

    $resultId = (int)$pdo->lastInsertId();

} catch (PDOException $e) {
    error_log('[save_result.php] Insert error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save quiz result. Please try again.']);
    exit;
}

// ── Performance label ─────────────────────────────────────────
$label = match(true) {
    $percent >= 90 => 'Outstanding',
    $percent >= 75 => 'Great',
    $percent >= 50 => 'Good',
    default        => 'Keep Practicing',
};

// ── Success ───────────────────────────────────────────────────
http_response_code(201);
echo json_encode([
    'success'   => true,
    'result_id' => $resultId,
    'percent'   => $percent,
    'label'     => $label,
    'message'   => "Saved! You scored {$score}/{$total} ({$percent}%) — {$label}!",
]);