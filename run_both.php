<?php
echo "Starting deletion...\n";
system("C:\\xampp\\php\\php.exe shopify_delete_all.php");
echo "\nStarting sync...\n";
system("C:\\xampp\\php\\php.exe shopify_import.php");
echo "\nDone!\n";

