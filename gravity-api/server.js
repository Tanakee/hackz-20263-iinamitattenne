const express = require('express');
const cors = require('cors');
const bodyParser = require('body-parser');
const mysql = require('mysql2/promise');
const OpenAI = require('openai');
const http = require('http');
const { WebSocketServer } = require('ws');

const app = express();
const PORT = 8080;
const server = http.createServer(app);
const NLP_API_URL = process.env.NLP_API_URL || 'http://localhost:8001';

// DB接続プール
const dbConfig = {
  host: process.env.DB_HOST || 'db',
  user: process.env.DB_USER || 'hackz_user',
  password: process.env.DB_PASSWORD || 'hackz_password',
  database: process.env.DB_NAME || 'hackz_db',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
};
const pool = mysql.createPool(dbConfig);

// OpenAI クライアント初期化
const openai = new OpenAI({
  apiKey: process.env.OPENAI_API_KEY,
});

// ミドルウェア
app.use(cors());
app.use(bodyParser.json());

// ヘルスチェック
app.get('/api/health', (req, res) => {
  res.json({ status: 'Gravity API is running!' });
});

// GET /winds - 最新の風一覧を返却
app.get('/api/winds', async (req, res) => {
  try {
    const [rows] = await pool.execute(
      'SELECT id, summary, post_ids, created_at FROM winds ORDER BY created_at DESC LIMIT 50'
    );
    res.json(rows);
  } catch (error) {
    console.error('Database error:', error);
    res.status(500).json({ error: 'Failed to fetch winds' });
  }
});

// 最小文字数の定義
const MIN_TEXT_LENGTH = 3;

