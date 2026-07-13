<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$apiKey    = getenv('OPENAI_API_KEY')
          ?: ($_ENV['OPENAI_API_KEY']    ?? '')
          ?: ($_SERVER['OPENAI_API_KEY'] ?? '');

$model     = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
$pythonBin = getenv('PYTHON_BIN')   ?: 'python';
$scriptPath = realpath(__DIR__ . '/../../ai/chat_assistant.py');

echo json_encode([
    'apiKey_first12' => substr($apiKey, 0, 12),
    'apiKey_length'  => strlen($apiKey),
    'model'          => $model,
    'pythonBin'      => $pythonBin,
    'scriptPath'     => $scriptPath,
    'scriptExists'   => file_exists($scriptPath ?? '') ? 'YES' : 'NO',
    'ENV_keys'       => array_keys($_ENV),
    'SYSTEMROOT'     => getenv('SYSTEMROOT'),
    'PATH_first50'   => substr(getenv('PATH') ?: '', 0, 50),
]);
