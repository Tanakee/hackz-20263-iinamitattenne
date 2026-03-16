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
import { ref, onMounted } from 'vue'

const canvas = ref(null)
const postText = ref('')
const postCount = ref(0)
const apiStatus = ref('確認中...')

let ctx = null

onMounted(() => {
  if (canvas.value) {
    ctx = canvas.value.getContext('2d')
    resizeCanvas()
    window.addEventListener('resize', resizeCanvas)
    animate()
    checkApiStatus()
  }
})

const resizeCanvas = () => {
  if (canvas.value) {
    canvas.value.width = canvas.value.offsetWidth
    canvas.value.height = canvas.value.offsetHeight
  }
}

const animate = () => {
  if (ctx) {
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
    // 背景
    ctx.fillStyle = 'rgba(102, 126, 234, 0.1)'
    ctx.fillRect(0, 0, canvas.value.width, canvas.value.height)

    // グリッド線（海の表現）
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.1)'
    ctx.lineWidth = 1
    for (let i = 0; i < canvas.value.width; i += 50) {
      ctx.beginPath()
      ctx.moveTo(i, 0)
      ctx.lineTo(i, canvas.value.height)
      ctx.stroke()
    }
    for (let i = 0; i < canvas.value.height; i += 50) {
      ctx.beginPath()
      ctx.moveTo(0, i)
      ctx.lineTo(canvas.value.width, i)
      ctx.stroke()
    }
  }
  requestAnimationFrame(animate)
}

const handleCanvasClick = (event) => {
  const rect = canvas.value.getBoundingClientRect()
  const x = event.clientX - rect.left
  const y = event.clientY - rect.top
  drawRipple(x, y)
}

const drawRipple = (x, y) => {
  // 波紋アニメーション（仮実装）
  let radius = 5
  const maxRadius = 100

  const drawRippleFrame = () => {
    ctx.strokeStyle = `rgba(255, 255, 255, ${1 - radius / maxRadius})`
    ctx.lineWidth = 2
    ctx.beginPath()
    ctx.arc(x, y, radius, 0, Math.PI * 2)
    ctx.stroke()

    radius += 3
    if (radius < maxRadius) {
      requestAnimationFrame(drawRippleFrame)
    }
  }

  drawRippleFrame()
}

const submitPost = async () => {
  if (!postText.value.trim()) {
    alert('何か入力してください')
    return
  }

  try {
    // 重力API呼び出し（テスト用）
    const gravityResponse = await fetch('/api/gravity/calculate-mass', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: postText.value })
    }).catch(() => null)

    postCount.value++
    postText.value = ''
    console.log('投稿が送信されました')
  } catch (error) {
    console.error('投稿送信エラー:', error)
  }
}

const checkApiStatus = async () => {
  try {
    await Promise.all([
      fetch('/api/gravity/health').catch(() => { throw new Error('gravity') }),
      fetch('/api/logic/health').catch(() => { throw new Error('logic') })
    ])
    apiStatus.value = '✅ 接続済み'
  } catch (error) {
    apiStatus.value = '❌ 未接続'
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
