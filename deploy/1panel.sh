#!/usr/bin/env bash
# ============================================================
# ai.xalcy.cn —— 1Panel (Docker) 运维脚本
#
# 背景: 1Panel 的 PHP 是跑在 Docker 容器里的，宿主机没有 php 命令，
#       因此 scripts/setup.php 必须在 PHP 容器内执行；同时容器内的
#       127.0.0.1 不等于宿主机，数据库 host 需要特别处理。
#
# 在 1Panel 所在服务器上以 root 执行:
#   bash deploy/1panel.sh doctor                  # 环境自检(推荐第一步)
#   bash deploy/1panel.sh db-import               # 建库并导入 sql/schema.sql
#   bash deploy/1panel.sh setup --user=admin --pass='密码'   # 初始化管理员与推送令牌
#   bash deploy/1panel.sh php -v                  # 在 PHP 容器内执行任意 php 命令
#   bash deploy/1panel.sh sh                      # 进入 PHP 容器 shell
#   bash deploy/1panel.sh list                    # 列出相关容器
#
# 可用环境变量覆盖（自动探测失败时使用）:
#   PROJECT_DIR=/opt/1panel/www/sites/ai.xalcy.cn/index
#   PHP_CONTAINER=1Panel-php8      MYSQL_CONTAINER=mysql
# ============================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${PROJECT_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)}"

# 1Panel 的站点目录映射：宿主机 /opt/1panel/www  →  容器内 /www
HOST_WWW_ROOT="${HOST_WWW_ROOT:-/opt/1panel/www}"
CONTAINER_WWW_ROOT="${CONTAINER_WWW_ROOT:-/www}"

C_RED=$'\033[31m'; C_GRN=$'\033[32m'; C_YLW=$'\033[33m'; C_CYN=$'\033[36m'; C_OFF=$'\033[0m'
info(){ printf '%s==> %s%s\n' "$C_CYN" "$*" "$C_OFF"; }
ok(){   printf '%s  [OK]   %s%s\n' "$C_GRN" "$*" "$C_OFF"; }
warn(){ printf '%s  [注意] %s%s\n' "$C_YLW" "$*" "$C_OFF"; }
err(){  printf '%s  [异常] %s%s\n' "$C_RED" "$*" "$C_OFF"; }
die(){  printf '%s错误: %s%s\n' "$C_RED" "$*" "$C_OFF" >&2; exit 1; }

# ---- 基础探测 ----
detect_php_container(){
  if [[ -n "${PHP_CONTAINER:-}" ]]; then printf '%s\n' "$PHP_CONTAINER"; return 0; fi
  local all
  all="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -iE 'php' || true)"
  [[ -z "$all" ]] && return 1
  # 优先 php8 系列（本项目要求 PHP 8+），例如 1Panel 默认的 php8-fpm
  local p8
  p8="$(printf '%s\n' "$all" | grep -iE 'php8' | head -n1)"
  if [[ -n "$p8" ]]; then printf '%s\n' "$p8"; else printf '%s\n' "$all" | head -n1; fi
}
detect_mysql_container(){
  if [[ -n "${MYSQL_CONTAINER:-}" ]]; then printf '%s\n' "$MYSQL_CONTAINER"; return 0; fi
  docker ps --format '{{.Names}}' 2>/dev/null | grep -iE 'mysql|mariadb' | head -n1
}

