<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// DB接続関数（シングルトン）
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

// リクエストのパース
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// シンプルなルーター
switch ($request_uri) {
    case '/health':
        if ($request_method === 'GET') {
            http_response_code(200);
            echo json_encode(['status' => 'Logic API is running!']);
        }
        break;

    case '/test-db':
        if ($request_method === 'GET') {
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->query("SELECT 1 as test");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                http_response_code(200);
                echo json_encode(['status' => 'DB connection successful', 'result' => $result]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'DB test failed: ' . $e->getMessage()]);
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

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
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
            echo json_encode(['error' => 'Text is required and cannot be empty']);
            return;
        }

        if (!isset($input['x']) || !is_numeric($input['x'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid x coordinate is required']);
            return;
        }

        if (!isset($input['y']) || !is_numeric($input['y'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid y coordinate is required']);
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
        echo json_encode([
            'success' => true,
            'post' => $post,
            'message' => 'Post created successfully'
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create post: ' . $e->getMessage()]);
    }
}

function handleGetPosts() {
    // テンプレート実装：Day 1でDBに接続
    $posts = [
        [
            'id' => '1',
            'text' => 'これはテスト投稿です',
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
            'heat' => 10,
            'weathered' => false
        ]
    ];

    http_response_code(200);
    echo json_encode($posts);
}

function handleWeatheringCheck() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['post_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'post_id is required']);
        return;
    }

    // 簡易的な風化判定（24時間以上経過したら風化）
    $created_timestamp = strtotime($input['created_at'] ?? 'now');
    $current_timestamp = time();
    $is_weathered = ($current_timestamp - $created_timestamp) > 86400;

    http_response_code(200);
    echo json_encode(['weathered' => $is_weathered]);
}

function handleHeatCalculation() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['post_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'post_id is required']);
        return;
    }

    // テンプレート実装：複数の投稿から熱量を集計
    $heat = rand(10, 100);

    http_response_code(200);
    echo json_encode(['heat' => $heat, 'message' => '熱量を計算しました']);
}
?>
