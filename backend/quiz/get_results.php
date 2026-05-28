<?php
/**
 * backend/quiz/get_results.php
 * ─────────────────────────────────────────────────────────────
 * GET /backend/quiz/get_results.php
 * Authorization: Bearer <token>
 *
 * Optional query parameters:
 *   ?note_id=<int>      Filter results for a single note
 *   ?limit=<int>        Max rows returned (default 50, max 200)
 *   ?sort=newest        newest | oldest | score_asc | score_desc
 *
 * Returns JSON:
 *   {
 *     "success": true,
 *
 *     // Individual quiz attempt rows
 *     "results": [
 *       {
 *         "id"         : int,
 *         "note_id"    : int,
 *         "note_name"  : string,
 *         "score"      : int,
 *         "total"      : int,
 *         "percent"    : int,
 *         "label"      : string,   // Outstanding / Great / Good / Keep Practicing
 *         "date"       : string    // "MM/DD/YYYY"
 *       }, ...
 *     ],
 *
 *     // Aggregate stats (used by the Report page stat cards)
 *     "stats": {
 *       "notes_count"   : int,     // total notes uploaded by this user
 *       "quizzes_taken" : int,     // total completed quiz attempts
 *       "avg_score"     : float,   // average percent across all attempts
 *       "best_score"    : int,     // highest percent ever achieved
 *       "total_correct" : int,     // cumulative correct answers
 *       "total_answered": int      // cumulative total questions answered
 *     },
 *
 *     // Per-note breakdown (used by an optional per-note stats view)
 *     "by_note": [
 *       {
 *         "note_id"    : int,
 *         "note_name"  : string,
 *         "attempts"   : int,
 *         "avg_percent": float,
 *         "best"       : int,
 *         "latest_date": string
 *       }, ...
 *     ]
 *   }
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
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// ── Query parameters ──────────────────────────────────────────
$filterNoteId = isset($_GET['note_id']) ? (int)$_GET['note_id'] : 0;
$limit        = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$sort         = trim((string)($_GET['sort'] ?? 'newest'));

// Allowed sort maps
$sortMap = [
    'newest'     => 'qr.created_at DESC',
    'oldest'     => 'qr.created_at ASC',
    'score_asc'  => 'qr.percent ASC,  qr.created_at DESC',
    'score_desc' => 'qr.percent DESC, qr.created_at DESC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['newest'];

// ── Helper: performance label from percent ────────────────────
function performanceLabel(int $percent): string
{
    return match(true) {
        $percent >= 90 => 'Outstanding',
        $percent >= 75 => 'Great',
        $percent >= 50 => 'Good',
        default        => 'Keep Practicing',
    };
}

// ═══════════════════════════════════════════════════════════════
// 1. Individual quiz results
// ═══════════════════════════════════════════════════════════════
try {
    // Build WHERE
    $where  = 'qr.user_id = :user_id';
    $params = [':user_id' => $userId];

    if ($filterNoteId > 0) {
        $where            .= ' AND qr.note_id = :note_id';
        $params[':note_id'] = $filterNoteId;
    }

    $stmt = $pdo->prepare(
        "SELECT
             qr.id,
             qr.note_id,
             COALESCE(n.name, '[Deleted Note]') AS note_name,
             qr.score,
             qr.total,
             qr.percent,
             DATE_FORMAT(qr.created_at, '%m/%d/%Y') AS date,
             DATE_FORMAT(qr.created_at, '%Y-%m-%d %H:%i') AS datetime_raw
         FROM  quiz_results qr
         LEFT  JOIN notes n
               ON  n.id = qr.note_id AND n.deleted_at IS NULL
         WHERE {$where}
         ORDER BY {$orderBy}
         LIMIT :lim"
    );

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[get_results.php] Results fetch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error fetching results.']);
    exit;
}

// Decorate with label and cast types
$results = array_map(static function (array $row): array {
    $row['id']      = (int)$row['id'];
    $row['note_id'] = (int)$row['note_id'];
    $row['score']   = (int)$row['score'];
    $row['total']   = (int)$row['total'];
    $row['percent'] = (int)$row['percent'];
    $row['label']   = performanceLabel($row['percent']);
    unset($row['datetime_raw']); // internal field — don't expose
    return $row;
}, $rawResults);

// ═══════════════════════════════════════════════════════════════
// 2. Aggregate stats
// ═══════════════════════════════════════════════════════════════
try {
    // Overall quiz stats
    $statsStmt = $pdo->prepare(
        'SELECT
             COUNT(*)          AS quizzes_taken,
             ROUND(AVG(percent), 1) AS avg_score,
             MAX(percent)      AS best_score,
             SUM(score)        AS total_correct,
             SUM(total)        AS total_answered
         FROM quiz_results
         WHERE user_id = :user_id'
    );
    $statsStmt->execute([':user_id' => $userId]);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Notes count
    $notesCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM notes WHERE user_id = :user_id AND deleted_at IS NULL'
    );
    $notesCountStmt->execute([':user_id' => $userId]);
    $notesCount = (int)$notesCountStmt->fetchColumn();

} catch (PDOException $e) {
    error_log('[get_results.php] Stats query error: ' . $e->getMessage());
    // Non-fatal — return empty stats rather than failing the whole request
    $statsRow   = [];
    $notesCount = 0;
}

$stats = [
    'notes_count'    => $notesCount,
    'quizzes_taken'  => (int)($statsRow['quizzes_taken']  ?? 0),
    'avg_score'      => $statsRow['avg_score'] !== null ? (float)$statsRow['avg_score'] : 0.0,
    'best_score'     => (int)($statsRow['best_score']     ?? 0),
    'total_correct'  => (int)($statsRow['total_correct']  ?? 0),
    'total_answered' => (int)($statsRow['total_answered'] ?? 0),
];

// ═══════════════════════════════════════════════════════════════
// 3. Per-note breakdown
// ═══════════════════════════════════════════════════════════════
try {
    $byNoteStmt = $pdo->prepare(
        "SELECT
             qr.note_id,
             COALESCE(n.name, '[Deleted Note]')  AS note_name,
             COUNT(*)                            AS attempts,
             ROUND(AVG(qr.percent), 1)           AS avg_percent,
             MAX(qr.percent)                     AS best,
             DATE_FORMAT(MAX(qr.created_at), '%m/%d/%Y') AS latest_date
         FROM  quiz_results qr
         LEFT  JOIN notes n
               ON  n.id = qr.note_id AND n.deleted_at IS NULL
         WHERE qr.user_id = :user_id
         GROUP BY qr.note_id, n.name
         ORDER BY MAX(qr.created_at) DESC
         LIMIT 50"
    );
    $byNoteStmt->execute([':user_id' => $userId]);
    $rawByNote = $byNoteStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[get_results.php] By-note query error: ' . $e->getMessage());
    $rawByNote = [];
}

$byNote = array_map(static function (array $row): array {
    $row['note_id']    = (int)$row['note_id'];
    $row['attempts']   = (int)$row['attempts'];
    $row['avg_percent']= (float)$row['avg_percent'];
    $row['best']       = (int)$row['best'];
    return $row;
}, $rawByNote);

// ═══════════════════════════════════════════════════════════════
// 4. Score trend (last 10 attempts — used for the bar chart)
// ═══════════════════════════════════════════════════════════════
try {
    $trendStmt = $pdo->prepare(
        "SELECT
             qr.percent,
             COALESCE(n.name, '[Deleted Note]') AS note_name,
             DATE_FORMAT(qr.created_at, '%m/%d/%Y') AS date
         FROM  quiz_results qr
         LEFT  JOIN notes n ON n.id = qr.note_id AND n.deleted_at IS NULL
         WHERE qr.user_id = :user_id
         ORDER BY qr.created_at DESC
         LIMIT 10"
    );
    $trendStmt->execute([':user_id' => $userId]);
    $trend = array_reverse(          // chronological order for chart
        $trendStmt->fetchAll(PDO::FETCH_ASSOC)
    );

    $trend = array_map(static function (array $row): array {
        $row['percent'] = (int)$row['percent'];
        return $row;
    }, $trend);

} catch (PDOException $e) {
    error_log('[get_results.php] Trend query error: ' . $e->getMessage());
    $trend = [];
}

// ═══════════════════════════════════════════════════════════════
// 5. Response
// ═══════════════════════════════════════════════════════════════
http_response_code(200);
echo json_encode([
    'success' => true,
    'results' => $results,
    'stats'   => $stats,
    'by_note' => $byNote,
    'trend'   => $trend,
]);