# 自动探测 宿主机→容器 的目录映射（取 Source 命中项目路径的那条挂载）
# 1Panel 通常把 /opt/1panel/www 挂到容器内 /www，但不同版本可能不同，故直接问 docker。
MOUNT_SRC=""
MOUNT_DST=""
init_mount(){
  MOUNT_SRC=""; MOUNT_DST=""
  local php; php="$(detect_php_container)"
  [[ -z "$php" ]] && return 1
  local line src dst
  while IFS=: read -r src dst; do
    [[ -z "$src" || -z "$dst" ]] && continue
    if [[ "$PROJECT_DIR" == "$src" || "$PROJECT_DIR" == "$src"/* ]]; then
      MOUNT_SRC="$src"; MOUNT_DST="$dst"; return 0
    fi
  done < <(docker inspect "$php" --format '{{range .Mounts}}{{.Source}}:{{.Destination}}{{"\n"}}{{end}}' 2>/dev/null)
  return 1
}

# 宿主机路径 → 容器内路径
to_container_path(){
  local p="$1"
  if [[ -n "$MOUNT_SRC" && -n "$MOUNT_DST" && ( "$p" == "$MOUNT_SRC" || "$p" == "$MOUNT_SRC"/* ) ]]; then
    printf '%s%s\n' "$MOUNT_DST" "${p#"$MOUNT_SRC"}"
    return
  fi
  if [[ "$p" == "$HOST_WWW_ROOT"* ]]; then
    printf '%s%s\n' "$CONTAINER_WWW_ROOT" "${p#"$HOST_WWW_ROOT"}"
  else
    printf '%s\n' "$p"
  fi
}
# 交互式终端才加 -t，脚本/CI 环境加 -t 会报 "the input device is not a TTY"
tty_flag(){ if [[ -t 0 ]]; then printf '%s' '-it'; else printf '%s' '-i'; fi; }

require_docker(){
  command -v docker >/dev/null 2>&1 || die "本机没有 docker 命令；本脚本仅适用于 1Panel 的 Docker 部署方式。"
  docker info >/dev/null 2>&1 || die "docker 不可用（无权限或守护进程未运行）。"
}

# 读 config.php 里的数据库信息（在 PHP 容器内解析，避免在 bash 里解析 PHP）
read_db_conf(){
  local php cpath
  php="$(detect_php_container)"
  [[ -n "$php" ]] || die "未找到 PHP 容器。"
  cpath="$(to_container_path "$PROJECT_DIR")"
  docker exec "$php" php -r '
    $c = require $argv[1];
    echo implode("|", [
      $c["db"]["name"] ?? "", $c["db"]["user"] ?? "", $c["db"]["pass"] ?? "", $c["db"]["host"] ?? "", (string)($c["db"]["port"] ?? 3306)
    ]);
  ' "$cpath/config.php" 2>/dev/null
}

# ------------------------------------------------------------
cmd_list(){
  require_docker
  info "当前容器"
  docker ps --format '  {{.Names}}\t{{.Image}}\t{{.Status}}'
}

# ------------------------------------------------------------
cmd_doctor(){
  require_docker

  info "1) 识别容器"
  local php mysql
  php="$(detect_php_container)"; mysql="$(detect_mysql_container)"
  if [[ -n "$php" ]]; then ok "PHP 容器: $php"; else err "未发现 PHP 容器（1Panel → 运行环境 中安装 PHP 后创建站点）"; fi
  if [[ -n "$mysql" ]]; then ok "MySQL 容器: $mysql"; else warn "未发现 MySQL/MariaDB 容器（数据库在宿主或外部时可忽略）"; fi

  info "2) 项目路径"
  [[ -d "$PROJECT_DIR" ]] || die "宿主机上找不到项目目录: $PROJECT_DIR（可用 PROJECT_DIR=/opt/1panel/www/sites/ai.xalcy.cn/index 指定）"
  local cpath; cpath="$(to_container_path "$PROJECT_DIR")"
  ok "宿主机: $PROJECT_DIR"
  ok "容器内: $cpath"
  if [[ -n "$MOUNT_SRC" ]]; then
    ok "挂载映射: $MOUNT_SRC → $MOUNT_DST（由容器实际挂载推导）"
  else
    warn "未从容器挂载推导出映射，回退默认 ${HOST_WWW_ROOT} → ${CONTAINER_WWW_ROOT}"
  fi
  [[ -f "$PROJECT_DIR/config.php" ]] && ok "config.php 存在" || err "缺少 config.php（cp config.example.php config.php 后填写）"
  [[ -f "$PROJECT_DIR/sql/schema.sql" ]] && ok "sql/schema.sql 存在" || err "缺少 sql/schema.sql"

  info "3) 容器内可见性"
  if [[ -n "$php" ]]; then
    if docker exec "$php" test -d "$cpath" 2>/dev/null; then
      ok "容器内可以访问项目目录"
    else
      err "容器内看不到 $cpath —— 确认项目是否放在 $HOST_WWW_ROOT 之下"
    fi
    docker exec "$php" php -v 2>/dev/null | head -n1 | sed 's/^/    /'
    local mods
    mods="$(docker exec "$php" php -m 2>/dev/null | grep -ixE 'pdo_mysql|mysqli|openssl|json|mbstring|curl' | tr '\n' ' ')"
    [[ "$mods" == *pdo_mysql* ]] && ok "扩展: $mods" || err "缺少 pdo_mysql 扩展，无法连接 MySQL"
  fi

  info "4) 数据库连通性（在 PHP 容器内实测，找出可用的 db.host）"
  if [[ -n "$php" ]] && docker exec "$php" test -f "$cpath/scripts/check_db.php" 2>/dev/null; then
    docker exec "$(tty_flag)" "$php" true 2>/dev/null || true
    docker exec -i "$php" php "$cpath/scripts/check_db.php" ${mysql:+"$mysql"} 2>&1 | sed 's/^/    /'
  else
    warn "跳过：缺少 PHP 容器或 scripts/check_db.php"
  fi

  info "5) Web 根目录暴露检查"
  local domain; domain="$(basename "$(dirname "$PROJECT_DIR")")"
  [[ "$domain" == "." || -z "$domain" ]] && domain="ai.xalcy.cn"
  if command -v curl >/dev/null 2>&1; then
    local u code bad=0
    for u in "/sql/schema.sql" "/README.md" "/config.php" "/src/db.php" "/.workbuddy/memory/"; do
      code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://$domain$u" 2>/dev/null || echo 000)"
      case "$code" in
        403|404|410) ok "https://$domain$u → $code（已阻断）" ;;
        200)         err "https://$domain$u → 200 可被公网访问！"; bad=1 ;;
        000)         warn "https://$domain$u → 无法连接（域名未解析 / 未启用 HTTPS，可忽略）" ;;
        *)           warn "https://$domain$u → HTTP $code" ;;
      esac
    done
    if [[ $bad -eq 1 ]]; then
      printf '\n'
      warn "修复方法（1Panel 面板操作）：网站 → ai.xalcy.cn → 设置 → 网站目录 → 运行目录 选择 /public，保存后重载。"
      warn "原理：项目根只在 /public 之下暴露，src/ sql/ scripts/ config.php 都在 web 根之外。"
    fi
  else
    warn "未安装 curl，跳过公网可达性检查"
  fi

  printf '\n'
  info "自检完成。若第 4 步给出了『请把 config.php 的 db.host 改为 xxx』，先改配置再执行 db-import / setup。"
}

# ------------------------------------------------------------
cmd_db_import(){
  require_docker
  local mysql; mysql="$(detect_mysql_container)"
  [[ -n "$mysql" ]] || die "未发现 MySQL 容器；可改用 1Panel 面板『数据库 → 导入』功能导入 sql/schema.sql。"
  local sql="$PROJECT_DIR/sql/schema.sql"
  [[ -f "$sql" ]] || die "找不到 $sql"

  info "读取 config.php 中的数据库配置"
  local conf; conf="$(read_db_conf)"
  [[ -z "$conf" ]] && die "无法解析 config.php，请确认 PHP 容器可访问项目目录。"
  local DB_NAME DB_USER DB_PASS DB_HOST DB_PORT
  IFS='|' read -r DB_NAME DB_USER DB_PASS DB_HOST DB_PORT <<<"$conf"
  ok "数据库: $DB_NAME  用户: $DB_USER  端口: $DB_PORT"

  local rootpass="${MYSQL_ROOT_PASS:-}"
  if [[ -z "$rootpass" ]]; then
    printf '请输入 MySQL root 密码（1Panel → 数据库 页面可查看）: '
    read -rs rootpass; printf '\n'
  fi
  [[ -n "$rootpass" ]] || die "未提供 root 密码。"

  info "创建数据库（若不存在）"
  docker exec -i "$mysql" mysql -uroot -p"$rootpass" \
    -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
    || die "连接 MySQL 失败：root 密码是否正确？"

  info "导入 $sql"
  warn "schema.sql 含 DROP TABLE 语句，重复导入会清空已有数据（首次部署无影响）。"
  docker exec -i "$mysql" mysql -uroot -p"$rootpass" "$DB_NAME" < "$sql" \
    || die "导入失败，请检查 schema.sql 与 MySQL 版本（需 5.7+ / 8.0）。"

  info "校验表结构"
  docker exec -i "$mysql" mysql -uroot -p"$rootpass" -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" \
    | sed 's/^/    已创建表数量: /'
  ok "导入完成。接着执行: bash deploy/1panel.sh setup --user=admin --pass='你的密码'"
}

# ------------------------------------------------------------
cmd_setup(){
  require_docker
  local php cpath
  php="$(detect_php_container)"; [[ -n "$php" ]] || die "未找到 PHP 容器。"
  cpath="$(to_container_path "$PROJECT_DIR")"
  docker exec "$php" test -f "$cpath/scripts/setup.php" 2>/dev/null \
    || die "容器内找不到 $cpath/scripts/setup.php"
  [[ $# -gt 0 ]] || die "缺少参数。用法: bash deploy/1panel.sh setup --user=admin --pass='密码'"
  info "在 PHP 容器 [$php] 内执行 setup.php"
  docker exec $(tty_flag) "$php" php "$cpath/scripts/setup.php" "$@"
}

# ------------------------------------------------------------
cmd_php(){
  require_docker
  local php cpath
  php="$(detect_php_container)"; [[ -n "$php" ]] || die "未找到 PHP 容器。"
  cpath="$(to_container_path "$PROJECT_DIR")"
  [[ $# -gt 0 ]] || die "用法: bash deploy/1panel.sh php -v  |  php -l src/auth.php"
  docker exec -i -w "$cpath" "$php" php "$@"
}

cmd_sh(){
  require_docker
  local php; php="$(detect_php_container)"; [[ -n "$php" ]] || die "未找到 PHP 容器。"
  info "进入容器 $php（容器内项目路径: $(to_container_path "$PROJECT_DIR")）"
  docker exec -it "$php" sh
}

# ------------------------------------------------------------
usage(){
  sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

main(){
  [[ $# -gt 0 ]] || usage 1
  local cmd="$1"; shift
  case "$cmd" in
    doctor|check) cmd_doctor "$@" ;;
    db-import|import) cmd_db_import "$@" ;;
    setup|init)   cmd_setup "$@" ;;
    php)          cmd_php "$@" ;;
    sh|shell)     cmd_sh "$@" ;;
    list)         cmd_list "$@" ;;
    -h|--help|help) usage 0 ;;
    *) die "未知命令: $cmd（可用: doctor / db-import / setup / php / sh / list）" ;;
  esac
}

main "$@"
