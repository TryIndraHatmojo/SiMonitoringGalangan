@echo off
setlocal EnableExtensions DisableDelayedExpansion
pushd "%~dp0" || exit /b 1

set "APP_URL=http://127.0.0.1:8000"

set "STEP=Memeriksa Git, PHP, Composer, Node.js, npm, PowerShell, dan curl"
for %%T in (git composer node npm powershell curl.exe) do (
    where %%T >nul 2>&1 || (
        echo %%T tidak ditemukan di PATH.
        goto :failed
    )
)
php -r "exit(PHP_VERSION_ID >= 80401 ? 0 : 1);" >nul 2>&1
if not errorlevel 1 goto :php_ready

rem Jika clone ada di folder www Laragon, cari PHP yang kompatibel.
for /d %%D in ("%~dp0..\..\bin\php\*") do (
    if exist "%%~fD\php.exe" (
        "%%~fD\php.exe" -r "exit(PHP_VERSION_ID >= 80401 ? 0 : 1);" >nul 2>&1 && (
            set "PATH=%%~fD;%PATH%"
            goto :php_ready
        )
    )
)
echo PHP 8.4.1 atau lebih baru diperlukan. Tambahkan folder PHP ke PATH.
goto :failed

:php_ready
set "STEP=Memeriksa konfigurasi lokal"
if not exist .env (
    echo Siapkan .env dengan APP_KEY dan pengaturan database client.
    goto :failed
)
git rev-parse --show-toplevel >nul 2>&1 || (
    echo Jalankan file ini dari folder clone Git proyek.
    goto :failed
)
powershell -NoProfile -Command "$ErrorActionPreference = 'Stop'; $listeners = [Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners(); if ($listeners.Port -contains 8000) { exit 1 }" || (
    echo Port 8000 sudah dipakai atau tidak dapat dibuka. Tutup server sebelumnya.
    goto :failed
)
git diff --quiet -- && git diff --cached --quiet --
if errorlevel 1 (
    echo Ada perubahan lokal pada file yang dilacak Git. Simpan perubahan sebelum update.
    goto :failed
)

set "STEP=Memperbarui kode dari Git"
echo [1/5] %STEP%...
git pull --ff-only || goto :failed

set "STEP=Memasang dependensi PHP dan membersihkan cache konfigurasi"
echo [2/5] %STEP%...
call composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader || goto :failed
php artisan config:clear --no-interaction || goto :failed

set "STEP=Memasang dependensi frontend dan membangun aset"
echo [3/5] %STEP%...
call npm ci --include=dev || goto :failed
call npm run build || goto :failed
if exist "public\hot" del /q "public\hot"
if exist "public\hot" goto :failed

set "STEP=Menjalankan migrasi database"
echo [4/5] %STEP%...
php artisan migrate --force --no-interaction || goto :failed

set "STEP=Menjalankan server Laravel"
echo [5/5] %STEP%...
echo Browser akan terbuka di %APP_URL% saat server siap. Tekan Ctrl+C untuk berhenti.
start "" /b powershell -NoProfile -Command "curl.exe --noproxy '*' --fail --silent --output NUL --max-time 2 --retry 30 --retry-connrefused --retry-delay 1 --retry-max-time 35 ($env:APP_URL + '/up'); if ($LASTEXITCODE -eq 0) { Start-Process $env:APP_URL } else { Write-Host 'Server belum siap. Periksa keluaran Laravel.' }" || goto :failed
php artisan serve --host=127.0.0.1 --port=8000 --tries=1 --no-reload --no-interaction || goto :failed

popd
exit /b 0

:failed
echo.
echo Gagal: %STEP%.
popd
pause
exit /b 1
