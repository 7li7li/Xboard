# Xboard Project Memory

## Local Startup

This workspace is a Laravel 12 project at:

```powershell
D:\Project\Github\xboard
```

Use the bundled PHP runtime in this project because global `php` may not be available:

```powershell
.\.tools\php\php.exe
```

For the current prepared workspace, start the app with:

```powershell
cd D:\Project\Github\xboard
.\.tools\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Open:

```text
http://127.0.0.1:8000
```

## Restart Existing Local Server

If an old `artisan serve` process is already running for this workspace, restart it with PowerShell:

```powershell
$project = 'D:\Project\Github\xboard'
$phpPath = Join-Path $project '.tools\php\php.exe'

Get-CimInstance Win32_Process | Where-Object {
  $_.ExecutablePath -eq $phpPath -and $_.CommandLine -like '*artisan serve*'
} | ForEach-Object {
  Stop-Process -Id $_.ProcessId -Force
}

Start-Process -FilePath $phpPath `
  -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') `
  -WorkingDirectory $project `
  -WindowStyle Hidden
```

## First-Time Setup

If the workspace is not prepared yet:

```powershell
cd D:\Project\Github\xboard
composer install
Copy-Item .env.example .env
.\.tools\php\php.exe artisan key:generate
.\.tools\php\php.exe artisan migrate --seed
.\.tools\php\php.exe artisan optimize:clear
```

The `.env` file expects MySQL and Redis by default:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xboard
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Quick Checks

Check the homepage:

```powershell
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8000/
```

Check Laravel routes or clear cache:

```powershell
.\.tools\php\php.exe artisan route:list
.\.tools\php\php.exe artisan optimize:clear
```
