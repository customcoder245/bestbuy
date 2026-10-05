<?php
// trigger_sync.php

// Spawn a completely independent background process using VBScript
// This guarantees the 15-minute scraper won't be killed by server timeouts
// and it perfectly detaches from the HTTP request.
exec("wscript.exe " . __DIR__ . "\\run_bg.vbs");

echo json_encode(["status" => "started", "message" => "Sync process safely launched in background"]);
