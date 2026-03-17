<template>
  <div class="container">
    <div class="header">
      <h1>いい波立ってんね～</h1>
      <p>議論が波紋となり、風となって拡散される</p>
    </div>

    <div class="main-content">
      <div ref="threeContainer" class="three-canvas" @click="handleClick"></div>

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
              {{ isSubmitting ? '投じ中...' : '一石を投じる' }}
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
import * as THREE from 'three'

const threeContainer = ref(null)
const postText = ref('')
const postCount = ref(0)
const apiStatus = ref('確認中...')
const isSubmitting = ref(false)
const lastMass = ref(null)
const lastGravity = ref(null)

// --- Three.js 変数 ---
let scene, camera, renderer, clock
let waterMesh, waterGeo
let animationId = null
const WATER_SEG = 128
const WATER_SIZE = 40

// 石（投稿）の3Dオブジェクト
const stoneMeshes = []
// 波紋データ
const ripples = []
// スプラッシュパーティクル
let splashParticles = null
let splashData = []
// 飛行中の石
let flyingStone = null

// --- 投稿データ管理 ---
const posts = ref([])

function loadMockPosts() {
  posts.value = [
    { id: 1, text: 'SNSの即時性は本当に必要なのか？', x: -0.4, z: -0.3, mass: 65, heat: 40, weathered: 0.0 },
    { id: 2, text: 'もっとゆっくり議論したい', x: 0.2, z: 0.2, mass: 30, heat: 10, weathered: 0.2 },
    { id: 3, text: '炎上は現代の焚き火である！！', x: 0.0, z: -0.1, mass: 85, heat: 70, weathered: 0.0 },
    { id: 4, text: 'エコーチェンバーを壊すには', x: 0.35, z: 0.3, mass: 45, heat: 25, weathered: 0.4 },
  ]
}

// --- 質量→色 ---
function massColor(mass) {
  if (mass > 80) return new THREE.Color(1.0, 0.3, 0.3)
  if (mass > 50) return new THREE.Color(1.0, 0.75, 0.3)
  if (mass > 25) return new THREE.Color(0.3, 0.85, 1.0)
  return new THREE.Color(0.9, 0.9, 1.0)
}

// --- Three.js 初期化 ---
function initThree() {
  const container = threeContainer.value
  const w = container.clientWidth
  const h = container.clientHeight

  // シーン
  scene = new THREE.Scene()
  scene.fog = new THREE.FogExp2(0x0a1628, 0.015)

  // カメラ（斜め上から水面を見下ろす）
  camera = new THREE.PerspectiveCamera(55, w / h, 0.1, 200)
  camera.position.set(0, 18, 22)
  camera.lookAt(0, 0, 0)

  // レンダラー
  renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true })
  renderer.setSize(w, h)
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
  renderer.toneMapping = THREE.ACESFilmicToneMapping
  renderer.toneMappingExposure = 1.2
  container.appendChild(renderer.domElement)

  clock = new THREE.Clock()

  // ライティング
  const ambientLight = new THREE.AmbientLight(0x334466, 0.8)
  scene.add(ambientLight)

  const moonLight = new THREE.DirectionalLight(0xaaccff, 1.5)
  moonLight.position.set(-10, 20, 10)
  scene.add(moonLight)

  const warmLight = new THREE.PointLight(0xff8844, 0.6, 50)
  warmLight.position.set(5, 8, -5)
  scene.add(warmLight)

  // 水面メッシュ
  waterGeo = new THREE.PlaneGeometry(WATER_SIZE, WATER_SIZE, WATER_SEG, WATER_SEG)
  const waterMat = new THREE.MeshPhongMaterial({
    color: 0x1a4a8a,
    emissive: 0x0a1a3a,
    specular: 0x88bbff,
    shininess: 80,
    transparent: true,
    opacity: 0.85,
    side: THREE.DoubleSide,
    flatShading: false,
  })
  waterMesh = new THREE.Mesh(waterGeo, waterMat)
  waterMesh.rotation.x = -Math.PI / 2
  scene.add(waterMesh)

  // 水底のグリッド
  const gridHelper = new THREE.GridHelper(WATER_SIZE, 30, 0x112244, 0x112244)
  gridHelper.position.y = -2
  gridHelper.material.opacity = 0.15
  gridHelper.material.transparent = true
  scene.add(gridHelper)

  // 背景の空（暗い夜空）
  scene.background = new THREE.Color(0x0a1628)

  // スプラッシュ用パーティクルシステム
  const splashGeo = new THREE.BufferGeometry()
  const maxSplash = 300
  const splashPositions = new Float32Array(maxSplash * 3)
  const splashAlphas = new Float32Array(maxSplash)
  splashGeo.setAttribute('position', new THREE.BufferAttribute(splashPositions, 3))
  splashGeo.setAttribute('alpha', new THREE.BufferAttribute(splashAlphas, 1))
  const splashMat = new THREE.PointsMaterial({
    color: 0xaaddff,
    size: 0.15,
    transparent: true,
    opacity: 0.8,
    depthWrite: false,
  })
  splashParticles = new THREE.Points(splashGeo, splashMat)
  scene.add(splashParticles)

  // 初期の石を配置
  createStoneMeshes()
}

