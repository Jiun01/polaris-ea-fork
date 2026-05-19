@echo off
"C:\Program Files\Git\usr\bin\bash.exe" -c "cd \"$(dirname \"$0\")\" && bash docker/build.sh --push"
pause
