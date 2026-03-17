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
            :disabled="isSubmitting"
            @keydown.ctrl.enter="submitPost"
            @keydown.meta.enter="submitPost"
          ></textarea>
          <div class="input-footer">
            <span class="char-count" :class="{ warn: postText.length > 500 }">
              {{ postText.length }} / 500
            </span>
            <button
              @click="submitPost"
              class="submit-btn"
              :disabled="isSubmitting || !postText.trim() || postText.length > 500"
            >
              {{ isSubmitting ? '投じ中...' : '石を投じる' }}
            </button>
          </div>
          <p v-if="lastMass !== null" class="mass-result">
            質量: {{ lastMass }} / 重力: {{ lastGravity }}
          </p>
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
const isSubmitting = ref(false)
const lastMass = ref(null)
const lastGravity = ref(null)

let ctx = null
let animationId = null
let time = 0

// --- 腕 + 石の投げアニメーション ---
let throwAnim = null

function startThrow(targetX, targetY, mass) {
  const h = canvas.value.height
  throwAnim = {
    phase: 'swing',   // swing → fly → done
    progress: 0,
    targetX,
    targetY,
    mass,
    stoneSize: Math.min(5 + mass * 0.08, 12),
    // 腕の付け根
    baseX: 30,
    baseY: h - 30,
  }
}

// 腕の手の位置を計算（swingの進行度に応じた角度）
function getHandPos(anim) {
  const angle = -Math.PI * 0.65 + anim.progress * Math.PI * 0.85
  const upperLen = 55
  const foreLen = 55
  const elbowX = anim.baseX + Math.cos(angle - 0.3) * upperLen
  const elbowY = anim.baseY + Math.sin(angle - 0.3) * upperLen
  const handX = elbowX + Math.cos(angle + 0.2) * foreLen
  const handY = elbowY + Math.sin(angle + 0.2) * foreLen
  return { elbowX, elbowY, handX, handY }
}

function updateThrowAnim() {
  if (!throwAnim) return
  const a = throwAnim

  if (a.phase === 'swing') {
    a.progress += 0.05
    if (a.progress >= 1) {
      // 石を放つ → fly フェーズへ
      const { handX, handY } = getHandPos(a)
      a.phase = 'fly'
      a.progress = 0
      a.stoneStartX = handX
      a.stoneStartY = handY
      // 放物線の頂点（手と着水点の中間の上方）
      a.stonePeakX = (handX + a.targetX) / 2
      a.stonePeakY = Math.min(handY, a.targetY) - 120 - a.mass * 0.3
      a.armFade = 1 // 腕のフェードアウト用
    }
  } else if (a.phase === 'fly') {
    a.progress += 0.03
    a.armFade = Math.max(0, a.armFade - 0.06)
    if (a.progress >= 1) {
      // 着水
      addSplash(a.targetX, a.targetY, a.mass)
      const maxRadius = Math.min(80 + a.mass * 1.5, 250)
      addRipple(a.targetX, a.targetY, maxRadius, a.mass)
      throwAnim = null
    }
  }
}

// 二次ベジェ曲線上の点
function bezier(t, p0, p1, p2) {
  const u = 1 - t
  return u * u * p0 + 2 * u * t * p1 + t * t * p2
}

