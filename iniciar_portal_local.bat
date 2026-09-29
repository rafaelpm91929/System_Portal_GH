@echo off
title Portal de Sistemas - Servidor Local
echo ====================================================================
echo   INICIANDO PORTAL DE SISTEMAS - GRUPO HUERTA (MODO LOCAL)
echo ====================================================================
echo.
echo  Directorio: %~dp0
echo  URL:        http://localhost:8000/login.php
echo.
echo  Presiona Ctrl+C en esta ventana cuando desees apagar el servidor.
echo ====================================================================
echo.

cd /d "%~dp0"

:: Abrir navegador automáticamente tras 1 segundo
start "" http://localhost:8000/login.php

:: Iniciar servidor embebido de PHP en puerto 8000
php -S localhost:8000
pause
