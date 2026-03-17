const express = require('express');
const cors = require('cors');
const bodyParser = require('body-parser');

const app = express();
const PORT = 8080;

// ミドルウェア
app.use(cors());
app.use(bodyParser.json());

// ヘルスチェック
app.get('/api/health', (req, res) => {
  res.json({ status: 'Gravity API is running!' });
});

// 質量計算エンドポイント
app.post('/api/calculate-mass', (req, res) => {
  try {
    const { text } = req.body;

    if (!text || text.trim().length === 0) {
      return res.status(400).json({ error: 'Text is required' });
    }

    const mass = calculateMass(text);

    res.json({
      mass: mass,
      message: '質量を計算しました',
      text_length: text.length,
      gravity: mass * 0.1 // 重力値（簡易計算）
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// 質量計算ロジック
function calculateMass(text) {
  // バリデーション：空文字または1文字以下は質量0を返す
  if (!text || text.length <= 1) {
    return 0;
  }

  // 基本的な質量 = 文字数 × 係数（係数: 5.0）
  const baseMass = text.length * 5.0;

  // 小数第1位で丸める
  return Math.round(baseMass * 10) / 10;
}

// エラーハンドリング
app.use((err, req, res, next) => {
  console.error(err.stack);
  res.status(500).json({ error: 'Internal server error' });
});

// サーバー起動
app.listen(PORT, '0.0.0.0', () => {
  console.log(`✨ Gravity API listening on port ${PORT}`);
  console.log(`🌊 重力計算を開始しました`);
});
