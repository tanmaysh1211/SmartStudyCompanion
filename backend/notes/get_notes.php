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

$search   = trim((string)($_GET['search']   ?? ''));
$sort     = trim((string)($_GET['sort']     ?? 'newest'));
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset   = ($page - 1) * $perPage;

$allowedSorts = [
    'newest' => 'n.created_at DESC',
    'oldest' => 'n.created_at ASC',
    'name'   => 'n.name ASC',
];

$orderBy = $allowedSorts[$sort] ?? $allowedSorts['newest'];

$whereClauses = ['n.user_id = :user_id', 'n.deleted_at IS NULL'];
$params       = [':user_id' => $userId];

if ($search !== '') {
    $whereClauses[] = 'n.name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

$whereSQL = implode(' AND ', $whereClauses);

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

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
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

$notes = array_map(static function (array $note): array {
    $note['id']         = (int)$note['id'];
    $note['quiz_count'] = (int)$note['quiz_count'];
    return $note;
}, $notes);

http_response_code(200);
echo json_encode([
    'success'     => true,
    'notes'       => $notes,
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $perPage,
    'total_pages' => (int)ceil($total / $perPage),
]);
