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

3. **初回起動時の待機**
   Javaコンテナがビルドされるため、3～5分程度かかります。
   以下のメッセージが表示されたら完了：
   ```
   hackz-gravity-api | 2024-XX-XX XX:XX:XX.XXX  INFO XXXX --- [main] com.hackz.GravityApiApplication
   ```

4. **ブラウザでアクセス**
   - フロントエンド: http://localhost:5173
   - 重力API: http://localhost:8080/api/health
   - 風化API: http://localhost:8000/health
   - DB（ポート 3306）: ローカルでのみアクセス可能

## 📁 ディレクトリ構成

```
.
├── docker-compose.yml        # 全サービスの起動定義
├── frontend/                 # Vue.js フロントエンド
│   ├── src/
│   │   ├── App.vue          # メインコンポーネント
│   │   └── main.js
│   ├── package.json
│   └── vite.config.js
├── gravity-api/              # Java / Spring Boot API
│   ├── src/main/java/com/hackz/
│   │   └── GravityApiApplication.java
│   ├── build.gradle
│   └── settings.gradle
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
Spring Boot の Gradle ビルドが重いため、初回は時間がかかります。
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