// --- 石の3Dメッシュ生成 ---
function createStoneMeshes() {
  // 既存の石を削除
  for (const s of stoneMeshes) {
    scene.remove(s.group)
  }
  stoneMeshes.length = 0

  for (const p of posts.value) {
    addStoneMesh(p)
  }
}

function addStoneMesh(p) {
  const group = new THREE.Group()

  const size = 0.3 + p.mass * 0.005
  const color = massColor(p.mass)

  // 石本体（変形した球体 → 石っぽく）
  const stoneGeo = new THREE.DodecahedronGeometry(size, 1)
  const stoneMat = new THREE.MeshPhongMaterial({
    color: color,
    emissive: color.clone().multiplyScalar(p.heat > 30 ? 0.3 : 0.05),
    specular: 0x666666,
    shininess: 40,
    transparent: true,
    opacity: Math.max(0.4, 1 - p.weathered),
  })
  const stoneMesh = new THREE.Mesh(stoneGeo, stoneMat)
  group.add(stoneMesh)

  // 熱量が高い石にはグローリング
  if (p.heat > 20) {
    const glowGeo = new THREE.RingGeometry(size + 0.2, size + 0.5, 32)
    const glowMat = new THREE.MeshBasicMaterial({
      color: color,
      transparent: true,
      opacity: 0.3,
      side: THREE.DoubleSide,
    })
    const glowMesh = new THREE.Mesh(glowGeo, glowMat)
    glowMesh.rotation.x = -Math.PI / 2
    glowMesh.position.y = 0.05
    glowMesh.userData.isGlow = true
    group.add(glowMesh)
  }

  // テキストラベル（Sprite）
  const label = p.text.length > 16 ? p.text.slice(0, 16) + '…' : p.text
  const sprite = createTextSprite(label, color)
  sprite.position.y = size + 0.6
  group.add(sprite)

  // 配置
  const wx = p.x * (WATER_SIZE / 2) * 0.8
  const wz = p.z * (WATER_SIZE / 2) * 0.8
  group.position.set(wx, 0.2, wz)

  scene.add(group)
  stoneMeshes.push({ group, post: p, baseY: 0.2 })
}

function createTextSprite(text, color) {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  canvas.width = 512
  canvas.height = 64

  // 背景
  ctx.fillStyle = 'rgba(0, 0, 0, 0.6)'
  ctx.roundRect(0, 0, canvas.width, canvas.height, 8)
  ctx.fill()

  // テキスト
  ctx.fillStyle = '#ffffff'
  ctx.font = 'bold 28px sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(text, canvas.width / 2, canvas.height / 2)

  const texture = new THREE.CanvasTexture(canvas)
  const mat = new THREE.SpriteMaterial({
    map: texture,
    transparent: true,
    depthWrite: false,
  })
  const sprite = new THREE.Sprite(mat)
  sprite.scale.set(4, 0.5, 1)
  return sprite
}

