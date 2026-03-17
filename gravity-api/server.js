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

// 最小文字数の定義
const MIN_TEXT_LENGTH = 3;

// 質量計算エンドポイント
app.post('/api/calculate-mass', (req, res) => {
  try {
    const { text } = req.body;

    if (!text || text.trim().length === 0) {
      return res.status(400).json({ error: 'Text is required' });
    }

    if (text.trim().length < MIN_TEXT_LENGTH) {
      return res.status(400).json({ 
        error: `Text must be at least ${MIN_TEXT_LENGTH} characters long` 
      });
    }

    const result = calculateMass(text);

    res.json({
      mass: result.total_mass,
      message: '質量を計算しました',
      text_length: text.length,
      gravity: result.total_mass * 0.1, // 重力値（簡易計算）
      breakdown: {
        base_mass: result.base_mass,
        emotion_bonus: result.emotion_bonus,
        length_bonus: result.length_bonus,
        paragraph_bonus: result.paragraph_bonus
      }
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// 質量計算ロジック
function calculateMass(text) {
  // 基本的な質量 = 文字数 × 係数
  const baseMass = text.length * 0.1;

  // 感情ボーナス（感動符号がある場合）
  const emotionBonus = (text.match(/[！!？?]/g) || []).length * 20;

  // 長さボーナス（100文字以上）
  const lengthBonus = text.length > 100 ? 30 : 0;

  // 改行ボーナス（複数段落）
  const paragraphBonus = (text.match(/\n/g) || []).length * 10;

  // 合計質量
  const totalMass = baseMass + emotionBonus + lengthBonus + paragraphBonus;

  return {
    base_mass: Math.round(baseMass * 10) / 10,
    emotion_bonus: emotionBonus,
    length_bonus: lengthBonus,
    paragraph_bonus: paragraphBonus,
    total_mass: Math.round(totalMass * 10) / 10
  };
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
