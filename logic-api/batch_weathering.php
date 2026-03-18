<?php
// コマンドラインからのみ実行可能にする
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// DB接続関数を index.php からインクルードする代わりに自己完結させる（バッチ処理用）
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'db';
        $user = getenv('DB_USER') ?: 'hackz_user';
        $pass = getenv('DB_PASSWORD') ?: 'hackz_password';
        $dbname = getenv('DB_NAME') ?: 'hackz_db';
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}

try {
    echo "Starting weathering batch update...\n";
    $pdo = getDBConnection();

    // weathered が false で、作成から24時間経過している投稿を検索
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE weathered = FALSE AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= 86400");
    $stmt->execute();
    $posts_to_update = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($posts_to_update)) {
        echo "No posts require weathering update.\n";
        exit(0);
    }

    // UPDATEクエリを実行
    $placeholders = implode(',', array_fill(0, count($posts_to_update), '?'));
    $updateStmt = $pdo->prepare("UPDATE posts SET weathered = TRUE WHERE id IN ($placeholders)");
    $updateStmt->execute($posts_to_update);

    $updated_count = $updateStmt->rowCount();
    echo "Successfully updated $updated_count post(s) to weathered state.\n";

} catch (Exception $e) {
    echo "Error updating posts: " . $e->getMessage() . "\n";
    exit(1);
}
