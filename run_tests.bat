@echo off
set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" (
    set PHP_BIN=php
)

"%PHP_BIN%" "%~dp0run_tests.php"
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Unit Tests failed!
    exit /b %ERRORLEVEL%
)

