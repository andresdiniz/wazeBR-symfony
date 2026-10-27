@echo off
SET PHP=C:\php\php.exe
SET APP=C:\xampp\htdocs\wazebr
SET LOG=%APP%\var\log

:: Cria pasta de log se não existir
if not exist "%LOG%" mkdir "%LOG%"

:: Alertas Waze
%PHP% %APP%\bin\console app:waze:collect-feed --env=prod >> "%LOG%\waze-feed.log" 2>&1

:: CEMADEN
%PHP% %APP%\bin\console cemaden:collect --env=prod >> "%LOG%\cemaden.log" 2>&1

:: Notificações de alto risco
%PHP% %APP%\bin\console waze:notify:high-risk --env=prod >> "%LOG%\notify.log" 2>&1