-- ハッカソン用DB初期化スクリプト
-- 詳細なスキーマはDay 1で設計予定

USE hackz_db;

-- 既存のテストテーブルを削除
DROP TABLE IF EXISTS test_table;

-- 新しいpostsテーブルを作成
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    text TEXT NOT NULL,
    x FLOAT,
    y FLOAT,
    mass FLOAT,
    heat FLOAT DEFAULT 0,
    weathered BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- サンプルデータを挿入（postsテーブルに合わせて修正）
INSERT INTO posts (text) VALUES ('環境構築テスト');

-- 新しいinteractionsテーブルを作成
CREATE TABLE IF NOT EXISTS interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT,
    type ENUM('wave', 'wind'),
    value FLOAT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id)
);

-- windsテーブルを作成（AI要約結果を保存）
CREATE TABLE IF NOT EXISTS winds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    summary TEXT NOT NULL,
    post_ids TEXT NOT NULL, -- 関連する投稿IDのJSON文字列
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
