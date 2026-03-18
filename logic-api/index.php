<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

function json_encode_utf8($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

// DB接続関数（シングルトン）
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'db';
        $user = getenv('DB_USER') ?: 'hackz_user';
        $pass = getenv('DB_PASSWORD') ?: 'hackz_password';
        $dbname = getenv('DB_NAME') ?: 'hackz_db';
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}

// リクエストのパース
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// シンプルなルーター
switch ($request_uri) {
    case '/health':
        if ($request_method === 'GET') {
            http_response_code(200);
            echo json_encode_utf8(['status' => 'Logic API is running!']);
        }
        break;

    case '/test-db':
        if ($request_method === 'GET') {
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->query("SELECT 1 as test");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                http_response_code(200);
                echo json_encode_utf8(['status' => 'DB connection successful', 'result' => $result]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode_utf8(['error' => 'DB test failed: ' . $e->getMessage()]);
            }
        }
        break;

    case '/posts':
        if ($request_method === 'POST') {
            handleCreatePost();
        } elseif ($request_method === 'GET') {
            handleGetPosts();
        }
        break;

    case '/weathering':
        if ($request_method === 'POST') {
            handleWeatheringCheck();
        }
        break;

    case '/heat':
        if ($request_method === 'POST') {
            handleHeatCalculation();
        }
        break;

    case '/hot-topics':
        if ($request_method === 'GET') {
            handleGetHotTopics();
        }
        break;

        case '/winds':
        if ($request_method === 'POST') {
            handleCreateWind();
        } elseif ($request_method === 'GET') {
            handleGetWinds();
        }
        break;

    case '/demo-seed':
        if ($request_method === 'POST') {
            handleDemoSeed();
        }
        break;

    default:
        http_response_code(404);
        echo json_encode_utf8(['error' => 'Endpoint not found']);
        break;
}

// Gravity APIとの連携関数
function calculateMass($text) {
    $gravityApiUrl = getenv('GRAVITY_API_URL') ?: 'http://gravity-api:8080';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $gravityApiUrl . '/api/calculate-mass');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $text]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to calculate mass from Gravity API');
    }

    $data = json_decode($response, true);
    if (!isset($data['mass'])) {
        throw new Exception('Invalid response from Gravity API');
    }

    return $data['mass'];
}

// ハンドラー関数群

// 投稿作成ハンドラー
function handleCreatePost() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        // バリデーション
        if (!isset($input['text']) || empty(trim($input['text']))) {
            http_response_code(400);
            echo json_encode_utf8(['error' => 'Text is required and cannot be empty']);
            return;
        }

        if (!isset($input['x']) || !is_numeric($input['x'])) {
            http_response_code(400);
            echo json_encode_utf8(['error' => 'Valid x coordinate is required']);
            return;
        }

        if (!isset($input['y']) || !is_numeric($input['y'])) {
            http_response_code(400);
            echo json_encode_utf8(['error' => 'Valid y coordinate is required']);
            return;
        }

        $text = trim($input['text']);
        $x = (float)$input['x'];
        $y = (float)$input['y'];

        // Gravity APIで質量計算
        $mass = calculateMass($text);

        // DBに保存
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("INSERT INTO posts (text, x, y, mass, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$text, $x, $y, $mass]);

        $postId = $pdo->lastInsertId();

        // 保存したデータを取得してレスポンス
        $stmt = $pdo->prepare("SELECT id, text, x, y, mass, heat, weathered, created_at FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode_utf8([
            'success' => true,
            'post' => $post,
            'message' => 'Post created successfully'
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to create post: ' . $e->getMessage()]);
    }
}

function handleGetPosts() {
    try {
        $pdo = getDBConnection();

        // postsテーブルから全件取得、作成日時の降順でソート
        $stmt = $pdo->prepare("SELECT id, text, x, y, mass, heat, weathered, created_at FROM posts ORDER BY created_at DESC");
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // レスポンスとしてJSON配列を返却
        http_response_code(200);
        echo json_encode_utf8($posts);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to retrieve posts: ' . $e->getMessage()]);
    }
}

