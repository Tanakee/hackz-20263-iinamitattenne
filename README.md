# 🌊 いい波立ってんね～

議論を「物理現象」として捉える議論プラットフォーム

## 🏗️ システムアーキテクチャ

```
Frontend (Vue.js + Canvas)
    ↓
Gravity API (Node.js/Express) ← 質量計算
Logic API (PHP) ← 風化・熱量・DB連携
Database (MySQL)
```

## 🚀 環境構築

### 前提条件
- Docker Desktop がインストールされている
- Git がインストールされている
- VS Code（推奨）

### セットアップ手順

1. **リポジトリをクローン（またはフォルダを開く）**
   ```bash
   cd hackz-megaro-20263-iinamitattenne
   ```

2. **Docker イメージをビルド・起動**
   ```bash
   docker-compose up --build
   ```

3. **ブラウザでアクセス**
   - フロントエンド: http://localhost:5173
   - 重力API: http://localhost:8080/api/health
   - 風化API: http://localhost:8000/health
   - DB（ポート 3306）: ローカルでのみアクセス可能

## � API仕様

### Gravity API - 質量計算エンドポイント

#### 1. ヘルスチェック
```
GET /api/health
```

**レスポンス (200 OK)**
```json
{
  "status": "Gravity API is running!"
}
```

---

#### 2. 質量計算
```
POST /api/calculate-mass
```

**リクエスト例**
```json
{
  "text": "すごい！！！"
}
```

**レスポンス (200 OK)**
```json
{
  "mass": 120.6,
  "message": "質量を計算しました",
  "text_length": 6,
  "gravity": 12.06,
  "gravity_coefficient": 1.25,
  "character_ratio": {
    "kanji": 0.0,
    "hiragana": 0.5,
    "katakana": 0.0,
    "alnum": 0.5
  },
  "breakdown": {
    "base_mass": 0.6,
    "emotion_bonus": 120,
    "length_bonus": 0,
    "paragraph_bonus": 0
  }
}
```

**エラー: テスト未入力 (400 Bad Request)**
```json
{
  "error": "Text is required"
}
```

**エラー: テキストが短すぎる (400 Bad Request)**
```json
{
  "error": "Text must be at least 3 characters long"
}
```

---

### 計算ロジック

| 項目 | 計算式 | 説明 |
|-----|--------|------|
| 基本質量 | `text_length × 0.1` | テキストの文字数に比例 |
| 感情ボーナス | `（感嘆符 + 疑問符の個数） × 20` | ！! ？? の出現回数でボーナス |
| 長さボーナス | `30`（100文字以上のみ） | 長いテキストへのボーナス |
| 改行ボーナス | `改行数 × 10` | 複数段落のボーナス |

**最終質量** = 基本質量 + 感情ボーナス + 長さボーナス + 改行ボーナス

---

### バリデーションルール

- **必須**: `text` フィールドが必須
- **最小長**: 3文字以上（空白のみは除外）
- **最大長**: 制限なし

---

### Logic API - 投稿 / 熱量 / 風化エンドポイント

#### 1. 投稿一覧取得
```
GET /posts
```

**レスポンス (200 OK)**
```json
[
  {
    "id": 1,
    "text": "投稿内容",
    "x": 0.12,
    "y": -0.34,
    "mass": 12.3,
    "heat": 45,
    "weathered": false,
    "created_at": "2026-03-18 00:00:00"
  }
]
```

#### 2. 投稿作成
```
POST /posts
```

**リクエスト例**
```json
{
  "text": "意見を書きます",
  "x": 0.1,
  "y": -0.2,
  "mass": 12.3
}
```

**レスポンス (201 Created)**
```json
{
  "success": true,
  "post": {
    "id": 2,
    "text": "意見を書きます",
    "x": 0.1,
    "y": -0.2,
    "mass": 12.3,
    "heat": 0,
    "weathered": false,
    "created_at": "2026-03-18 00:00:00"
  },
  "message": "Post created successfully"
}
```

#### 3. 熱量計算
```
POST /heat
```

**リクエスト例**
```json
{ "post_id": 1 }
```

**レスポンス (200 OK)**
```json
{ "heat": 42, "message": "熱量を計算しました" }
```

#### 4. 風化判定
```
POST /weathering
```

**リクエスト例**
```json
{ "post_id": 1, "created_at": "2026-03-18 00:00:00" }
```

**レスポンス (200 OK)**
```json
{ "weathered": true }
```

## �📁 ディレクトリ構成

```
.
├── docker-compose.yml        # 全サービスの起動定義
├── frontend/                 # Vue.js フロントエンド
│   ├── src/
│   │   ├── App.vue          # メインコンポーネント
│   │   └── main.js
│   ├── package.json
│   └── vite.config.js
├── gravity-api/              # Node.js/Express 重力計算API
│   ├── server.js            # エンドポイント実装
│   └── package.json
├── logic-api/                # PHP API
│   └── index.php            # ハンドラー実装
├── db/                       # MySQL 初期化スクリプト
│   └── init.sql
└── README.md
```

## 🛠️ 開発時のコマンド

### すべてのコンテナを起動
```bash
docker-compose up
```

### バックグラウンドで起動
```bash
docker-compose up -d
```

### ログを確認
```bash
docker-compose logs -f [service-name]
# 例: docker-compose logs -f frontend
```

### コンテナを停止
```bash
docker-compose down
```

### 特定のコンテナに接続
```bash
docker exec -it [container-name] /bin/bash
# 例（PHP）: docker exec -it hackz-logic-api sh
```

## ⚠️ よくあるトラブル

### 1. "Bind for 0.0.0.0:XXXX failed"エラー
別のプロセスがポートを使用しています。以下を確認：
```bash
lsof -i :5173    # フロントエンド
lsof -i :8080    # 重力API
lsof -i :8000    # 風化API
lsof -i :3306    # DB
```

### 2. "Gravity API" が起動しない
ログを確認：
```bash
docker-compose logs gravity-api
```

### 3. CORS エラーが出る
Docker Compose 内部では、以下のURLを使用：
- Vue.js → Gravity API: `http://gravity-api:8080`
- Vue.js → Logic API: `http://logic-api:8000`
- Logic API → Gravity API: `http://gravity-api:8080`

## 🎯 Day 1 タスク（環境構築後）

- [ ] DBスキーマ設計（posts, comments, interactions テーブル等）
- [ ] Vue.js コンポーネント実装（石、波紋、風のUI）
- [ ] 重力API：文字数・感情度から質量を計算するロジック
- [ ] 風化API：時間経過と熱量から風化判定するロジック

## 🆘 質問・詰まった時

- AIアシスタント（Claude）に「エラーメッセージとコード」をそのまま貼って相談
- Dockerが動かない場合は、素早く他の技術スタックへの変更も検討

---

**健闘を祈ります！** 🚀
