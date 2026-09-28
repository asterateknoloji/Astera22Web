@echo off
setlocal

echo.
echo ========================================
echo       GITHUB YEDEKLEME
echo ========================================
echo.

set /p MESSAGE="Yedekleme aciklamasi: "

if "%MESSAGE%"=="" (
    set "MESSAGE=Otomatik yedek"
)

for /f "tokens=1-2" %%a in ('powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm:ss'"') do set "DATETIME=%%a %%b"

echo.
echo Tarih/Saat : %DATETIME%
echo Aciklama   : %MESSAGE%
echo.

git add .

git diff --cached --quiet
if %ERRORLEVEL% EQU 0 (
    echo Degisiklik bulunmadi. GitHub'a yeni yedek gonderilmedi.
    echo.
    pause
    exit /b
)

git commit -m "%DATETIME% - %MESSAGE%"

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo COMMIT HATASI!
    pause
    exit /b 1
)

git push

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================
    echo       GITHUB YEDEGI BASARILI
    echo ========================================
) else (
    echo.
    echo ========================================
    echo       GITHUB PUSH HATASI
    echo ========================================
)

echo.
pause