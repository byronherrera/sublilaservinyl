@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title Sublilaservinyl - Bajar actualizaciones - pull

echo.
echo ==================================================
echo    Sublilaservinyl  ::  BAJAR ACTUALIZACIONES
echo ==================================================
echo.

rem ---------- 1) Verificar que es un repositorio Git ----------
git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Esta carpeta no es un repositorio Git.
    goto :fin
)

rem ---------- 2) Estado local antes de descargar ----------
set "HAYCAMBIOS=0"
for /f "usebackq delims=" %%i in (`git status --porcelain`) do set "HAYCAMBIOS=1"

if "%HAYCAMBIOS%"=="1" (
    echo [AVISO] Tienes cambios locales sin confirmar:
    git status --short
    echo         Se intentara descargar de todos modos. Si hay conflictos,
    echo         ejecuta primero subir.bat para respaldar tu trabajo.
    echo.
)

rem ---------- 3) Descargar actualizaciones ----------
set "RAMA="
for /f "usebackq delims=" %%i in (`git rev-parse --abbrev-ref HEAD`) do set "RAMA=%%i"
set "HAYEUPSTREAM=0"
git rev-parse --abbrev-ref --symbolic-full-name "@{upstream}" >nul 2>&1 && set "HAYEUPSTREAM=1"

echo Descargando actualizaciones del remoto...
if "%HAYEUPSTREAM%"=="1" (
    git pull
) else (
    git pull origin "%RAMA%"
)
if errorlevel 1 (
    echo.
    echo [ERROR] No se pudo descargar. Revisa tu conexion o resuelve los conflictos.
    goto :fin
)

echo.
echo [OK] Actualizaciones descargadas.
echo.

rem ---------- 4) Ultimos commits ----------
echo Ultimos commits:
git log -5 --pretty=format:"    %%h  %%ad  %%s" --date=format:"%%Y-%%m-%%d %%H:%%M"
echo.
echo.

:fin
echo Presiona una tecla para cerrar...
pause >nul
endlocal