// --- 水面の波アニメーション ---
// PlaneGeometryはXY平面で生成され、rotation.x=-PI/2でXZ平面に回転
// ジオメトリのローカル座標: X→ワールドX, Y→ワールド-Z（回転により反転）
function updateWater(elapsed) {
  const positions = waterGeo.attributes.position
  const count = positions.count

  for (let i = 0; i < count; i++) {
    const localX = positions.getX(i)  // ワールドX
    const localY = positions.getY(i)  // ワールド-Z（回転でZが反転）

    // 基本の波
    let h = Math.sin(localX * 0.5 + elapsed * 0.8) * 0.15
      + Math.sin(localY * 0.3 + elapsed * 0.6) * 0.1
      + Math.sin((localX + localY) * 0.4 + elapsed * 1.2) * 0.08

    // 波紋による変位
    // ワールド座標の波紋(r.x, r.z)をローカル座標に変換: localX=r.x, localY=-r.z
    for (const r of ripples) {
      const dx = localX - r.x
      const dy = localY - (-r.z)  // ワールドZ → ローカルY = -Z
      const dist = Math.sqrt(dx * dx + dy * dy)
      if (dist < r.radius + 2 && dist > r.radius - 2) {
        const wave = Math.sin((dist - r.radius) * 3) * r.amplitude
        h += wave
      }
    }

    positions.setZ(i, h)
  }

  positions.needsUpdate = true
  waterGeo.computeVertexNormals()
}

// --- 波紋 ---
function addRipple3D(worldX, worldZ, mass) {
  const amplitude = 0.1 + mass * 0.005
  const maxRadius = 3 + mass * 0.08
  ripples.push({
    x: worldX,
    z: worldZ,
    radius: 0.1,
    maxRadius,
    amplitude,
    speed: Math.max(1.5, 4 - mass * 0.02),
  })
}

function updateRipples(dt) {
  for (let i = ripples.length - 1; i >= 0; i--) {
    const r = ripples[i]
    r.radius += r.speed * dt
    r.amplitude *= 0.995
    if (r.radius > r.maxRadius || r.amplitude < 0.001) {
      ripples.splice(i, 1)
    }
  }
}

// --- スプラッシュ ---
function addSplash3D(worldX, worldZ, mass) {
  const count = Math.min(8 + Math.floor(mass * 0.15), 30)
  for (let i = 0; i < count; i++) {
    const angle = (Math.PI * 2 * i) / count + (Math.random() - 0.5) * 0.5
    const speed = 1 + Math.random() * 3
    splashData.push({
      x: worldX,
      y: 0.2,
      z: worldZ,
      vx: Math.cos(angle) * speed * 0.3,
      vy: 2 + Math.random() * 4,
      vz: Math.sin(angle) * speed * 0.3,
      life: 1,
    })
  }
}

function updateSplashes(dt) {
  for (let i = splashData.length - 1; i >= 0; i--) {
    const s = splashData[i]
    s.x += s.vx * dt
    s.y += s.vy * dt
    s.z += s.vz * dt
    s.vy -= 9.8 * dt
    s.life -= dt * 1.5
    if (s.life <= 0 || s.y < -1) {
      splashData.splice(i, 1)
    }
  }

  // パーティクルバッファ更新
  const positions = splashParticles.geometry.attributes.position
  for (let i = 0; i < 300; i++) {
    if (i < splashData.length) {
      positions.setXYZ(i, splashData[i].x, splashData[i].y, splashData[i].z)
    } else {
      positions.setXYZ(i, 0, -100, 0)
    }
  }
  positions.needsUpdate = true
}

// --- 石の投げアニメーション ---
function throwStone(targetX, targetZ, mass, text) {
  const startX = -WATER_SIZE / 2 + 2
  const startY = 8
  const startZ = WATER_SIZE / 2 - 2

  const size = 0.3 + mass * 0.005
  const color = massColor(mass)
  const geo = new THREE.DodecahedronGeometry(size, 1)
  const mat = new THREE.MeshPhongMaterial({
    color: color,
    emissive: color.clone().multiplyScalar(0.2),
    specular: 0x666666,
    shininess: 40,
  })
  const mesh = new THREE.Mesh(geo, mat)
  mesh.position.set(startX, startY, startZ)
  scene.add(mesh)

  // 残像用（トレイル）
  const trailGeo = new THREE.BufferGeometry()
  const trailPositions = new Float32Array(60 * 3)
  trailGeo.setAttribute('position', new THREE.BufferAttribute(trailPositions, 3))
  const trailMat = new THREE.LineBasicMaterial({
    color: color,
    transparent: true,
    opacity: 0.4,
  })
  const trailLine = new THREE.Line(trailGeo, trailMat)
  scene.add(trailLine)

  flyingStone = {
    mesh,
    trailLine,
    trailPositions: [],
    startX, startY, startZ,
    targetX, targetZ,
    mass, text,
    progress: 0,
    peakY: startY + 3,
  }
}

