<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
ob_start();
set_time_limit(300);
ini_set('memory_limit', '512M');

function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);
    $buffer = ob_get_clean();
    if ($buffer && json_decode($buffer) === null) {
        $buffer = '';
    }
    echo $buffer . json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';
require_once __DIR__ . '/../auth/verify_token.php';

$authUser = requireAuth();
$userId   = (int)$authUser['sub'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => 'Method not allowed.'], 405);
}

define('MAX_FILE_SIZE_BYTES', (int)(getenv('MAX_FILE_SIZE_MB') ?: 20) * 1024 * 1024);
define('ALLOWED_EXTENSIONS',  ['pdf', 'txt', 'md']);
define('UPLOAD_BASE_DIR',     rtrim(getenv('UPLOAD_DIR') ?: __DIR__ . '/../../uploads', '/\\'));
define('PYTHON_BIN',          getenv('PYTHON_BIN') ?: 'python3');

$extractScript = realpath(__DIR__ . '/../../ai/extract_pdf.py');
if ($extractScript === false) {
    sendJson(['success' => false, 'message' => 'extract_pdf.py not found.'], 500);
}
define('EXTRACT_PDF_SCRIPT', $extractScript);

$noteName = trim((string)($_POST['note_name'] ?? ''));
if ($noteName === '') {
    sendJson(['success' => false, 'message' => 'Note name is required.'], 400);
}
if (mb_strlen($noteName) > 120) {
    sendJson(['success' => false, 'message' => 'Note name must not exceed 120 characters.'], 400);
}

$pastedText    = trim((string)($_POST['pasted_text'] ?? ''));
$uploadedFiles = $_FILES['files'] ?? [];
$hasFiles      = !empty($uploadedFiles['name'][0]);
$hasPasted     = $pastedText !== '';

if (!$hasFiles && !$hasPasted) {
    sendJson(['success' => false, 'message' => 'Please upload a file or paste some text.'], 400);
}

function getExtension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function extractTextFromFile(array $fileInfo): array
{
    $tmpPath  = $fileInfo['tmp_name'];
    $origName = $fileInfo['name'];
    $fileSize = (int)$fileInfo['size'];
    $ext      = getExtension($origName);

    if ($fileSize > MAX_FILE_SIZE_BYTES) {
        $maxMb = MAX_FILE_SIZE_BYTES / 1024 / 1024;
        return ['success' => false, 'message' => "\"{$origName}\" exceeds the {$maxMb} MB limit."];
    }

    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return ['success' => false, 'message' => "\"{$origName}\": unsupported type. Use PDF, TXT, or MD."];
    }

    // ── PDF ───────────────────────────────────────────────────
    if ($ext === 'pdf') {

        // ── Write Python output to a TEMP FILE to avoid shell_exec buffer limits ──
        // shell_exec() truncates output above ~2MB on Windows.
        // A 101-page PDF produces ~18MB of base64 JSON — way over the limit.
        // Solution: redirect Python stdout to a temp file, then read the file in PHP.

        $tmpJsonFile = tempnam(sys_get_temp_dir(), 'pdf_extract_') . '.json';

        $pythonBin  = PYTHON_BIN;
        $scriptPath = EXTRACT_PDF_SCRIPT;
        $pdfPath    = $tmpPath;

        // Build command — redirect stdout to temp file, stderr to NUL
        $cmd = "\"{$pythonBin}\" \"{$scriptPath}\" \"{$pdfPath}\" > \"{$tmpJsonFile}\" 2>&1";

        // Execute — we don't care about the return value here
        // pclose/popen gives us exit code; we check the output file instead
        $handle   = popen($cmd, 'r');
        $exitCode = pclose($handle);

        // Verify output file was created and has content
        if (!file_exists($tmpJsonFile)) {
            return ['success' => false, 'message' => 'Python did not create output file. CMD: ' . $cmd];
        }

        $fileBytes = filesize($tmpJsonFile);
        if ($fileBytes === 0) {
            @unlink($tmpJsonFile);
            return ['success' => false, 'message' => 'Python output file is empty. Exit code: ' . $exitCode];
        }

        error_log("[extract_pdf] Output file size: {$fileBytes} bytes");

        // Read the full JSON from the temp file
        $jsonStr = file_get_contents($tmpJsonFile);
        @unlink($tmpJsonFile); // clean up immediately

        if ($jsonStr === false || trim($jsonStr) === '') {
            return ['success' => false, 'message' => 'Could not read Python output file.'];
        }

        // Strip any stray content before/after the JSON object
        $trimmed   = trim($jsonStr);
        $jsonStart = strpos($trimmed, '{');
        $jsonEnd   = strrpos($trimmed, '}');

        if ($jsonStart === false || $jsonEnd === false) {
            return ['success' => false, 'message' => 'No JSON found in output: ' . substr($trimmed, 0, 200)];
        }

        $jsonStr = substr($trimmed, $jsonStart, $jsonEnd - $jsonStart + 1);

        // Decode — use JSON_BIGINT_AS_STRING to avoid precision loss on large strings
        $result = json_decode($jsonStr, true, 512, JSON_BIGINT_AS_STRING);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[extract_pdf] json_decode error: ' . json_last_error_msg() . ' on ' . strlen($jsonStr) . ' bytes');
            return [
                'success' => false,
                'message' => 'JSON decode error: ' . json_last_error_msg() . ' (' . strlen($jsonStr) . ' bytes)',
            ];
        }

        if (empty($result['success']) || !isset($result['text'])) {
            return ['success' => false, 'message' => 'Extraction failed: ' . ($result['message'] ?? 'unknown')];
        }

        // Store HTML content exactly as-is — contains base64 PNG images
        return [
            'success'   => true,
            'text'      => $result['text'],
            'file_type' => 'PDF Document',
            'orig_name' => $origName,
            'file_size' => $fileSize,
        ];
    }

    // ── TXT / MD ──────────────────────────────────────────────
    $text = file_get_contents($tmpPath);
    if ($text === false) {
        return ['success' => false, 'message' => "Could not read \"{$origName}\"."];
    }

    $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $encoding);
    }

    return [
        'success'   => true,
        'text'      => $text,
        'file_type' => $ext === 'md' ? 'Markdown File' : 'Text File',
        'orig_name' => $origName,
        'file_size' => $fileSize,
    ];
}