function handleWeatheringCheck() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['post_id'])) {
            http_response_code(400);
            echo json_encode_utf8(['error' => 'post_id is required']);
            return;
        }

        $post_id = $input['post_id'];
        $pdo = getDBConnection();

        // 対象の投稿を取得
        $stmt = $pdo->prepare("SELECT id, created_at, weathered FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
            http_response_code(404);
            echo json_encode_utf8(['error' => 'Post not found']);
            return;
        }

        $created_timestamp = strtotime($post['created_at']);
        $current_timestamp = time();
        $elapsed_time_seconds = max(0, $current_timestamp - $created_timestamp); // 負の数を防止

        // 風化度合い (0.0 〜 1.0)
        $weathering_degree = min($elapsed_time_seconds / 86400, 1.0);
        $is_weathered = ($elapsed_time_seconds >= 86400);

        // 未風化から風化状態に変わる場合はDBを更新
        if ($is_weathered && !$post['weathered']) {
            $updateStmt = $pdo->prepare("UPDATE posts SET weathered = TRUE WHERE id = ?");
            $updateStmt->execute([$post_id]);
        }

        http_response_code(200);
        echo json_encode_utf8([
            'weathered' => $is_weathered,
            'weathering_degree' => $weathering_degree,
            'elapsed_time_seconds' => $elapsed_time_seconds
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to check weathering: ' . $e->getMessage()]);
    }
}

function handleHeatCalculation() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['post_id'])) {
            http_response_code(400);
            echo json_encode_utf8(['error' => 'post_id is required']);
            return;
        }

        $post_id = $input['post_id'];
        $pdo = getDBConnection();

        // 1. 対象の投稿の座標(x,y)を取得
        $stmt = $pdo->prepare("SELECT id, x, y, mass FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $target_post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target_post) {
            http_response_code(404);
            echo json_encode_utf8(['error' => 'Post not found']);
            return;
        }

        $x = (float)$target_post['x'];
        $y = (float)$target_post['y'];

        // 2. 近接する投稿の数と質量の合計を算出 (自身を除く)
        // ここでは距離 100 以内を近接と定義
        $proximity_distance = 100;
        $nearbyStmt = $pdo->prepare("
            SELECT COUNT(*) as nearby_count, COALESCE(SUM(mass), 0) as nearby_mass_sum 
            FROM posts 
            WHERE id != ? 
            AND SQRT(POW(x - ?, 2) + POW(y - ?, 2)) <= ?
        ");
        $nearbyStmt->execute([$post_id, $x, $y, $proximity_distance]);
        $nearby_data = $nearbyStmt->fetch(PDO::FETCH_ASSOC);

        $nearby_count = (int)$nearby_data['nearby_count'];
        $nearby_mass_sum = (float)$nearby_data['nearby_mass_sum'];

        // 3. interactions テーブルからの反応数を取得
        $interactionsStmt = $pdo->prepare("
            SELECT COUNT(*) as interaction_count, COALESCE(SUM(value), 0) as interaction_value_sum 
            FROM interactions 
            WHERE post_id = ?
        ");
        $interactionsStmt->execute([$post_id]);
        $interactions_data = $interactionsStmt->fetch(PDO::FETCH_ASSOC);
        
        $interaction_count = (int)$interactions_data['interaction_count'];
        $interaction_value_sum = (float)$interactions_data['interaction_value_sum'];

        // 4. 熱量の計算ロジック
        // 基礎熱量を 10 とする
        // 近接投稿1つにつき +10, 近接投稿の質量の合計 * 0.5
        // インタラクション1つにつき +20, valueの合計 * 1.0
        $base_heat = 10;
        $nearby_score = ($nearby_count * 10) + ($nearby_mass_sum * 0.5);
        $interaction_score = ($interaction_count * 20) + ($interaction_value_sum * 1.0);

        $heat = $base_heat + $nearby_score + $interaction_score;

        // 5. posts テーブルの heat カラムに更新
        $updateStmt = $pdo->prepare("UPDATE posts SET heat = ? WHERE id = ?");
        $updateStmt->execute([$heat, $post_id]);

        http_response_code(200);
        echo json_encode_utf8([
            'heat' => $heat, 
            'details' => [
                'nearby_count' => $nearby_count,
                'nearby_mass_sum' => $nearby_mass_sum,
                'interaction_count' => $interaction_count,
                'interaction_value_sum' => $interaction_value_sum
            ],
            'message' => '熱量を計算しました'
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to calculate heat: ' . $e->getMessage()]);
    }
}

function handleGetHotTopics() {
    try {
        $pdo = getDBConnection();
        
        // 閾値の取得（環境変数から、なければデフォルト100）
        $threshold = (float)(getenv('HOT_TOPIC_THRESHOLD') ?: 100);

        // 風化しておらず、熱量が閾値以上の投稿を取得（熱量の降順）
        $stmt = $pdo->prepare("
            SELECT id, text, x, y, mass, heat, created_at 
            FROM posts 
            WHERE weathered = FALSE AND heat > ? 
            ORDER BY heat DESC
        ");
        $stmt->execute([$threshold]);
        $hot_topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode_utf8([
            'threshold' => $threshold,
            'count' => count($hot_topics),
            'hot_topics' => $hot_topics
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to retrieve hot topics: ' . $e->getMessage()]);
    }
}
// AI要約（風）をDBに保存する関数
function handleCreateWind() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['summary']) || !isset($input['post_ids'])) {
            http_response_code(400);
            echo json_encode_utf8(['error' => 'summary and post_ids are required']);
            return;
        }
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("INSERT INTO winds (summary, post_ids, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$input['summary'], $input['post_ids']]);
        http_response_code(201);
        echo json_encode_utf8(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to save wind: ' . $e->getMessage()]);
    }
}

// 保存されている要約一覧を取得する関数
function handleGetWinds() {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, summary, post_ids, created_at FROM winds ORDER BY created_at DESC");
        $stmt->execute();
        $winds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        http_response_code(200);
        echo json_encode_utf8($winds);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'Failed to retrieve winds: ' . $e->getMessage()]);
    }
}