function updateFlyingStone(dt) {
  if (!flyingStone) return
  const f = flyingStone
  f.progress += dt * 0.8

  if (f.progress >= 1) {
    // 着水
    scene.remove(f.mesh)
    scene.remove(f.trailLine)

    addSplash3D(f.targetX, f.targetZ, f.mass)
    addRipple3D(f.targetX, f.targetZ, f.mass)

    // 石を配置
    const newPost = {
      id: Date.now(),
      text: f.text,
      x: f.targetX / (WATER_SIZE / 2) / 0.8,
      z: f.targetZ / (WATER_SIZE / 2) / 0.8,
      mass: f.mass,
      heat: 0,
      weathered: 0,
    }
    posts.value.push(newPost)
    addStoneMesh(newPost)

    flyingStone = null
    return
  }

  // ベジェ補間
  const t = f.progress
  const u = 1 - t
  const midX = (f.startX + f.targetX) / 2
  const midZ = (f.startZ + f.targetZ) / 2

  f.mesh.position.x = u * u * f.startX + 2 * u * t * midX + t * t * f.targetX
  f.mesh.position.z = u * u * f.startZ + 2 * u * t * midZ + t * t * f.targetZ
  f.mesh.position.y = u * u * f.startY + 2 * u * t * f.peakY + t * t * 0.3
  f.mesh.rotation.x += dt * 5
  f.mesh.rotation.z += dt * 3

  // トレイル更新
  f.trailPositions.push(f.mesh.position.x, f.mesh.position.y, f.mesh.position.z)
  if (f.trailPositions.length > 60 * 3) {
    f.trailPositions.splice(0, 3)
  }
  const positions = f.trailLine.geometry.attributes.position
  for (let i = 0; i < 60; i++) {
    const idx = i * 3
    if (idx < f.trailPositions.length) {
      positions.setXYZ(i, f.trailPositions[idx], f.trailPositions[idx + 1], f.trailPositions[idx + 2])
    }
  }
  positions.needsUpdate = true
  f.trailLine.geometry.setDrawRange(0, Math.floor(f.trailPositions.length / 3))
}

// --- 石のボブ（浮遊）アニメーション ---
function updateStones(elapsed) {
  for (const s of stoneMeshes) {
    // 水面に浮くようにゆっくり上下
    s.group.position.y = s.baseY + Math.sin(elapsed * 1.5 + s.post.id * 0.7) * 0.1

    // 熱量が高い石のグロー脈動
    if (s.post.heat > 20) {
      for (const child of s.group.children) {
        if (child.userData.isGlow) {
          child.material.opacity = 0.15 + Math.sin(elapsed * 3 + s.post.id) * 0.15
          child.scale.setScalar(1 + Math.sin(elapsed * 2 + s.post.id) * 0.1)
        }
      }
    }
  }
}

// --- クリック → 波紋 ---
function handleClick(event) {
  const container = threeContainer.value
  const rect = container.getBoundingClientRect()
  const mouse = new THREE.Vector2(
    ((event.clientX - rect.left) / rect.width) * 2 - 1,
    -((event.clientY - rect.top) / rect.height) * 2 + 1,
  )

  const raycaster = new THREE.Raycaster()
  raycaster.setFromCamera(mouse, camera)
  const intersects = raycaster.intersectObject(waterMesh)
  if (intersects.length > 0) {
    const point = intersects[0].point
    addRipple3D(point.x, point.z, 15)
    addSplash3D(point.x, point.z, 10)
  }
}

// --- メインループ ---
function animate() {
  animationId = requestAnimationFrame(animate)
  const dt = Math.min(clock.getDelta(), 0.05)
  const elapsed = clock.getElapsedTime()

  updateWater(elapsed)
  updateRipples(dt)
  updateSplashes(dt)
  updateStones(elapsed)
  updateFlyingStone(dt)

  renderer.render(scene, camera)
}

// --- リサイズ ---
function onResize() {
  if (!threeContainer.value) return
  const w = threeContainer.value.clientWidth
  const h = threeContainer.value.clientHeight
  camera.aspect = w / h
  camera.updateProjectionMatrix()
  renderer.setSize(w, h)
}

// --- ライフサイクル ---
onMounted(() => {
  loadMockPosts()
  initThree()
  animate()
  checkApiStatus()
  window.addEventListener('resize', onResize)
})

onUnmounted(() => {
  window.removeEventListener('resize', onResize)
  if (animationId) cancelAnimationFrame(animationId)
  if (renderer) renderer.dispose()
})

