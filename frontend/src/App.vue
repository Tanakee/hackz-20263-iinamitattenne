<template>
  <div class="container">
    <div class="main-content">
      <div ref="threeContainer" class="three-canvas" @click="handleClick"></div>

      <!-- ヘッダー（Canvas上にオーバーレイ） -->
      <div class="header-overlay">
        <h1>いい波立ってんね～</h1>
        <p>議論が波紋となり、風となって拡散される</p>
      </div>

      <!-- 石詳細ポップアップ -->
      <Transition name="popup">
        <div v-if="selectedPost" class="stone-popup" :style="popupStyle" @click.stop>
          <button class="popup-close" @click="selectedPost = null">x</button>
          <p class="popup-text">{{ selectedPost.text }}</p>
          <div class="popup-stats">
            <span>質量 {{ selectedPost.mass }}</span>
            <span>主語 {{ selectedPost.scale ?? '—' }}</span>
            <span>熱量 {{ selectedPost.heat }}</span>
            <span>いいね {{ selectedPost.likes || 0 }}</span>
          </div>
          <button class="like-btn" @click="likePost(selectedPost)">
            いいね +1
          </button>
        </div>
      </Transition>

      <!-- 石一覧トグルボタン -->
      <button class="list-toggle" @click="showList = !showList">
        {{ showList ? '閉じる' : '一覧' }}
      </button>

      <!-- 石一覧パネル -->
      <Transition name="slide">
        <div v-if="showList" class="stone-list">
          <h3>投じられた石</h3>
          <div v-if="posts.length === 0" class="list-empty">まだ石がありません</div>
          <div
            v-for="p in sortedPosts"
            :key="p.id"
            class="list-item"
            @click="focusStone(p)"
          >
            <div class="list-item-header">
              <span class="list-heat-bar">
                <span class="list-heat-fill" :style="{ width: Math.min(p.heat, 100) + '%' }"></span>
              </span>
              <span class="list-likes">{{ p.likes || 0 }}</span>
            </div>
            <p class="list-item-text">{{ p.text }}</p>
            <div class="list-item-meta">
              <span>質量 {{ p.mass }}</span>
              <span>主語 {{ p.scale ?? '—' }}</span>
              <span>熱量 {{ p.heat }}</span>
            </div>
          </div>
        </div>
      </Transition>

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
            質量: {{ lastMass }} / 重力: {{ lastGravity }} / 主語: {{ lastScale }}
          </p>
        </div>

        <div class="info-section">
          <h3>ステータス</h3>
          <p>投稿数: {{ posts.length }}</p>
          <p>API接続: {{ apiStatus }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import * as THREE from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'

const threeContainer = ref(null)
const postText = ref('')
const apiStatus = ref('確認中...')
const isSubmitting = ref(false)
const lastMass = ref(null)
const lastGravity = ref(null)
const lastScale = ref(null)

// --- UI状態 ---
const selectedPost = ref(null)
const popupStyle = ref({})
const showList = ref(false)

// --- Three.js 変数 ---
let scene, camera, renderer, clock, controls
let waterMesh, waterGeo
let animationId = null

// --- 効果音 (Web Audio API) ---
let audioCtx = null
function initAudio() {
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
}

function playSplashSound(mass, scale = 30) {
  if (!audioCtx) return
  const now = audioCtx.currentTime
  const s = scale / 100 // 0〜1に正規化

  // ノイズバースト（水しぶき）— スケール大 → 長い
  const noiseDuration = 0.08 + s * 0.2
  const bufferSize = Math.floor(audioCtx.sampleRate * noiseDuration)
  const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate)
  const data = buffer.getChannelData(0)
  for (let i = 0; i < bufferSize; i++) {
    data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (bufferSize * 0.15))
  }
  const noise = audioCtx.createBufferSource()
  noise.buffer = buffer

  // 低音（ドボン）— スケール大 → 低く重い音
  const osc = audioCtx.createOscillator()
  osc.type = 'sine'
  osc.frequency.setValueAtTime(120 - s * 70, now)  // 120Hz(小) → 50Hz(大)
  osc.frequency.exponentialRampToValueAtTime(20 + (1 - s) * 20, now + 0.3 + s * 0.2)

  // ゲイン — スケール大 → 大きい音
  const noiseGain = audioCtx.createGain()
  noiseGain.gain.setValueAtTime(0.1 + s * 0.15, now)
  noiseGain.gain.exponentialRampToValueAtTime(0.001, now + noiseDuration + 0.05)

  const oscGain = audioCtx.createGain()
  oscGain.gain.setValueAtTime(0.08 + s * 0.22, now)
  oscGain.gain.exponentialRampToValueAtTime(0.001, now + 0.4 + s * 0.3)

  // フィルタ — スケール大 → 低めのカットオフ（重い音）
  const filter = audioCtx.createBiquadFilter()
  filter.type = 'lowpass'
  filter.frequency.setValueAtTime(2500 - s * 1500, now)
  filter.frequency.exponentialRampToValueAtTime(300, now + noiseDuration)

  noise.connect(filter)
  filter.connect(noiseGain)
  noiseGain.connect(audioCtx.destination)
  osc.connect(oscGain)
  oscGain.connect(audioCtx.destination)

  noise.start(now)
  noise.stop(now + noiseDuration + 0.05)
  osc.start(now)
  osc.stop(now + 0.5 + s * 0.3)
}

