@echo off
rem Instalador de FlexArena y SportTime para Windows: doble clic y elegir en el menu.
rem Tiene que estar en la misma carpeta que instalar_windows.ps1. Cualquier parametro se pasa al script,
rem por ejemplo:  Instalar.bat -Proyecto SportTime -SinDatosDemo
setlocal
cd /d "%~dp0"
if not exist "%~dp0instalar_windows.ps1" (
  echo No encuentro instalar_windows.ps1 junto a este archivo.
  pause
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0instalar_windows.ps1" %*
set CODIGO=%ERRORLEVEL%
echo.
pause
exit /b %CODIGO%