// ── Process uploaded files ────────────────────────────────────
$combinedText    = '';
$primaryFileType = 'Text';
$totalSize       = 0;
$primaryOrigName = '';
$fileList        = [];

if ($hasFiles) {
    $count = count($uploadedFiles['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($uploadedFiles['error'][$i] !== UPLOAD_ERR_OK) {
            $errCodes = [
                UPLOAD_ERR_INI_SIZE   => 'exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE  => 'exceeds form size limit',
                UPLOAD_ERR_PARTIAL    => 'was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'no file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'missing temp directory',
                UPLOAD_ERR_CANT_WRITE => 'failed to write to disk',
                UPLOAD_ERR_EXTENSION  => 'upload stopped by extension',
            ];
            $reason = $errCodes[$uploadedFiles['error'][$i]] ?? 'unknown error';
            sendJson(['success' => false, 'message' => "File \"{$uploadedFiles['name'][$i]}\" {$reason}."], 400);
        }
        $fileList[] = [
            'tmp_name' => $uploadedFiles['tmp_name'][$i],
            'name'     => $uploadedFiles['name'][$i],
            'type'     => $uploadedFiles['type'][$i],
            'size'     => $uploadedFiles['size'][$i],
        ];
    }

    foreach ($fileList as $idx => $fileInfo) {
        $result = extractTextFromFile($fileInfo);
        if (!$result['success']) {
            sendJson(['success' => false, 'message' => $result['message']], 400);
        }
        $combinedText .= $result['text'];
        $totalSize    += $result['file_size'];
        if ($idx === 0) {
            $primaryFileType = $result['file_type'];
            $primaryOrigName = $result['orig_name'];
        }
    }
}

if ($hasPasted) {
    $combinedText .= ($combinedText !== '' ? "\n\n---\n\n" : '') . $pastedText;
    if (!$hasFiles) {
        $primaryFileType = 'Text (Pasted)';
        $totalSize       = strlen($pastedText);
    }
}

$combinedText = trim($combinedText);
if ($combinedText === '') {
    sendJson(['success' => false, 'message' => 'No readable text could be extracted.'], 400);
}

function formatFileSize(int $bytes): string
{
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
}

$displaySize = formatFileSize($totalSize ?: strlen($combinedText));

// ── Persist original file ─────────────────────────────────────
$storedFilePath = null;
if ($hasFiles && isset($fileList[0])) {
    $userDir = UPLOAD_BASE_DIR . '/' . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }
    $safeFilename   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $primaryOrigName);
    $uniqueFilename = time() . '_' . $safeFilename;
    $destPath       = $userDir . '/' . $uniqueFilename;
    if (move_uploaded_file($fileList[0]['tmp_name'], $destPath)) {
        $storedFilePath = 'uploads/' . $userId . '/' . $uniqueFilename;
    }
}

// ── Save to database ──────────────────────────────────────────
// IMPORTANT: MySQL's max_allowed_packet must be large enough to store
// the base64 HTML content (~18MB for a 101-page PDF).
// Run this in MySQL if inserts fail:
//   SET GLOBAL max_allowed_packet = 67108864;  -- 64 MB
// Or add to my.ini: max_allowed_packet = 64M
try {
    $stmt = $pdo->prepare(
        'INSERT INTO notes
           (user_id, name, content, file_type, file_size, file_path, upload_date, created_at)
         VALUES
           (:user_id, :name, :content, :file_type, :file_size, :file_path, CURDATE(), NOW())'
    );
    $stmt->execute([
        ':user_id'   => $userId,
        ':name'      => $noteName,
        ':content'   => $combinedText,
        ':file_type' => $primaryFileType,
        ':file_size' => $displaySize,
        ':file_path' => $storedFilePath,
    ]);
    $noteId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('[upload_note.php] DB insert: ' . $e->getMessage());
    sendJson(['success' => false, 'message' => 'Failed to save note. DB error: ' . $e->getMessage()], 500);
}

sendJson(['success' => true, 'note_id' => $noteId, 'message' => "Note \"{$noteName}\" uploaded successfully."], 201);