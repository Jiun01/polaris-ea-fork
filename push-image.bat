@echo off
"C:\Program Files\Git\usr\bin\bash.exe" --login -i -c "cd '%~dp0' && bash docker/build.sh --push"
pause