// デモ用サンプルデータ投入
function handleDemoSeed() {
    try {
        $pdo = getDBConnection();

        // weatheredカラムをFLOATに変更
        $pdo->exec("ALTER TABLE posts MODIFY COLUMN weathered FLOAT DEFAULT 0");

        // 既存データをクリア
        $pdo->exec("DELETE FROM interactions");
        $pdo->exec("DELETE FROM winds");
        $pdo->exec("DELETE FROM posts");
        $pdo->exec("ALTER TABLE posts AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE interactions AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE winds AUTO_INCREMENT = 1");

        // posts データ
        $pdo->exec("INSERT INTO posts (text, x, y, mass, heat, weathered, created_at) VALUES
            ('SNSは民主主義を壊しているのか？！',        -0.55,  0.10,  95.0, 78.0, 0.00, NOW()),
            ('炎上は現代の焚き火である！！',              0.10, -0.40, 110.0, 65.0, 0.00, NOW()),
            ('AIに仕事を奪われたくない！！！',            0.45,  0.30,  80.0, 42.0, 0.05, NOW()),
            ('もっとゆっくり議論できる場所が欲しい',     -0.30,  0.45,  35.0, 12.0, 0.30, DATE_SUB(NOW(), INTERVAL 10 MINUTE)),
            ('エコーチェンバーを壊すにはどうすれば',      0.60, -0.20,  50.0,  8.0, 0.45, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
            ('匿名だから言えることもある',               -0.15, -0.55,  28.0,  5.0, 0.55, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
            ('バズることに意味はあるのか',                0.25,  0.55,  40.0,  2.0, 0.82, DATE_SUB(NOW(), INTERVAL 60 MINUTE)),
            ('誰も読まないコメントを書き続ける意味',     -0.50, -0.30,  22.0,  0.5, 0.91, DATE_SUB(NOW(), INTERVAL 90 MINUTE))
        ");

        // interactions データ
        $pdo->exec("INSERT INTO interactions (post_id, type, value, created_at) VALUES
            (1, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 5 MINUTE)),
            (1, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 3 MINUTE)),
            (1, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 1 MINUTE)),
            (2, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 8 MINUTE)),
            (2, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 4 MINUTE)),
            (3, 'wave', 1.0, DATE_SUB(NOW(), INTERVAL 6 MINUTE))
        ");

        // winds データ
        $pdo->exec("INSERT INTO winds (summary, post_ids, created_at) VALUES
            ('SNSと民主主義、炎上とAI——現代社会への問いが池に渦巻いている。あなたはどう思う？', '[1,2,3]', DATE_SUB(NOW(), INTERVAL 2 MINUTE)),
            ('静かな声も、やがて風化する。議論の場に熱を。', '[4,5,6]', DATE_SUB(NOW(), INTERVAL 15 MINUTE))
        ");

        http_response_code(200);
        echo json_encode_utf8([
            'success' => true,
            'message' => 'デモデータを投入しました',
            'counts' => ['posts' => 8, 'interactions' => 6, 'winds' => 2]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode_utf8(['error' => 'デモデータ投入に失敗: ' . $e->getMessage()]);
    }
}
?>