function playLikeSound() {
  if (!audioCtx) return
  const now = audioCtx.currentTime
  const osc = audioCtx.createOscillator()
  osc.type = 'sine'
  osc.frequency.setValueAtTime(600, now)
  osc.frequency.exponentialRampToValueAtTime(900, now + 0.1)
  const gain = audioCtx.createGain()
  gain.gain.setValueAtTime(0.08, now)
  gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15)
  osc.connect(gain)
  gain.connect(audioCtx.destination)
  osc.start(now)
  osc.stop(now + 0.15)
}
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
    { id: 1, text: 'SNSの即時性は本当に必要なのか？', x: -0.4, z: -0.3, mass: 65, heat: 40, likes: 12, weathered: 0.0, scale: 60 },
    { id: 2, text: 'もっとゆっくり議論したい', x: 0.2, z: 0.2, mass: 30, heat: 10, likes: 3, weathered: 0.2, scale: 15 },
    { id: 3, text: '炎上は現代の焚き火である！！', x: 0.0, z: -0.1, mass: 85, heat: 70, likes: 25, weathered: 0.0, scale: 85 },
    { id: 4, text: 'エコーチェンバーを壊すには', x: 0.35, z: 0.3, mass: 45, heat: 25, likes: 7, weathered: 0.4, scale: 40 },
  ]
}

// 一覧のソート（熱量順）
const sortedPosts = computed(() => {
  return [...posts.value].sort((a, b) => b.heat - a.heat)
})

// いいね
function likePost(post) {
  post.likes = (post.likes || 0) + 1
  post.heat = Math.min(100, post.heat + 10)
  playLikeSound()
  // 3Dメッシュの見た目を更新
  const entry = stoneMeshes.find(s => s.post.id === post.id)
  if (entry) {
    updateStoneMeshAppearance(entry)
  }
}

// 石メッシュの見た目を熱量に応じて更新
function updateStoneMeshAppearance(entry) {
  const p = entry.post
  const color = massColor(p.mass)
  const stoneChild = entry.group.children[0]
  if (stoneChild && stoneChild.material) {
    stoneChild.material.emissive = color.clone().multiplyScalar(p.heat > 30 ? 0.3 : 0.05)
    stoneChild.material.opacity = Math.max(0.4, 1 - p.weathered)
  }
}

// 熱量の自然減衰 + 石の消滅
function decayHeat() {
  for (let i = posts.value.length - 1; i >= 0; i--) {
    const p = posts.value[i]
    // 主語デカい → ゆっくり沈む（scale 100 → 0.33倍速、scale 0 → 1倍速）
    const scaleFactor = 1 - (p.scale ?? 30) / 150
    p.heat = Math.max(0, p.heat - 0.5 * scaleFactor)
    p.weathered = Math.min(1, p.weathered + 0.002 * scaleFactor)

    // 熱量ゼロ → 沈没・消滅
    if (p.heat <= 0 && p.weathered >= 0.8) {
      removeStoneMesh(p.id)
      posts.value.splice(i, 1)
      if (selectedPost.value && selectedPost.value.id === p.id) {
        selectedPost.value = null
      }
    } else {
      const entry = stoneMeshes.find(s => s.post.id === p.id)
      if (entry) updateStoneMeshAppearance(entry)
    }
  }
}

