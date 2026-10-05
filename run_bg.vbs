Set WshShell = CreateObject("WScript.Shell")
WshShell.Run "cmd /c " & chr(34) & "C:\xampp\php\php.exe" & Chr(34) & " " & Chr(34) & "D:\Manik_WorkSpace2k26\bestbuy\sync_runner.php" & Chr(34) & " >> " & Chr(34) & "D:\Manik_WorkSpace2k26\bestbuy\shopify_import.log" & Chr(34) & " 2>&1", 0
Set WshShell = Nothing
