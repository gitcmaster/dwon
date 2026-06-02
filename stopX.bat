@echo off
:: 锁定 BitLocker
manage-bde -lock X:

:: DiskPart 卸载
(echo select vdisk file="C:\secure\secret.vhdx"
echo detach vdisk) | diskpart

:: 备份
rem xcopy /y D:\secure\secret.vhdx D:\backup\secret_backup_%date:~0,10%.vhdx