// 石メッシュを削除
function removeStoneMesh(postId) {
  const idx = stoneMeshes.findIndex(s => s.post.id === postId)
  if (idx !== -1) {
    // 沈没アニメーション用にマーク
    stoneMeshes[idx].sinking = true
  }
}

// 一覧から石にフォーカス
function focusStone(post) {
  selectedPost.value = post
  showList.value = false
  // ポップアップを画面中央に
  popupStyle.value = { left: '50%', top: '50%', transform: 'translate(-50%, -50%)' }
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

  // OrbitControls（カメラ操作）
  controls = new OrbitControls(camera, renderer.domElement)
  controls.enableDamping = true
  controls.dampingFactor = 0.05
  controls.maxPolarAngle = Math.PI / 2.2  // 水面より下に潜らない
  controls.minDistance = 10
  controls.maxDistance = 50
  controls.target.set(0, 0, 0)

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
    opacity: 0.55,
    side: THREE.DoubleSide,
    flatShading: false,
    depthWrite: false,
  })
  waterMesh = new THREE.Mesh(waterGeo, waterMat)
  waterMesh.rotation.x = -Math.PI / 2
  waterMesh.renderOrder = 1
  scene.add(waterMesh)

  // 水底
  const bottomGeo = new THREE.PlaneGeometry(WATER_SIZE * 1.2, WATER_SIZE * 1.2)
  const bottomMat = new THREE.MeshPhongMaterial({ color: 0x0a1a3a, emissive: 0x050d1a })
  const bottomMesh = new THREE.Mesh(bottomGeo, bottomMat)
  bottomMesh.rotation.x = -Math.PI / 2
  bottomMesh.position.y = -2
  scene.add(bottomMesh)

  // 背景の空（暗い夜空）
  scene.background = new THREE.Color(0x0a1628)

  // --- 周囲の地形 ---
  createTerrain()
  createRocks()

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

// --- 地形生成 ---
function createTerrain() {
  const half = WATER_SIZE / 2
  const terrainMat = new THREE.MeshPhongMaterial({
    color: 0x2a3a2a,
    emissive: 0x0a0f0a,
    specular: 0x111111,
    shininess: 10,
    flatShading: true,
  })

  // 4辺に崖地形を配置
  const sides = [
    { px: 0, pz: -half - 4, rx: 0, ry: 0 },         // 奥
    { px: 0, pz: half + 4, rx: 0, ry: Math.PI },     // 手前
    { px: -half - 4, pz: 0, rx: 0, ry: Math.PI / 2 },  // 左
    { px: half + 4, pz: 0, rx: 0, ry: -Math.PI / 2 },  // 右
  ]

  for (const side of sides) {
    const geo = new THREE.PlaneGeometry(WATER_SIZE + 10, 8, 40, 8)
    const positions = geo.attributes.position

    // 頂点をランダムに変位させて自然な崖を作る
    for (let i = 0; i < positions.count; i++) {
      const x = positions.getX(i)
      const y = positions.getY(i)
      // 上部ほど凹凸を大きく
      const noise = (Math.sin(x * 1.3) * 0.5 + Math.sin(x * 3.7) * 0.3 + Math.random() * 0.4) * (y + 4) * 0.15
      positions.setZ(i, noise)
    }
    geo.computeVertexNormals()

    const mesh = new THREE.Mesh(geo, terrainMat)
    mesh.position.set(side.px, 1, side.pz)
    mesh.rotation.y = side.ry
    scene.add(mesh)
  }

  // 四隅に大きめの岩山
  const corners = [
    { x: -half - 2, z: -half - 2 },
    { x: half + 2, z: -half - 2 },
    { x: -half - 2, z: half + 2 },
    { x: half + 2, z: half + 2 },
  ]
  const rockMat = new THREE.MeshPhongMaterial({
    color: 0x3a3a3a,
    emissive: 0x0a0a0a,
    flatShading: true,
  })

  for (const c of corners) {
    const geo = new THREE.DodecahedronGeometry(3 + Math.random() * 2, 1)
    // 頂点を歪ませて自然に
    const positions = geo.attributes.position
    for (let i = 0; i < positions.count; i++) {
      positions.setX(i, positions.getX(i) * (0.8 + Math.random() * 0.4))
      positions.setY(i, positions.getY(i) * (0.6 + Math.random() * 0.5))
      positions.setZ(i, positions.getZ(i) * (0.8 + Math.random() * 0.4))
    }
    geo.computeVertexNormals()
    const mesh = new THREE.Mesh(geo, rockMat)
    mesh.position.set(c.x, 0.5, c.z)
    scene.add(mesh)
  }
}