// --- 投稿 ---
const submitPost = async () => {
  if (!postText.value.trim() || postText.value.length > 500) return
  isSubmitting.value = true

  try {
    let mass = postText.value.length * 0.1
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

    // ランダムな着水位置（水面中央付近）
    const targetX = (Math.random() - 0.5) * WATER_SIZE * 0.6
    const targetZ = (Math.random() - 0.5) * WATER_SIZE * 0.6
    throwStone(targetX, targetZ, mass, postText.value)

    postCount.value++
    postText.value = ''
  } catch (error) {
    console.error('投稿送信エラー:', error)
  } finally {
    isSubmitting.value = false
  }
}

// --- API接続確認 ---
const checkApiStatus = async () => {
  try {
    await Promise.all([
      fetch('/api/gravity/health').catch(() => { throw new Error('gravity') }),
      fetch('/api/logic/health').catch(() => { throw new Error('logic') })
    ])
    apiStatus.value = '接続済み'
  } catch {
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
  background: #0a1628;
  overflow: hidden;
}

.header {
  padding: 15px 20px;
  color: white;
  text-align: center;
  background: rgba(10, 22, 40, 0.9);
  border-bottom: 1px solid rgba(100, 180, 255, 0.15);
}

.header h1 {
  font-size: 2em;
  margin-bottom: 4px;
  background: linear-gradient(90deg, #4af, #a8f, #4af);
  background-size: 200% auto;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: shimmer 3s ease-in-out infinite;
}

@keyframes shimmer {
  0%, 100% { background-position: 0% center; }
  50% { background-position: 200% center; }
}

.header p {
  font-size: 0.95em;
  opacity: 0.6;
}

.main-content {
  display: flex;
  flex: 1;
  gap: 0;
  overflow: hidden;
  position: relative;
}

.three-canvas {
  flex: 1;
  min-height: 200px;
  cursor: crosshair;
}

.sidebar {
  width: 300px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 15px;
  padding: 15px;
  background: rgba(10, 22, 40, 0.85);
  border-left: 1px solid rgba(100, 180, 255, 0.1);
}

@media (max-width: 768px) {
  .main-content {
    flex-direction: column;
  }
  .three-canvas {
    flex: 1;
  }
  .sidebar {
    width: 100%;
    border-left: none;
    border-top: 1px solid rgba(100, 180, 255, 0.1);
  }
  .header h1 {
    font-size: 1.4em;
  }
  .header {
    padding: 10px;
  }
}

.input-section,
.info-section {
  background: rgba(100, 180, 255, 0.06);
  padding: 15px;
  border-radius: 10px;
  border: 1px solid rgba(100, 180, 255, 0.1);
  color: white;
}

textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid rgba(100, 180, 255, 0.2);
  border-radius: 6px;
  font-family: inherit;
  resize: none;
  background: rgba(10, 22, 40, 0.8);
  color: white;
  outline: none;
  transition: border-color 0.3s;
}

textarea:focus {
  border-color: rgba(100, 180, 255, 0.5);
}

textarea::placeholder {
  color: rgba(255, 255, 255, 0.3);
}

.input-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
}

.char-count {
  font-size: 0.8em;
  opacity: 0.5;
  white-space: nowrap;
}

.char-count.warn {
  color: #ff6b6b;
  opacity: 1;
}

.submit-btn {
  flex: 1;
  padding: 10px;
  border: 1px solid rgba(100, 180, 255, 0.3);
  background: rgba(100, 180, 255, 0.15);
  color: #aaddff;
  font-weight: bold;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 0.95em;
}

.submit-btn:hover:not(:disabled) {
  background: rgba(100, 180, 255, 0.3);
  border-color: rgba(100, 180, 255, 0.6);
  transform: scale(1.02);
}

.submit-btn:disabled {
  background: rgba(50, 50, 50, 0.5);
  color: rgba(255, 255, 255, 0.2);
  border-color: rgba(50, 50, 50, 0.3);
  cursor: not-allowed;
  transform: none;
}

.mass-result {
  margin-top: 8px;
  font-size: 0.85em;
  opacity: 0.5;
  text-align: center;
}

.info-section h3 {
  margin-bottom: 10px;
  font-size: 1em;
  opacity: 0.8;
}

.info-section p {
  margin: 4px 0;
  font-size: 0.9em;
  opacity: 0.6;
}
</style>
