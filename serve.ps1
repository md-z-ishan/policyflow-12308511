Set-Location "e:\projectt"

$phpDir = "C:\Users\mdkam\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe"
$phpExe = "$phpDir\php.exe"

$paths = ($env:Path -split ';') | ForEach-Object { $_.Trim('"', ' ') } | Where-Object { $_ -ne '' }
$env:Path = "$phpDir;" + ($paths -join ';')

& $phpExe artisan serve --host=127.0.0.1 --port=8000