function drawThrowAnim() {
  if (!throwAnim) return
  const a = throwAnim

  ctx.save()

  // --- 腕の描画 ---
  let armAlpha = 1
  let armProgress = a.phase === 'swing' ? a.progress : 1
  if (a.phase === 'fly') armAlpha = a.armFade

  if (armAlpha > 0) {
    ctx.globalAlpha = armAlpha
    const swingAngle = -Math.PI * 0.65 + armProgress * Math.PI * 0.85
    const upperLen = 55
    const foreLen = 55
    const elbowX = a.baseX + Math.cos(swingAngle - 0.3) * upperLen
    const elbowY = a.baseY + Math.sin(swingAngle - 0.3) * upperLen
    const handX = elbowX + Math.cos(swingAngle + 0.2) * foreLen
    const handY = elbowY + Math.sin(swingAngle + 0.2) * foreLen

    // 上腕
    ctx.strokeStyle = '#f0c8a0'
    ctx.lineWidth = 14
    ctx.lineCap = 'round'
    ctx.beginPath()
    ctx.moveTo(a.baseX, a.baseY)
    ctx.lineTo(elbowX, elbowY)
    ctx.stroke()

    // 前腕
    ctx.beginPath()
    ctx.moveTo(elbowX, elbowY)
    ctx.lineTo(handX, handY)
    ctx.stroke()

    // 手
    ctx.fillStyle = '#f0c8a0'
    ctx.beginPath()
    ctx.arc(handX, handY, 10, 0, Math.PI * 2)
    ctx.fill()

    ctx.globalAlpha = 1
  }

  // --- 石の描画 ---
  let stoneX, stoneY
  if (a.phase === 'swing') {
    // 手に持っている
    const { handX, handY } = getHandPos(a)
    stoneX = handX
    stoneY = handY - 14
  } else if (a.phase === 'fly') {
    // ベジェ曲線で飛行
    stoneX = bezier(a.progress, a.stoneStartX, a.stonePeakX, a.targetX)
    stoneY = bezier(a.progress, a.stoneStartY, a.stonePeakY, a.targetY)
  }

  if (stoneX !== undefined) {
    ctx.fillStyle = '#c8d6e5'
    ctx.beginPath()
    ctx.arc(stoneX, stoneY, a.stoneSize, 0, Math.PI * 2)
    ctx.fill()

    // 飛行中の残像
    if (a.phase === 'fly' && a.progress > 0.05) {
      const prevT = a.progress - 0.05
      const prevX = bezier(prevT, a.stoneStartX, a.stonePeakX, a.targetX)
      const prevY = bezier(prevT, a.stoneStartY, a.stonePeakY, a.targetY)
      ctx.globalAlpha = 0.3
      ctx.beginPath()
      ctx.arc(prevX, prevY, a.stoneSize * 0.6, 0, Math.PI * 2)
      ctx.fill()
    }
  }

  ctx.restore()
}

// --- スプラッシュ (#5) ---
const splashes = []

function addSplash(x, y, mass) {
  const count = Math.min(5 + Math.floor(mass * 0.1), 15)
  for (let i = 0; i < count; i++) {
    const angle = (Math.PI * 2 * i) / count + (Math.random() - 0.5) * 0.5
    const speed = 2 + Math.random() * 3
    splashes.push({
      x, y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed - 3 - Math.random() * 2,
      alpha: 1,
      size: 1.5 + Math.random() * 2
    })
  }
}

function updateSplashes() {
  for (let i = splashes.length - 1; i >= 0; i--) {
    const p = splashes[i]
    p.x += p.vx
    p.y += p.vy
    p.vy += 0.15 // 重力
    p.alpha -= 0.025
    if (p.alpha <= 0) {
      splashes.splice(i, 1)
    }
  }
}

function drawSplashes() {
  for (const p of splashes) {
    ctx.save()
    ctx.globalAlpha = p.alpha
    ctx.fillStyle = '#a0d2ff'
    ctx.beginPath()
    ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2)
    ctx.fill()
    ctx.restore()
  }
}

// --- 波紋管理 (#3 + #6) ---
const MAX_RIPPLES = 50
const ripples = []

// 質量に応じた波紋の色を返す (#6)
function rippleColor(mass) {
  if (mass > 80) return { r: 255, g: 100, b: 100 } // 熱い赤
  if (mass > 50) return { r: 255, g: 200, b: 100 } // 暖かいオレンジ
  if (mass > 25) return { r: 100, g: 220, b: 255 } // 水色
  return { r: 255, g: 255, b: 255 }                 // 白（軽い）
}

