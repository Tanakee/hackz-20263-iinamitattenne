-- ハッカソン用DB初期化スクリプト
-- 詳細なスキーマはDay 1で設計予定

USE hackz_db;

-- テスト用テーブル（Day 1で削除・置き換え予定）
CREATE TABLE IF NOT EXISTS test_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO test_table (name) VALUES ('環境構築テスト');