// --- 散在する岩 ---
function createRocks() {
  const half = WATER_SIZE / 2
  const rockMat = new THREE.MeshPhongMaterial({
    color: 0x4a4a4a,
    emissive: 0x0a0a0a,
    specular: 0x222222,
    shininess: 15,
    flatShading: true,
  })
  const mossMat = new THREE.MeshPhongMaterial({
    color: 0x2a4a2a,
    emissive: 0x0a1a0a,
    flatShading: true,
  })

  // 水面の縁に沿って岩を散在
  const rockConfigs = []
  for (let i = 0; i < 35; i++) {
    const side = Math.floor(Math.random() * 4)
    let x, z
    const offset = (Math.random() - 0.5) * WATER_SIZE * 0.9
    const edgeDist = half + 0.5 + Math.random() * 3
    if (side === 0) { x = offset; z = -edgeDist }
    else if (side === 1) { x = offset; z = edgeDist }
    else if (side === 2) { x = -edgeDist; z = offset }
    else { x = edgeDist; z = offset }
    rockConfigs.push({
      x, z,
      size: 0.4 + Math.random() * 1.5,
      moss: Math.random() > 0.6,
    })
  }

  for (const rc of rockConfigs) {
    const detail = rc.size > 1 ? 1 : 0
    const geo = new THREE.DodecahedronGeometry(rc.size, detail)
    const positions = geo.attributes.position
    for (let i = 0; i < positions.count; i++) {
      positions.setX(i, positions.getX(i) * (0.7 + Math.random() * 0.6))
      positions.setY(i, positions.getY(i) * (0.5 + Math.random() * 0.6))
      positions.setZ(i, positions.getZ(i) * (0.7 + Math.random() * 0.6))
    }
    geo.computeVertexNormals()

    const mesh = new THREE.Mesh(geo, rc.moss ? mossMat : rockMat)
    mesh.position.set(rc.x, -0.3 + rc.size * 0.3, rc.z)
    mesh.rotation.set(Math.random() * 0.5, Math.random() * Math.PI, Math.random() * 0.3)
    scene.add(mesh)
  }

  // 地面（遠方まで広がる地形）
  const groundGeo = new THREE.PlaneGeometry(120, 120)
  const groundMat = new THREE.MeshPhongMaterial({
    color: 0x1a2a1a,
    emissive: 0x050a05,
  })
  const ground = new THREE.Mesh(groundGeo, groundMat)
  ground.rotation.x = -Math.PI / 2
  ground.position.y = -1.5
  scene.add(ground)
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

  const size = 0.3 + (p.scale ?? 30) * 0.025  // scale 0→0.3, scale 100→2.8
  const color = massColor(p.mass)

  // 石本体（SphereGeometryを歪ませて自然な石に）
  const stoneGeo = new THREE.SphereGeometry(size, 8, 6)
  const positions = stoneGeo.attributes.position
  // シードっぽく使うためにidベースで歪みを安定させる
  const seed = p.id * 137.5
  for (let i = 0; i < positions.count; i++) {
    let x = positions.getX(i)
    let y = positions.getY(i)
    let z = positions.getZ(i)
    // 上下を潰して扁平に（川石っぽく）
    y *= 0.45
    // 頂点ごとにランダムに凹凸
    const noise = 0.7 + 0.6 * Math.abs(Math.sin(seed + i * 3.7) * Math.cos(i * 2.3 + seed * 0.5))
    x *= noise
    z *= noise
    positions.setXYZ(i, x, y, z)
  }
  stoneGeo.computeVertexNormals()

  const stoneMat = new THREE.MeshPhongMaterial({
    color: 0x888888,
    emissive: color.clone().multiplyScalar(p.heat > 30 ? 0.25 : 0.03),
    specular: 0x333333,
    shininess: 15,
    transparent: true,
    opacity: Math.max(0.4, 1 - p.weathered),
    flatShading: true,
  })
  const stoneMesh = new THREE.Mesh(stoneGeo, stoneMat)
  group.add(stoneMesh)

  // テキストラベル（Sprite）水面上に浮かせて表示
  const label = p.text.length > 16 ? p.text.slice(0, 16) + '…' : p.text
  const sprite = createTextSprite(label, color)
  sprite.position.y = 2.0
  group.add(sprite)

  // 配置（水底に沈める）
  const wx = p.x * (WATER_SIZE / 2) * 0.8
  const wz = p.z * (WATER_SIZE / 2) * 0.8
  group.position.set(wx, -1.5, wz)

  scene.add(group)
  stoneMeshes.push({ group, post: p, baseY: -1.5 })
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
    depthTest: false,
  })
  const sprite = new THREE.Sprite(mat)
  sprite.scale.set(4, 0.5, 1)
  sprite.renderOrder = 2
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
function addRipple3D(worldX, worldZ, mass, scale = 30) {
  const s = scale / 100
  const amplitude = 0.1 + s * 0.8    // scale 100 → 0.9 amplitude
  const maxRadius = 3 + s * 15        // scale 100 → 18 radius
  ripples.push({
    x: worldX,
    z: worldZ,
    radius: 0.1,
    maxRadius,
    amplitude,
    speed: Math.max(1.5, 4 - s * 2),
  })
}