function addRipple(x, y, maxRadius = 120, mass = 10) {
  const color = rippleColor(mass)
  // 質量が大きい → 広がる速度が遅い (#6)
  const speed = Math.max(0.8, 2.0 - mass * 0.01)
  ripples.push({ x, y, radius: 2, maxRadius, alpha: 1, color, speed })
  if (ripples.length > MAX_RIPPLES) {
    ripples.shift()
  }
}

function updateRipples() {
  for (let i = ripples.length - 1; i >= 0; i--) {
    const r = ripples[i]
    r.radius += r.speed
    r.alpha = 1 - r.radius / r.maxRadius
    if (r.radius >= r.maxRadius) {
      ripples.splice(i, 1)
    }
  }
}

function drawRipples() {
  for (const r of ripples) {
    const { r: cr, g: cg, b: cb } = r.color
    ctx.save()
    ctx.globalAlpha = r.alpha * 0.8
    ctx.strokeStyle = `rgb(${cr}, ${cg}, ${cb})`
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
  updateThrowAnim()
  updateSplashes()
  updateRipples()
  drawRipples()
  drawSplashes()
  drawThrowAnim()
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
  addRipple(x, y, 120, 10)
}

// --- 投稿 (#4) + Gravity API通信 (#10) ---
const submitPost = async () => {
  if (!postText.value.trim() || postText.value.length > 500) return

  isSubmitting.value = true

  try {
    // Gravity APIで質量を計算
    let mass = postText.value.length * 0.1 // フォールバック値
    let gravity = mass * 0.1

    try {
      const res = await fetch('/api/gravity/calculate-mass', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: postText.value })
      })
      if (res.ok) {
        const data = await res.json()
        mass = data.mass
        gravity = data.gravity
      }
    } catch {
      console.warn('Gravity API未接続。フォールバック値を使用')
    }

    lastMass.value = mass
    lastGravity.value = gravity

    // 腕を振って石を投げる（着水後に波紋が自動発生）
    if (canvas.value) {
      const x = Math.random() * canvas.value.width * 0.5 + canvas.value.width * 0.3
      const y = Math.random() * canvas.value.height * 0.4 + canvas.value.height * 0.2
      startThrow(x, y, mass)
    }

    postCount.value++
    postText.value = ''
  } catch (error) {
    console.error('投稿送信エラー:', error)
  } finally {
    isSubmitting.value = false
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
  overflow: hidden;
}

.wave-canvas {
  flex: 1;
  min-height: 200px;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 10px;
  border: 2px solid rgba(255, 255, 255, 0.2);
  cursor: crosshair;
}

.sidebar {
  width: 300px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

@media (max-width: 768px) {
  .main-content {
    flex-direction: column;
    padding: 10px;
    gap: 10px;
  }
  .wave-canvas {
    flex: 1;
  }
  .sidebar {
    width: 100%;
    flex-shrink: 1;
  }
  .header h1 {
    font-size: 1.5em;
  }
  .header {
    padding: 10px;
  }
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

.input-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
}

.char-count {
  font-size: 0.8em;
  opacity: 0.7;
  white-space: nowrap;
}

.char-count.warn {
  color: #ff6b6b;
  opacity: 1;
}

.submit-btn {
  flex: 1;
  padding: 10px;
  border: none;
  background: #00d4ff;
  color: #333;
  font-weight: bold;
  border-radius: 5px;
  cursor: pointer;
  transition: all 0.3s;
}

.submit-btn:hover:not(:disabled) {
  background: #00f7ff;
  transform: scale(1.05);
}

.submit-btn:disabled {
  background: #666;
  color: #999;
  cursor: not-allowed;
  transform: none;
}

.mass-result {
  margin-top: 8px;
  font-size: 0.85em;
  opacity: 0.8;
  text-align: center;
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
