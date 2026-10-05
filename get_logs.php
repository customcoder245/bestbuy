<?php
// get_logs.php
$logFile = __DIR__ . '/shopify_import.log';

if (!file_exists($logFile)) {
    echo "Waiting for logs... (No sync has run yet)";
    exit;
}

// Efficiently read the last 100 lines without loading the whole file into memory
function tailCustom($filepath, $lines = 100) {
    $f = @fopen($filepath, "rb");
    if ($f === false) return false;
    
    // Seek to the end
    fseek($f, -1, SEEK_END);
    
    $output = '';
    $lineCount = 0;
    
    while (ftell($f) > 0 && $lineCount < $lines) {
        $char = fgetc($f);
        if ($char === "\n") {
            $lineCount++;
        }
        $output = $char . $output;
        fseek($f, -2, SEEK_CUR);
    }
    
    fclose($f);
    return $output;
}

$logs = tailCustom($logFile, 150);
if (empty($logs)) {
    echo "Logs are empty.";
} else {
    echo htmlspecialchars(trim($logs));
}
