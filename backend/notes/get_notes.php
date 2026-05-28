<?php
/**
 * backend/notes/get_notes.php
 * ─────────────────────────────────────────────────────────────
 * GET /backend/notes/get_notes.php
 * Authorization: Bearer <token>
 *
 * Optional query parameters:
 *   ?search=keyword     Filter by note name (LIKE search)
 *   ?sort=newest        newest | oldest | name  (default: newest)
 *   ?page=1             Page number (1-based, default: 1)
 *   ?per_page=20        Results per page (max: 100, default: 20)
 *
 * Returns JSON:
 *   Success → {
 *     "success": true,
 *     "notes": [ { id, name, file_type, file_size, upload_date, created_at } ],
 *     "total": int,
 *     "page": int,
 *     "per_page": int,
 *     "total_pages": int
 *   }
 *   Failure → { "success": false, "message": "..." }
 *
 * Note: 'content' is intentionally excluded from the list
 * response — it is only returned by get_note_by_id.php to
 * keep list payloads small.
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

// ── Only GET ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Query parameters ──────────────────────────────────────────
$search   = trim((string)($_GET['search']   ?? ''));
$sort     = trim((string)($_GET['sort']     ?? 'newest'));
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset   = ($page - 1) * $perPage;

// ── Validate sort parameter ───────────────────────────────────
$allowedSorts = [
    'newest' => 'n.created_at DESC',
    'oldest' => 'n.created_at ASC',
    'name'   => 'n.name ASC',
];

$orderBy = $allowedSorts[$sort] ?? $allowedSorts['newest'];

// ── Build WHERE clause ────────────────────────────────────────
$whereClauses = ['n.user_id = :user_id', 'n.deleted_at IS NULL'];
$params       = [':user_id' => $userId];

if ($search !== '') {
    $whereClauses[] = 'n.name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

$whereSQL = implode(' AND ', $whereClauses);

// ── Count total matching rows (for pagination) ────────────────
try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notes n WHERE {$whereSQL}"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

} catch (PDOException $e) {
    error_log('[get_notes.php] Count query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

// ── Fetch notes page ──────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT
            n.id,
            n.name,
            n.file_type,
            n.file_size,
            DATE_FORMAT(n.upload_date, '%m/%d/%Y') AS upload_date,
            DATE_FORMAT(n.created_at,  '%m/%d/%Y') AS created_at,
            n.file_path,
            (SELECT COUNT(*) FROM quiz_results qr WHERE qr.note_id = n.id) AS quiz_count
         FROM notes n
         WHERE {$whereSQL}
         ORDER BY {$orderBy}
         LIMIT :limit OFFSET :offset"
    );

    // Bind named params first
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    // Bind integer params (LIMIT / OFFSET must be integers with PDO)
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

    $stmt->execute();
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[get_notes.php] Fetch query error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

// ── Cast types ────────────────────────────────────────────────
$notes = array_map(static function (array $note): array {
    $note['id']         = (int)$note['id'];
    $note['quiz_count'] = (int)$note['quiz_count'];
    return $note;
}, $notes);

// ── Response ──────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success'     => true,
    'notes'       => $notes,
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $perPage,
    'total_pages' => (int)ceil($total / $perPage),
]);