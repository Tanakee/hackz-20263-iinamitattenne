-- ============================================================
-- デモ用サンプルデータ投入スクリプト
-- 実行: docker exec -i hackz-db mysql -u hackz_user -phackz_password hackz_db < scripts/demo_seed.sql
-- ============================================================

USE hackz_db;
SET NAMES utf8mb4;

-- 既存データをクリア
DELETE FROM interactions;
DELETE FROM winds;
DELETE FROM posts;
ALTER TABLE posts AUTO_INCREMENT = 1;
ALTER TABLE interactions AUTO_INCREMENT = 1;
ALTER TABLE winds AUTO_INCREMENT = 1;

-- ============================================================
-- posts データ
--
-- デモストーリー:
--   「SNS・議論・社会」をテーマに、様々な状態の石が池に沈んでいる
--
-- 状態の内訳:
--   [A] 熱量高め (heat >= 20) → 青い境界線リング、風化ゆっくり  3件
--   [B] 熱量低め (heat < 20)  → 風化進行中                      3件
--   [C] 風化末期 (weathered ≈ 0.8〜0.9) → 消失寸前の脈動        2件
-- ============================================================

INSERT INTO posts (text, x, y, mass, heat, weathered, created_at) VALUES

-- [A] 熱量高め・活発な議論
('SNSは民主主義を壊しているのか？！',        -0.55,  0.10,  95.0, 78.0, 0.00, NOW()),
('炎上は現代の焚き火である！！',              0.10, -0.40, 110.0, 65.0, 0.00, NOW()),
('AIに仕事を奪われたくない！！！',            0.45,  0.30,  80.0, 42.0, 0.05, NOW()),

-- [B] 熱量低め・風化進行中
('もっとゆっくり議論できる場所が欲しい',     -0.30,  0.45,  35.0, 12.0, 0.30, DATE_SUB(NOW(), INTERVAL 10 MINUTE)),
('エコーチェンバーを壊すにはどうすれば',      0.60, -0.20,  50.0,  8.0, 0.45, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
('匿名だから言えることもある',               -0.15, -0.55,  28.0,  5.0, 0.55, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),

-- [C] 風化末期・消失寸前
('バズることに意味はあるのか',                0.25,  0.55,  40.0,  2.0, 0.82, DATE_SUB(NOW(), INTERVAL 60 MINUTE)),
('誰も読まないコメントを書き続ける意味',     -0.50, -0.30,  22.0,  0.5, 0.91, DATE_SUB(NOW(), INTERVAL 90 MINUTE));

-- ============================================================
-- interactions データ（熱量高め投稿へのいいね履歴）
-- ============================================================

INSERT INTO interactions (post_id, type, value, created_at) VALUES
(1, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 5 MINUTE)),
(1, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 3 MINUTE)),
(1, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 1 MINUTE)),
(2, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 8 MINUTE)),
(2, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 4 MINUTE)),
(3, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 6 MINUTE));

-- ============================================================
-- winds データ（AI要約の風）
-- ============================================================

INSERT INTO winds (summary, post_ids, created_at) VALUES
('SNSと民主主義、炎上とAI——現代社会への問いが池に渦巻いている。あなたはどう思う？', '[1,2,3]', DATE_SUB(NOW(), INTERVAL 2 MINUTE)),
('静かな声も、やがて風化する。議論の場に熱を。',                                     '[4,5,6]', DATE_SUB(NOW(), INTERVAL 15 MINUTE));
