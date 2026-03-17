<template>
  <div class="container">
    <div class="header">
      <h1>🌊 いい波立ってんね～ 🌬️</h1>
      <p>議論が波紋となり、風となって拡散される</p>
    </div>

    <div class="main-content">
      <canvas
        ref="canvas"
        class="wave-canvas"
        @click="handleCanvasClick"
      ></canvas>

      <div class="sidebar">
        <div class="input-section">
          <textarea
            v-model="postText"
            placeholder="あなたの意見を投じてください..."
            rows="5"
          ></textarea>
          <button @click="submitPost" class="submit-btn">
            石を投じる
          </button>
        </div>

        <div class="info-section">
          <h3>ステータス</h3>
          <p>投稿数: {{ postCount }}</p>
          <p>API接続: {{ apiStatus }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const canvas = ref(null)
const postText = ref('')
const postCount = ref(0)
const apiStatus = ref('確認中...')

let ctx = null
let animationId = null
let time = 0

// --- 波紋管理 (#3) ---
const MAX_RIPPLES = 50
const ripples = []

function addRipple(x, y, maxRadius = 120) {
  ripples.push({ x, y, radius: 2, maxRadius, alpha: 1 })
  if (ripples.length > MAX_RIPPLES) {
    ripples.shift()
  }
}

function updateRipples() {
  for (let i = ripples.length - 1; i >= 0; i--) {
    const r = ripples[i]
    r.radius += 1.5
    r.alpha = 1 - r.radius / r.maxRadius
    if (r.radius >= r.maxRadius) {
      ripples.splice(i, 1)
    }
  }
}

function drawRipples() {
  for (const r of ripples) {
    ctx.save()
    ctx.globalAlpha = r.alpha * 0.8
    ctx.strokeStyle = '#ffffff'
    ctx.lineWidth = 2
    ctx.beginPath()
    ctx.arc(r.x, r.y, r.radius, 0, Math.PI * 2)
    ctx.stroke()
    // 内側にもう一つ薄い波紋
    if (r.radius > 10) {
      ctx.globalAlpha = r.alpha * 0.3
      ctx.beginPath()
      ctx.arc(r.x, r.y, r.radius * 0.6, 0, Math.PI * 2)
      ctx.stroke()
    }
    ctx.restore()
  }
}

// --- 背景描画 (#1) ---
function drawBackground() {
  const w = canvas.value.width
  const h = canvas.value.height

  // 水面グラデーション
  const grad = ctx.createLinearGradient(0, 0, 0, h)
  grad.addColorStop(0, '#1a2a6c')
  grad.addColorStop(0.5, '#2d4a8a')
  grad.addColorStop(1, '#1a3a5c')
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, w, h)

  // 波打つ水面の横線
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.05)'
  ctx.lineWidth = 1
  for (let y = 30; y < h; y += 40) {
    ctx.beginPath()
    for (let x = 0; x <= w; x += 5) {
      const offsetY = Math.sin((x + time * 30) * 0.02) * 4
        + Math.sin((x + time * 15) * 0.01) * 3
      if (x === 0) {
        ctx.moveTo(x, y + offsetY)
      } else {
        ctx.lineTo(x, y + offsetY)
      }
    }
    ctx.stroke()
  }
}

// --- メインループ ---
function animate() {
  if (!ctx) return
  time++
  ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
  drawBackground()
  updateRipples()
  drawRipples()
  animationId = requestAnimationFrame(animate)
}

// --- Canvas セットアップ (#1) ---
function resizeCanvas() {
  if (canvas.value) {
    canvas.value.width = canvas.value.offsetWidth
    canvas.value.height = canvas.value.offsetHeight
  }
}

onMounted(() => {
  if (canvas.value) {
    ctx = canvas.value.getContext('2d')
    resizeCanvas()
    window.addEventListener('resize', resizeCanvas)
    animate()
    checkApiStatus()
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', resizeCanvas)
  if (animationId) cancelAnimationFrame(animationId)
})

// --- イベントハンドラ (#2) ---
const handleCanvasClick = (event) => {
  const rect = canvas.value.getBoundingClientRect()
  const x = event.clientX - rect.left
  const y = event.clientY - rect.top
  addRipple(x, y)
}

// --- 投稿 (#4) ---
const submitPost = async () => {
  if (!postText.value.trim()) {
    alert('何か入力してください')
    return
  }

  try {
    const gravityResponse = await fetch('/api/gravity/calculate-mass', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: postText.value })
    }).catch(() => null)

    // 投稿時にCanvas中央付近にランダムで波紋を発生
    if (canvas.value) {
      const x = Math.random() * canvas.value.width * 0.6 + canvas.value.width * 0.2
      const y = Math.random() * canvas.value.height * 0.6 + canvas.value.height * 0.2
      addRipple(x, y, 180)
    }

    postCount.value++
    postText.value = ''
  } catch (error) {
    console.error('投稿送信エラー:', error)
  }
}

// --- API接続確認 (#12) ---
const checkApiStatus = async () => {
  try {
    await Promise.all([
      fetch('/api/gravity/health').catch(() => { throw new Error('gravity') }),
      fetch('/api/logic/health').catch(() => { throw new Error('logic') })
    ])
    apiStatus.value = '接続済み'
  } catch (error) {
    apiStatus.value = '未接続'
  }
}
</script>

<style scoped>
.container {
  width: 100%;
  height: 100vh;
  display: flex;
  flex-direction: column;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  overflow: hidden;
}

.header {
  padding: 20px;
  color: white;
  text-align: center;
  background: rgba(0, 0, 0, 0.3);
}

.header h1 {
  font-size: 2.5em;
  margin-bottom: 10px;
}

.header p {
  font-size: 1.1em;
  opacity: 0.9;
}

.main-content {
  display: flex;
  flex: 1;
  gap: 20px;
  padding: 20px;
}

.wave-canvas {
  flex: 1;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 10px;
  border: 2px solid rgba(255, 255, 255, 0.2);
  cursor: crosshair;
}

.sidebar {
  width: 300px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.input-section,
.info-section {
  background: rgba(255, 255, 255, 0.1);
  padding: 15px;
  border-radius: 10px;
  backdrop-filter: blur(10px);
  color: white;
}

textarea {
  width: 100%;
  padding: 10px;
  border: none;
  border-radius: 5px;
  font-family: inherit;
  resize: none;
  background: rgba(255, 255, 255, 0.9);
}

.submit-btn {
  width: 100%;
  padding: 10px;
  margin-top: 10px;
  border: none;
  background: #00d4ff;
  color: #333;
  font-weight: bold;
  border-radius: 5px;
  cursor: pointer;
  transition: all 0.3s;
}

.submit-btn:hover {
  background: #00f7ff;
  transform: scale(1.05);
}

.info-section h3 {
  margin-bottom: 10px;
  font-size: 1.1em;
}

.info-section p {
  margin: 5px 0;
  font-size: 0.95em;
}
</style>
