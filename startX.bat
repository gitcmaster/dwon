@echo off
:: 挂载 VHDX 并解锁 BitLocker

:: DiskPart 挂载
(echo select vdisk file="C:\secure\secret.vhdx"
echo attach vdisk) | diskpart

:: 解锁 BitLocker
manage-bde -unlock X: -password

:: 打开资源管理器
explorer X:\