// 質量計算エンドポイント
app.post('/api/calculate-mass', async (req, res) => {
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

    // NLP APIで主語のデカさを取得
    let subjectScale = 30;
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 3000);
      const nlpRes = await fetch(`${NLP_API_URL}/subject-scale`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text }),
        signal: controller.signal,
      });
      clearTimeout(timeout);
      if (nlpRes.ok) {
        const nlpData = await nlpRes.json();
        subjectScale = nlpData.max_scale;
      }
    } catch (e) {
      console.warn('NLP API unreachable, using default scale:', e.message);
    }

    res.json({
      mass: result.total_mass,
      message: '質量を計算しました',
      text_length: text.length,
      gravity: Math.round(result.total_mass * 0.1 * (result.gravity_coefficient ?? 1.0) * 10) / 10,
      gravity_coefficient: result.gravity_coefficient,
      character_ratio: result.character_ratio,
      subject_scale: subjectScale,
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

// AI要約エンドポイント
app.post('/api/summarize-hot-discussions', async (req, res) => {
  try {
    const { discussions } = req.body;

    if (!discussions || !Array.isArray(discussions) || discussions.length === 0) {
      return res.status(400).json({ error: 'Discussions array is required and must not be empty' });
    }

    // ディスカッションのテキストを連結
    const combinedText = discussions.map(d => d.text || '').join('\n\n');

    if (combinedText.trim().length === 0) {
      return res.status(400).json({ error: 'No valid text found in discussions' });
    }

    // OpenAI APIで要約
    const summary = await summarizeWithAI(combinedText);

    // 投稿IDを抽出
    const postIds = discussions.map(d => d.id).filter(id => id != null);

    // DBに保存
    const [result] = await pool.execute(
      'INSERT INTO winds (summary, post_ids) VALUES (?, ?)',
      [summary, JSON.stringify(postIds)]
    );

    res.json({
      summary: summary,
      wind_id: result.insertId,
      original_discussions_count: discussions.length,
      total_text_length: combinedText.length
    });
  } catch (error) {
    console.error('Summarization error:', error);
    res.status(500).json({ error: 'Failed to summarize discussions' });
  }
});

// AI要約関数
async function summarizeWithAI(text) {
  try {
    if (!process.env.OPENAI_API_KEY) {
      throw new Error('OpenAI API key not configured');
    }

    const response = await openai.chat.completions.create({
      model: 'gpt-3.5-turbo',
      messages: [
        {
          role: 'system',
          content: 'あなたはディスカッションの要約を専門とするアシスタントです。与えられたテキストを簡潔に要約してください。'
        },
        {
          role: 'user',
          content: `以下のディスカッションを要約してください：\n\n${text}`
        }
      ],
      max_tokens: 300,
      temperature: 0.3,
    });

    return response.choices[0].message.content.trim();
  } catch (error) {
    console.error('OpenAI API error:', error);
    // フォールバック：テキストの最初の200文字を返す
    return text.length > 200 ? text.substring(0, 200) + '...' : text;
  }
}

// 質量計算ロジック
function calculateMass(text) {
  // 文字種の割合を計算
  const clean = text.replace(/\s/g, '');
  const totalChars = Math.max(clean.length, 1);
  const kanjiCount = (clean.match(/\p{Script=Han}/gu) || []).length;
  const hiraganaCount = (clean.match(/\p{Script=Hiragana}/gu) || []).length;
  const katakanaCount = (clean.match(/\p{Script=Katakana}/gu) || []).length;
  const alnumCount = (clean.match(/[A-Za-z0-9]/g) || []).length;

  const ratios = {
    kanji: kanjiCount / totalChars,
    hiragana: hiraganaCount / totalChars,
    katakana: katakanaCount / totalChars,
    alnum: alnumCount / totalChars,
  };

  // 重力係数: 漢字が多ければ重く、ひらがなが多ければ軽めに
  let gravityCoefficient = 1.0;
  if (ratios.kanji >= 0.4) {
    gravityCoefficient = 1.25; // 重厚な意見
  } else if (ratios.hiragana >= 0.4) {
    gravityCoefficient = 0.85; // 軽い意見
  } else if (ratios.katakana >= 0.4) {
    gravityCoefficient = 1.0; // 外来語・専門用語
  }

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
    total_mass: Math.round(totalMass * 10) / 10,
    gravity_coefficient: gravityCoefficient,
    character_ratio: {
      kanji: Math.round(ratios.kanji * 1000) / 1000,
      hiragana: Math.round(ratios.hiragana * 1000) / 1000,
      katakana: Math.round(ratios.katakana * 1000) / 1000,
      alnum: Math.round(ratios.alnum * 1000) / 1000,
    }
  };
}

// --- VRリモート（スマホ↔VR間の共有ステート） ---
let vrRemoteState = { text: '', exitRequested: false };

app.get('/api/vr-remote', (req, res) => {
  res.json(vrRemoteState);
  // exitは一度読んだらリセット
  if (vrRemoteState.exitRequested) {
    vrRemoteState.exitRequested = false;
  }
});

app.post('/api/vr-remote', (req, res) => {
  const { action, text } = req.body;
  if (action === 'setText') {
    vrRemoteState.text = text || '';
  } else if (action === 'exit') {
    vrRemoteState.exitRequested = true;
  }
  res.json({ ok: true });
});

// エラーハンドリング
app.use((err, req, res, next) => {
  console.error(err.stack);
  res.status(500).json({ error: 'Internal server error' });
});

// --- WebSocket（デバイス間リアルタイム同期） ---
const wss = new WebSocketServer({ server, path: '/ws' });

function broadcast(data, sender) {
  const msg = JSON.stringify(data);
  for (const client of wss.clients) {
    if (client !== sender && client.readyState === 1) {
      client.send(msg);
    }
  }
}

wss.on('connection', (ws) => {
  console.log(`🔌 WS connected (total: ${wss.clients.size})`);

  ws.on('message', (raw) => {
    try {
      const data = JSON.parse(raw);
      // クライアントからのイベントを他の全クライアントにブロードキャスト
      broadcast(data, ws);
    } catch (e) {
      console.error('WS parse error:', e);
    }
  });

  ws.on('close', () => {
    console.log(`🔌 WS disconnected (total: ${wss.clients.size})`);
  });
});

// サーバー起動
server.listen(PORT, '0.0.0.0', () => {
  console.log(`✨ Gravity API listening on port ${PORT}`);
  console.log(`🔌 WebSocket ready on ws://0.0.0.0:${PORT}/ws`);
  console.log(`🌊 重力計算を開始しました`);
});
