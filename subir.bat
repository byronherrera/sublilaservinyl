@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title Sublilaservinyl - Subir cambios - commit y push

echo.
echo ==================================================
echo    Sublilaservinyl  ::  SUBIR CAMBIOS
echo ==================================================
echo.

rem ---------- 1) Verificar que es un repositorio Git ----------
git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Esta carpeta no es un repositorio Git.
    goto :fin
)

rem ---------- 2) Fecha y hora de hoy ----------
set "HOY="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd'"`) do set "HOY=%%i"
if not defined HOY set "HOY=2000-01-01"
set "HORA="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "Get-Date -Format 'HH:mm:ss'"`) do set "HORA=%%i"
if not defined HORA set "HORA=00:00:00"

echo Fecha de hoy: %HOY%    Hora: %HORA%
echo.

rem ---------- 3) Contar commits de hoy ----------
set "COMMITS=0"
for /f %%c in ('git rev-list --count --since="%HOY% 00:00:00" HEAD 2^>nul') do set "COMMITS=%%c"
if not defined COMMITS set "COMMITS=0"

rem ---------- 4) Nombre del archivo segun los commits de hoy ----------
rem    0 commits hoy  -> primer commit del dia  -> subir-AAAA-MM-DD.bat
rem    1 o mas        -> ya hubo commit hoy      -> subir.bat
set "NOMBRE=subir.bat"
if "%COMMITS%"=="0" set "NOMBRE=subir-%HOY%.bat"
echo Commits de hoy: %COMMITS%
echo Nombre del archivo: %NOMBRE%
echo.

rem ---------- 5) Comprobar si hay cambios ----------
set "HAYCAMBIOS=0"
for /f "usebackq delims=" %%i in (`git status --porcelain`) do set "HAYCAMBIOS=1"

set "MSG=%~1"
if not defined MSG set "MSG=Actualizacion %HOY% %HORA%"

if "%HAYCAMBIOS%"=="0" (
    echo [INFO] No hay cambios locales para confirmar.
    echo.
) else (
    echo Cambios a confirmar:
    git status --short
    echo.
    git add -A
    if errorlevel 1 (
        echo [ERROR] Fallo al preparar los archivos con git add.
        goto :fin
    )
    echo Creando commit: %MSG%
    git commit -m "%MSG%"
    if errorlevel 1 (
        echo [ERROR] Fallo al crear el commit.
        goto :fin
    )
    echo.
)

rem ---------- 6) Subir al repositorio remoto ----------
set "RAMA="
for /f "usebackq delims=" %%i in (`git rev-parse --abbrev-ref HEAD`) do set "RAMA=%%i"
set "HAYEUPSTREAM=0"
git rev-parse --abbrev-ref --symbolic-full-name "@{upstream}" >nul 2>&1 && set "HAYEUPSTREAM=1"

echo Enviando cambios al remoto...
if "%HAYEUPSTREAM%"=="1" (
    git push
) else (
    echo [INFO] Configurando rama %RAMA% como upstream...
    git push --set-upstream origin "%RAMA%"
)
if errorlevel 1 (
    echo.
    echo [AVISO] No se pudo subir.
    echo         Si el remoto tiene cambios nuevos, ejecuta bajar.bat primero
    echo         y despues vuelve a correr este archivo.
) else (
    echo.
    echo [OK] Cambios subidos correctamente.
)
echo.

rem ---------- 7) Ultimo commit ----------
git log -1 --pretty=format:"    %%h  %%s  -  %%an, %%ad" --date=format:"%%Y-%%m-%%d %%H:%%M"
echo.
echo.

:fin
echo Presiona una tecla para cerrar...
pause >nul

endlocal