function updateRipples(dt) {
  for (let i = ripples.length - 1; i >= 0; i--) {
    const r = ripples[i]
    r.radius += r.speed * dt
    // 最大半径の70%を超えたら徐々にフェードアウト
    const fadeStart = r.maxRadius * 0.7
    if (r.radius > fadeStart) {
      const fadeRatio = (r.radius - fadeStart) / (r.maxRadius - fadeStart)
      r.amplitude *= (1 - fadeRatio * 0.05)
    } else {
      r.amplitude *= 0.998
    }
    if (r.amplitude < 0.001) {
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
function throwStone(targetX, targetZ, mass, text, scale = 30) {
  const startX = -WATER_SIZE / 2 + 2
  const startY = 8
  const startZ = WATER_SIZE / 2 - 2

  const size = 0.3 + scale * 0.025
  const color = massColor(mass)
  const geo = new THREE.SphereGeometry(size, 8, 6)
  const pos = geo.attributes.position
  for (let i = 0; i < pos.count; i++) {
    pos.setY(i, pos.getY(i) * 0.45)
    const n = 0.7 + 0.6 * Math.abs(Math.sin(i * 3.7) * Math.cos(i * 2.3))
    pos.setX(i, pos.getX(i) * n)
    pos.setZ(i, pos.getZ(i) * n)
  }
  geo.computeVertexNormals()
  const mat = new THREE.MeshPhongMaterial({
    color: 0x888888,
    emissive: color.clone().multiplyScalar(0.15),
    specular: 0x333333,
    shininess: 15,
    flatShading: true,
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
    mass, text, scale,
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
    addRipple3D(f.targetX, f.targetZ, f.mass, f.scale)
    playSplashSound(f.mass, f.scale)

    // 石を配置
    const newPost = {
      id: Date.now(),
      text: f.text,
      x: f.targetX / (WATER_SIZE / 2) / 0.8,
      z: f.targetZ / (WATER_SIZE / 2) / 0.8,
      mass: f.mass,
      scale: f.scale,
      heat: 50,
      likes: 0,
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
  f.mesh.position.y = u * u * f.startY + 2 * u * t * f.peakY + t * t * (-1.5)
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

// --- 石のボブ（浮遊）+ 沈没アニメーション ---
function updateStones(elapsed) {
  for (let i = stoneMeshes.length - 1; i >= 0; i--) {
    const s = stoneMeshes[i]

    if (s.sinking) {
      // 沈没アニメーション（主語デカい → ゆっくり沈む）
      const sinkRate = Math.max(0.005, 0.04 - (s.post.scale ?? 30) * 0.0003)
      s.group.position.y -= sinkRate
      s.group.children.forEach(child => {
        if (child.material) {
          child.material.opacity = Math.max(0, (child.material.opacity || 1) - 0.005)
        }
      })
      if (s.group.position.y < -3) {
        scene.remove(s.group)
        stoneMeshes.splice(i, 1)
      }
      continue
    }

    // 水底でわずかに揺れる
    s.group.position.y = s.baseY + Math.sin(elapsed * 0.8 + s.post.id * 0.7) * 0.02
  }
}

// --- クリック → 石選択 or 波紋 ---
function handleClick(event) {
  initAudio()
  const container = threeContainer.value
  const rect = container.getBoundingClientRect()
  const mouse = new THREE.Vector2(
    ((event.clientX - rect.left) / rect.width) * 2 - 1,
    -((event.clientY - rect.top) / rect.height) * 2 + 1,
  )

  const raycaster = new THREE.Raycaster()
  raycaster.setFromCamera(mouse, camera)

  // まず石との交差判定
  const stoneMeshList = stoneMeshes.map(s => s.group.children[0]).filter(Boolean)
  const stoneHits = raycaster.intersectObjects(stoneMeshList)
  if (stoneHits.length > 0) {
    const hitMesh = stoneHits[0].object
    const entry = stoneMeshes.find(s => s.group.children[0] === hitMesh)
    if (entry) {
      selectedPost.value = entry.post
      // ポップアップ位置をクリック位置に（見切れ防止）
      const px = Math.min(event.clientX, window.innerWidth - 340)
      const py = Math.max(10, Math.min(event.clientY - 80, window.innerHeight - 250))
      popupStyle.value = {
        left: Math.max(10, px) + 'px',
        top: py + 'px',
      }
      return
    }
  }

  // 石に当たらなかった → 水面に波紋
  selectedPost.value = null
  const waterHits = raycaster.intersectObject(waterMesh)
  if (waterHits.length > 0) {
    const point = waterHits[0].point
    addRipple3D(point.x, point.z, 15)
    addSplash3D(point.x, point.z, 10)
    playSplashSound(10)
  }
}

// --- メインループ ---
let decayTimer = 0
function animate() {
  animationId = requestAnimationFrame(animate)
  const dt = Math.min(clock.getDelta(), 0.05)
  const elapsed = clock.getElapsedTime()

  updateWater(elapsed)
  updateRipples(dt)
  updateSplashes(dt)
  updateStones(elapsed)
  updateFlyingStone(dt)

  // 熱量の自然減衰（2秒ごと）
  decayTimer += dt
  if (decayTimer > 2) {
    decayTimer = 0
    decayHeat()
  }

  controls.update()
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
    let subjectScale = 30

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
        subjectScale = data.subject_scale ?? 30
      }
    } catch {
      console.warn('Gravity API未接続。フォールバック値を使用')
    }

    lastMass.value = mass
    lastGravity.value = gravity
    lastScale.value = subjectScale

    // ランダムな着水位置（水面中央付近）
    const targetX = (Math.random() - 0.5) * WATER_SIZE * 0.6
    const targetZ = (Math.random() - 0.5) * WATER_SIZE * 0.6
    throwStone(targetX, targetZ, mass, postText.value, subjectScale)

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
  background: #0a1628;
  overflow: hidden;
}

.header-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  z-index: 30;
  padding: 12px 20px;
  text-align: center;
  color: white;
  pointer-events: none;
  background: linear-gradient(to bottom, rgba(10, 22, 40, 0.7) 0%, transparent 100%);
}

.header-overlay h1 {
  font-size: 1.8em;
  margin-bottom: 2px;
  background: linear-gradient(90deg, #4af, #a8f, #4af);
  background-size: 200% auto;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: shimmer 3s ease-in-out infinite;
  text-shadow: none;
}

@keyframes shimmer {
  0%, 100% { background-position: 0% center; }
  50% { background-position: 200% center; }
}

.header-overlay p {
  font-size: 0.85em;
  opacity: 0.5;
}

.main-content {
  display: flex;
  width: 100%;
  height: 100%;
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
  .header-overlay h1 {
    font-size: 1.2em;
  }
  .header-overlay {
    padding: 8px;
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

/* --- 石詳細ポップアップ --- */
.stone-popup {
  position: fixed;
  z-index: 100;
  background: rgba(10, 22, 50, 0.95);
  border: 1px solid rgba(100, 180, 255, 0.3);
  border-radius: 12px;
  padding: 16px;
  min-width: 220px;
  max-width: 320px;
  color: white;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
}

.popup-close {
  position: absolute;
  top: 8px;
  right: 10px;
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.4);
  font-size: 16px;
  cursor: pointer;
}

.popup-close:hover {
  color: white;
}

.popup-text {
  font-size: 1em;
  line-height: 1.5;
  margin-bottom: 12px;
  word-break: break-word;
}

.popup-stats {
  display: flex;
  gap: 12px;
  font-size: 0.8em;
  opacity: 0.6;
  margin-bottom: 12px;
}

.like-btn {
  width: 100%;
  padding: 10px;
  border: 1px solid rgba(255, 120, 120, 0.4);
  background: rgba(255, 120, 120, 0.1);
  color: #ffaaaa;
  font-weight: bold;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  font-size: 0.95em;
}

.like-btn:hover {
  background: rgba(255, 120, 120, 0.25);
  border-color: rgba(255, 120, 120, 0.7);
  transform: scale(1.03);
}

.popup-enter-active, .popup-leave-active {
  transition: opacity 0.2s, transform 0.2s;
}
.popup-enter-from, .popup-leave-to {
  opacity: 0;
  transform: scale(0.9) translateY(10px);
}

/* --- 石一覧トグル --- */
.list-toggle {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 50;
  padding: 8px 16px;
  border: 1px solid rgba(100, 180, 255, 0.3);
  background: rgba(10, 22, 50, 0.85);
  color: #aaddff;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9em;
  backdrop-filter: blur(8px);
  transition: all 0.2s;
}

.list-toggle:hover {
  background: rgba(100, 180, 255, 0.2);
}

/* --- 石一覧パネル --- */
.stone-list {
  position: absolute;
  top: 50px;
  left: 10px;
  z-index: 50;
  width: 320px;
  max-height: calc(100% - 70px);
  overflow-y: auto;
  background: rgba(10, 22, 50, 0.92);
  border: 1px solid rgba(100, 180, 255, 0.15);
  border-radius: 12px;
  padding: 16px;
  color: white;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.stone-list h3 {
  font-size: 1em;
  margin-bottom: 12px;
  opacity: 0.8;
}

.list-empty {
  opacity: 0.4;
  font-size: 0.9em;
  text-align: center;
  padding: 20px 0;
}

.list-item {
  padding: 10px;
  border: 1px solid rgba(100, 180, 255, 0.08);
  border-radius: 8px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.list-item:hover {
  background: rgba(100, 180, 255, 0.08);
  border-color: rgba(100, 180, 255, 0.2);
}

.list-item-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.list-heat-bar {
  flex: 1;
  height: 4px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 2px;
  overflow: hidden;
}

.list-heat-fill {
  display: block;
  height: 100%;
  background: linear-gradient(90deg, #4af, #f64);
  border-radius: 2px;
  transition: width 0.5s;
}

.list-likes {
  font-size: 0.75em;
  opacity: 0.5;
  white-space: nowrap;
}

.list-likes::before {
  content: '\2764\FE0F ';
}

.list-item-text {
  font-size: 0.9em;
  line-height: 1.4;
  margin-bottom: 4px;
  word-break: break-word;
}

.list-item-meta {
  display: flex;
  gap: 12px;
  font-size: 0.75em;
  opacity: 0.4;
}

.slide-enter-active, .slide-leave-active {
  transition: opacity 0.25s, transform 0.25s;
}
.slide-enter-from, .slide-leave-to {
  opacity: 0;
  transform: translateX(-20px);
}

.stone-list::-webkit-scrollbar {
  width: 4px;
}
.stone-list::-webkit-scrollbar-track {
  background: transparent;
}
.stone-list::-webkit-scrollbar-thumb {
  background: rgba(100, 180, 255, 0.2);
  border-radius: 2px;
}
</style>
