<?php
// trigger_sync.php

// Spawn a completely independent background process.
// We use OS detection to support both local Windows testing and Linux live deployment (e.g. Render).
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows local environment
    exec("wscript.exe " . __DIR__ . "\\run_bg.vbs");
} else {
    // Linux live server environment (Docker/Render)
    exec("nohup php " . __DIR__ . "/sync_runner.php > /dev/null 2>&1 &");
}

echo json_encode(["status" => "started", "message" => "Sync process safely launched in background"]);
