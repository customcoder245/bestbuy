<?php
$phpPath = "C:\\xampp\\php\\php.exe";
$scriptPath = __DIR__ . "\\shopify_import.php";
$logPath = __DIR__ . "\\sync.log";
$cmd = 'start /B "" "' . $phpPath . '" "' . $scriptPath . '" > "' . $logPath . '" 2>&1';
echo "Command: $cmd\n";
pclose(popen($cmd, "r"));
echo "Launched!\n";
