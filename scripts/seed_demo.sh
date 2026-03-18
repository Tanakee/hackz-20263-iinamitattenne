#!/bin/sh
# ============================================================
# デモ用サンプルデータ投入スクリプト
# 使い方: sh scripts/seed_demo.sh
# ============================================================

set -e

DB_CONTAINER="hackz-db"
DB_USER="hackz_user"
DB_PASS="hackz_password"
DB_NAME="hackz_db"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "🌊 デモデータを投入します..."

# コンテナが起動しているか確認
if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
  echo "❌ ${DB_CONTAINER} コンテナが起動していません。先に docker-compose up を実行してください。"
  exit 1
fi

docker exec -i "$DB_CONTAINER" \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  < "$SCRIPT_DIR/demo_seed.sql"

echo "✅ デモデータの投入が完了しました！"
echo ""
echo "投入内容:"
echo "  [熱量高め] 3件 — 青い境界線リング表示"
echo "  [風化進行] 3件 — 熱量低下・グレースケール化"
echo "  [消失寸前] 2件 — 風化度80〜90%・脈動アニメーション"
echo "  [風]       2件 — AI要約メッセージが流れる"
echo ""
echo "👉 http://localhost:5173 をブラウザで開いて確認してください"
