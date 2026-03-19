<template>
  <div class="container">
    <div class="main-content">
      <div ref="threeContainer" class="three-canvas" @click="handleClick"></div>

      <!-- ヘッダー（Canvas上にオーバーレイ） -->
      <div class="header-overlay">
        <h1>いい波立ってんね～</h1>
        <p>議論が波紋となり、風となって拡散される</p>
      </div>

      <div class="wind-overlay" v-if="winds.length > 0">
        <div v-for="(w, index) in winds.slice(0, 3)" :key="w.id" class="wind-message" :style="{ animationDelay: (index * 5) + 's' }">
          <span class="wind-icon">🍃</span>
          {{ w.summary }}
        </div>
      </div>

      <!-- 石詳細ポップアップ -->
      <Transition name="popup">
        <div v-if="selectedPost" class="stone-popup" :style="popupStyle" @click.stop>
          <button class="popup-close" @click="selectedPost = null">x</button>
          <p class="popup-text">{{ selectedPost.text }}</p>
          <div class="popup-stats">
            <span>質量 {{ selectedPost.mass }}</span>
            <span>主語 {{ selectedPost.scale ?? '—' }}</span>
            <span>熱量 {{ Math.round(selectedPost.heat * 100) / 100 }}</span>
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
              <span>熱量 {{ Math.round(p.heat * 100) / 100 }}</span>
              <span class="list-weathering" :class="{ danger: p.weathered >= 0.7 }">
                風化中 {{ Math.round(p.weathered * 100) }}%
              </span>
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
              :disabled="isSubmitting || !postText.trim() || postText.length > 500 || isFlying"
            >
              {{ isSubmitting ? '投じ中...' : '一石を投じる' }}
            </button>
          </div>
          <p v-if="lastMass !== null" class="mass-result">
            質量: {{ lastMass }} / 重力: {{ lastGravity }} / 係数: {{ lastGravityCoef ?? 1 }} / 主語: {{ lastScale }}
          </p>
        </div>

        <div class="info-section">
          <h3>ステータス</h3>
          <p>投稿数: {{ posts.length }}</p>
          <p>API接続: {{ apiStatus }}</p>
          <button
            v-if="xrSupported"
            class="vr-btn"
            :disabled="!xrActive && !postText.trim()"
            @click="toggleXR"
          >{{ xrActive ? 'VR終了' : 'VRで投げる' }}</button>
          <p v-if="xrActive" class="xr-hint">トリガーを握って振り、離すと投石</p>
          <button
            class="demo-seed-btn"
            :disabled="isDemoSeeding"
            @click="seedDemoData"
          >{{ isDemoSeeding ? '投入中...' : 'デモデータ投入' }}</button>
          <button
            class="reset-data-btn"
            :disabled="isResetting"
            @click="resetAllData"
          >{{ isResetting ? 'リセット中...' : 'データリセット' }}</button>
          <button
            class="shark-btn"
            :disabled="!!shark"
            @click="spawnShark(); wsSend({ type: 'shark-spawn' })"
          >サメ襲来</button>
        </div>
      </div>

      <!-- サメカットイン演出 -->
      <Transition name="cutin">
        <div v-if="sharkCutinActive" class="shark-cutin-overlay">
          <div class="cutin-bar cutin-bar-top"></div>
          <div class="cutin-bar cutin-bar-bottom"></div>
          <div class="cutin-center">
            <div class="cutin-flash"></div>
            <div class="cutin-shark">
              <span class="cutin-shark-emoji">🦈</span>
              <span class="cutin-shark-text">サメ 襲来</span>
            </div>
            <div class="cutin-line cutin-line-left"></div>
            <div class="cutin-line cutin-line-right"></div>
          </div>
        </div>
      </Transition>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import * as THREE from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import * as CANNON from 'cannon-es'

// --- リモコンモード判定 ---
const isRemoteMode = ref(new URLSearchParams(window.location.search).has('remote'))
const remoteText = ref('')
let remoteSendTimer = null

// リモコン: テキスト変更をサーバーに送信（デバウンス）
watch(remoteText, (val) => {
  if (!isRemoteMode.value) return
  clearTimeout(remoteSendTimer)
  remoteSendTimer = setTimeout(() => {
    fetch('/api/gravity/vr-remote', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'setText', text: val })
    }).catch(() => {})
  }, 200)
})

function sendRemoteExit() {
  fetch('/api/gravity/vr-remote', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'exit' })
  }).catch(() => {})
}

const threeContainer = ref(null)
const postText = ref('')
const apiStatus = ref('確認中...')
const isSubmitting = ref(false)
const lastMass = ref(null)
const lastGravity = ref(null)
const lastScale = ref(null)
const lastGravityCoef = ref(null)

// --- UI状態 ---
const selectedPost = ref(null)
const popupStyle = ref({})
const showList = ref(false)

const winds = ref([]);
const isDemoSeeding = ref(false);
const isResetting = ref(false);

// --- Three.js 変数 ---
let scene, camera, renderer, clock, controls
let waterMesh, waterGeo

// --- 物理演算 (cannon-es) ---
let physicsWorld
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

// ジュー音（熱い石の着水）
function playSizzleSound(heat) {
  if (!audioCtx) return
  const now = audioCtx.currentTime
  const intensity = Math.min(heat / 100, 1)

  // ホワイトノイズ（ジュー）
  const duration = 0.5 + intensity * 1.0
  const bufferSize = Math.floor(audioCtx.sampleRate * duration)
  const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate)
  const data = buffer.getChannelData(0)
  for (let i = 0; i < bufferSize; i++) {
    data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (bufferSize * 0.3))
  }
  const noise = audioCtx.createBufferSource()
  noise.buffer = buffer

  // ハイパスフィルタ（シュー感）
  const hp = audioCtx.createBiquadFilter()
  hp.type = 'highpass'
  hp.frequency.setValueAtTime(3000 + intensity * 2000, now)
  hp.frequency.exponentialRampToValueAtTime(1000, now + duration)

  // バンドパス（ジュー感）
  const bp = audioCtx.createBiquadFilter()
  bp.type = 'bandpass'
  bp.frequency.setValueAtTime(4000, now)
  bp.Q.value = 0.5

  const gain = audioCtx.createGain()
  gain.gain.setValueAtTime(0.06 + intensity * 0.1, now)
  gain.gain.exponentialRampToValueAtTime(0.001, now + duration)

  noise.connect(hp)
  hp.connect(bp)
  bp.connect(gain)
  gain.connect(audioCtx.destination)
  noise.start(now)
  noise.stop(now + duration)
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
// 泡パーティクル
let bubbleParticles = null
let bubbleData = []
// 蒸気パーティクル
let steamParticles = null
let steamData = []
// 魚データ
const fishes = []
// サメ
let shark = null
const sharkCutinActive = ref(false)
let vrCutinSprite = null  // VR用3Dカットイン
let vrCutinTimer = 0
// 水柱データ
const waterColumns = []
// 飛行中の石
let flyingStone = null
const isFlying = ref(false)

// --- 投稿データ管理 ---
const posts = ref([])

// APIから投稿一覧を取得
async function loadPosts() {
  try {
    const res = await fetch(`${logicApiUrl}/posts`)
    if (res.ok) {
      const postsData = await res.json()
      posts.value = postsData.map(post => ({
        ...post,
        likes: post.likes || 0,
        scale: post.scale || 30,
        heat: parseFloat(post.heat) || 0,
        mass: parseFloat(post.mass) || 0,
        x: parseFloat(post.x) || 0,
        z: parseFloat(post.y) || 0,
        weathered: parseFloat(post.weathered) || 0,
      }))
      apiStatus.value = '接続済み'
    } else {
      throw new Error('投稿一覧取得に失敗しました')
    }
  } catch (error) {
    console.error('投稿一覧取得エラー:', error)
    apiStatus.value = '接続エラー'
    // エラー時はモックデータを表示
    loadMockPosts()
  }
}

function loadMockPosts() {
  posts.value = [
    { id: 1, text: 'SNSの即時性は本当に必要なのか？', x: -0.4, z: -0.3, mass: 65, heat: 40, likes: 12, weathered: 0.0, scale: 60 },
    { id: 2, text: 'もっとゆっくり議論したい', x: 0.2, z: 0.2, mass: 30, heat: 10, likes: 3, weathered: 0.2, scale: 15 },
    { id: 3, text: '炎上は現代の焚き火である！！', x: 0.0, z: -0.1, mass: 85, heat: 70, likes: 25, weathered: 0.0, scale: 85 },
    { id: 4, text: 'エコーチェンバーを壊すには', x: 0.35, z: 0.3, mass: 45, heat: 25, likes: 7, weathered: 0.4, scale: 40 },
  ]
}

async function loadWinds() {
  try {
    const res = await fetch(`${logicApiUrl}/winds`)
    if (res.ok) {
      winds.value = await res.json()
    }
  } catch (error) {
    console.error('風の取得に失敗しました:', error)
  }
}

// デモデータ投入（既存データを残して追加）
async function seedDemoData() {
  if (isDemoSeeding.value) return
  isDemoSeeding.value = true
  vrPhoneDebugMsg = `API: ${logicApiUrl}`
  updateVRPhoneScreen()
  try {
    const res = await fetch(`${logicApiUrl}/demo-seed`, { method: 'POST' })
    vrPhoneDebugMsg = `seed: status=${res.status}`
    updateVRPhoneScreen()
    if (res.ok) {
      const data = await res.json()
      // 既存のIDセットを記録
      const existingIds = new Set(posts.value.map(p => p.id))
      // データ再読み込み
      await loadPosts()
      // 新しく追加された投稿だけ3Dシーンに石を追加（上空から落下で波紋を起こす）
      let added = 0
      posts.value.forEach(p => {
        if (!existingIds.has(p.id)) {
          addStoneMesh(p, 5)
          added++
        }
      })
      await loadWinds()
      wsSend({ type: 'posts-added' })
      vrPhoneDebugMsg = `seed: OK +${added} stones`
    } else {
      vrPhoneDebugMsg = `seed: FAIL ${res.status}`
    }
  } catch (error) {
    vrPhoneDebugMsg = `ERR[${logicApiUrl}] ${error.message}`
  } finally {
    isDemoSeeding.value = false
    updateVRPhoneScreen()
  }
}

// 全データリセット
async function resetAllData() {
  if (isResetting.value) return
  isResetting.value = true
  vrPhoneDebugMsg = 'reset: fetching...'
  updateVRPhoneScreen()
  try {
    const res = await fetch(`${logicApiUrl}/reset-data`, { method: 'POST' })
    vrPhoneDebugMsg = `reset: status=${res.status}`
    updateVRPhoneScreen()
    if (res.ok) {
      stoneMeshes.forEach(s => {
        scene.remove(s.group)
        if (s.body) physicsWorld.removeBody(s.body)
      })
      stoneMeshes.length = 0
      posts.value = []
      winds.value = []
      selectedPost.value = null
      wsSend({ type: 'posts-reset' })
      vrPhoneDebugMsg = `reset: OK cleared`
    } else {
      vrPhoneDebugMsg = `reset: FAIL ${res.status}`
    }
  } catch (error) {
    vrPhoneDebugMsg = `reset: ERR ${error.message}`
  } finally {
    isResetting.value = false
    updateVRPhoneScreen()
  }
}

// 一覧のソート（熱量順）
const sortedPosts = computed(() => {
  return [...posts.value].sort((a, b) => b.heat - a.heat)
})

// いいね
async function likePost(post) {
  try {
    // Logic APIで熱量計算
    const heatRes = await fetch(`${logicApiUrl}/heat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ post_id: post.id })
    })

    if (heatRes.ok) {
      const heatData = await heatRes.json()
      post.heat = Math.min(100, heatData.heat)
    } else {
      // APIエラー時はローカルで増やす
      post.heat = Math.min(100, post.heat + 10)
    }

    post.likes = (post.likes || 0) + 1
    playLikeSound()
    wsSend({ type: 'post-liked', postId: post.id, heat: post.heat })
    // 3Dメッシュの見た目を更新
    const entry = stoneMeshes.find(s => s.post.id === post.id)
    if (entry) {
      updateStoneMeshAppearance(entry)
    }
  } catch (error) {
    console.error('いいねエラー:', error)
    // エラー時はローカルで増やす
    post.heat = Math.min(100, post.heat + 10)
    post.likes = (post.likes || 0) + 1
    post.heat = Math.min(100, post.heat + 10)
    playLikeSound()
    const entry = stoneMeshes.find(s => s.post.id === post.id)
    if (entry) {
      updateStoneMeshAppearance(entry)
    }
  }
}

// 石メッシュの見た目を熱量・風化度に応じて更新
function updateStoneMeshAppearance(entry, elapsed = 0) {
  const p = entry.post
  const stoneChild = entry.group.children[0]
  if (!stoneChild || !stoneChild.material) return

  const w = p.weathered  // 0.0〜1.0

  // グレースケール化: 風化が進むほど彩度を失う
  const color = massColor(p.mass)
  const gray = (color.r * 0.299 + color.g * 0.587 + color.b * 0.114)
  const r = color.r * (1 - w) + gray * w
  const g = color.g * (1 - w) + gray * w
  const b = color.b * (1 - w) + gray * w

  // 石本体の色: 熱量に応じてグレー→赤
  const heatRatio = Math.min((parseFloat(p.heat) || 0) / 100, 1)  // 0〜1
  const baseGray = 0.45 * (1 - w) + 0.25 * w   // 風化でさらに暗く
  const stoneR = baseGray + heatRatio * 0.55     // 熱いほど赤く
  const stoneG = baseGray * (1 - heatRatio * 0.6) // 熱いほど緑を減らす
  const stoneB = baseGray * (1 - heatRatio * 0.7) // 熱いほど青を減らす
  stoneChild.material.color.setRGB(stoneR, stoneG, stoneB)

  // emissive: 熱量に応じて赤く発光
  const emR = heatRatio * 0.4
  const emG = heatRatio * 0.05
  const emB = 0
  stoneChild.material.emissive.setRGB(emR * (1 - w), emG * (1 - w), emB)

  // 風化度70%以上: 脈動アニメーション
  let opacity = Math.max(0.15, 1 - w * 0.85)
  if (w >= 0.7 && elapsed > 0) {
    const pulse = 0.5 + 0.5 * Math.sin(elapsed * 4)
    opacity *= (0.6 + 0.4 * pulse)
  }
  stoneChild.material.opacity = opacity

  // 境界線リング（熱量20以上で赤〜オレンジに光る）
  let ring = entry.group.getObjectByName('heatRing')
  if (p.heat >= 20) {
    if (!ring) {
      const ringGeo = new THREE.TorusGeometry(entry.size * 1.3, 0.04, 8, 32)
      const ringMat = new THREE.MeshBasicMaterial({
        color: 0xff6633,
        transparent: true,
        depthWrite: false,
      })
      ring = new THREE.Mesh(ringGeo, ringMat)
      ring.name = 'heatRing'
      ring.rotation.x = Math.PI / 2
      entry.group.add(ring)
    }
    ring.material.opacity = 0.3 + (p.heat / 100) * 0.5
    ring.visible = true
  } else if (ring) {
    ring.visible = false
  }
}

// 熱量の自然減衰 + 石の消滅
async function decayHeat() {
  for (let i = posts.value.length - 1; i >= 0; i--) {
    const p = posts.value[i]
    // 主語デカい → ゆっくり沈む（scale 100 → 0.33倍速、scale 0 → 1倍速）
    const scaleFactor = 1 - (p.scale ?? 30) / 150
    p.heat = Math.max(0, p.heat - 0.5 * scaleFactor)

    // 熱量が一定ライン（20）を下回ると風化が進む
    const WEATHERING_THRESHOLD = 20;
    if (p.heat < WEATHERING_THRESHOLD) {
      // 熱量0に近いほど加速（最大、0の時は間隔の約1/4で消滅）
      const heatRatio = 1 - p.heat / WEATHERING_THRESHOLD  // 0〜1
      const speed = 0.005 + heatRatio * 0.02;
      p.weathered = Math.min(1, p.weathered + speed * scaleFactor);
    } else {
      // 熱量が閾値以上の場合は徐々に自己修復（風化が戻る）
      p.weathered = Math.max(0, p.weathered - 0.01);
    }

    // 風化が完全に進行（1.0に到達）したら沈没・消滅
    if (p.weathered >= 1.0) {
      removeStoneMesh(p.id)
      posts.value.splice(i, 1)
      if (selectedPost.value && selectedPost.value.id === p.id) {
        selectedPost.value = null
      }
    } else {
      const entry = stoneMeshes.find(s => s.post.id === p.id)
      if (entry) updateStoneMeshAppearance(entry, 0)
    }
  }
}

// 石メッシュを削除
function removeStoneMesh(postId) {
  const idx = stoneMeshes.findIndex(s => s.post.id === postId)
  if (idx !== -1) {
    stoneMeshes[idx].sinking = true
    // 物理ボディを静的に変更（他の石に影響しないように）
    if (stoneMeshes[idx].body) {
      stoneMeshes[idx].body.mass = 0
      stoneMeshes[idx].body.updateMassProperties()
    }
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
const BRIDGE_Z = WATER_SIZE / 2 + 1  // 池の手前端（少し前に出す）
const BRIDGE_Y = 3  // 橋の高さ

function buildBridge() {
  const bridge = new THREE.Group()
  const woodColor = 0x8B5E3C
  const darkWood = 0x5C3A1E

  // 橋板（メインデッキ）
  const deckGeo = new THREE.BoxGeometry(8, 0.2, 2.5)
  const deckMat = new THREE.MeshPhongMaterial({ color: woodColor, flatShading: true })
  const deck = new THREE.Mesh(deckGeo, deckMat)
  deck.position.set(0, BRIDGE_Y, BRIDGE_Z)
  bridge.add(deck)

  // 横板の隙間表現（暗い板を少しずらして重ねる）
  for (let i = -3; i <= 3; i++) {
    const plankGeo = new THREE.BoxGeometry(0.02, 0.22, 2.5)
    const plankMat = new THREE.MeshPhongMaterial({ color: darkWood })
    const plank = new THREE.Mesh(plankGeo, plankMat)
    plank.position.set(i * 1.14, BRIDGE_Y, BRIDGE_Z)
    bridge.add(plank)
  }

  // 手すり（左右）
  const railMat = new THREE.MeshPhongMaterial({ color: darkWood })
  for (const side of [-1, 1]) {
    // 手すりの横棒
    const railGeo = new THREE.BoxGeometry(8, 0.1, 0.1)
    const rail = new THREE.Mesh(railGeo, railMat)
    rail.position.set(0, BRIDGE_Y + 1, BRIDGE_Z + side * 1.2)
    bridge.add(rail)

    // 支柱
    for (let x = -4; x <= 4; x += 2) {
      const postGeo = new THREE.BoxGeometry(0.12, 1, 0.12)
      const post = new THREE.Mesh(postGeo, railMat)
      post.position.set(x, BRIDGE_Y + 0.5, BRIDGE_Z + side * 1.2)
      bridge.add(post)
    }
  }

  // 脚（4本）
  const legMat = new THREE.MeshPhongMaterial({ color: darkWood })
  for (const x of [-3.5, 3.5]) {
    for (const z of [-0.8, 0.8]) {
      const legGeo = new THREE.CylinderGeometry(0.12, 0.15, BRIDGE_Y + 1, 6)
      const leg = new THREE.Mesh(legGeo, legMat)
      leg.position.set(x, (BRIDGE_Y - 1) / 2, BRIDGE_Z + z)
      bridge.add(leg)
    }
  }

  scene.add(bridge)
}

// --- VR内スマホパネル（インタラクティブ） ---
let vrPhoneMesh = null
let vrPhoneScreen = null  // レイキャスト対象の画面メッシュ
let vrPhoneCanvas = null
let vrPhoneCtx = null
let vrPhoneTexture = null
let vrPhoneLastText = ''
let vrPhoneHoverIdx = -1  // ホバー中のボタンindex
let vrPhoneDebugMsg = ''  // デバッグ表示用
let vrPhoneLaser = null    // レーザーポインター
let vrPhoneGrabbing = false  // スマホを掴んでいるか
let vrPhoneGrabOffset = new THREE.Vector3()  // 掴んだ時のオフセット
const vrPhoneRaycaster = new THREE.Raycaster()

// ひらがなキーボード配列
const VR_KB_LAYOUTS = {
  hiragana: [
    ['あ','い','う','え','お','か','き','く','け','こ'],
    ['さ','し','す','せ','そ','た','ち','つ','て','と'],
    ['な','に','ぬ','ね','の','は','ひ','ふ','へ','ほ'],
    ['ま','み','む','め','も','や','ゆ','よ','ら','り'],
    ['る','れ','ろ','わ','を','ん','ー','！','？','。'],
  ],
  katakana: [
    ['ア','イ','ウ','エ','オ','カ','キ','ク','ケ','コ'],
    ['サ','シ','ス','セ','ソ','タ','チ','ツ','テ','ト'],
    ['ナ','ニ','ヌ','ネ','ノ','ハ','ヒ','フ','ヘ','ホ'],
    ['マ','ミ','ム','メ','モ','ヤ','ユ','ヨ','ラ','リ'],
    ['ル','レ','ロ','ワ','ヲ','ン','ー','！','？','。'],
  ],
  number: [
    ['1','2','3','4','5','6','7','8','9','0'],
    ['@','#','$','%','&','*','+','-','=','/'],
    ['（','）','「','」','『','』','【','】','〜','…'],
    ['A','B','C','D','E','F','G','H','I','J'],
    ['K','L','M','N','O','P','Q','R','S','T'],
  ],
}
const VR_KB_MODE_ORDER = ['hiragana', 'katakana', 'number']
const VR_KB_MODE_LABELS = { hiragana: 'あ', katakana: 'ア', number: '123' }
let vrKbMode = 'hiragana'

// ボタン領域を動的に生成（モード切替時に再構築）
let VR_PHONE_BUTTONS = []
const KB_TOP = 100  // キーボード開始Y
const KB_KEY_W = 30
const KB_KEY_H = 42
const KB_PAD = 2

function buildVRKeyboardButtons() {
  VR_PHONE_BUTTONS = []
  const rows = VR_KB_LAYOUTS[vrKbMode]
  for (let r = 0; r < rows.length; r++) {
    for (let c = 0; c < rows[r].length; c++) {
      VR_PHONE_BUTTONS.push({
        x: 4 + c * (KB_KEY_W + KB_PAD), y: KB_TOP + r * (KB_KEY_H + KB_PAD),
        w: KB_KEY_W, h: KB_KEY_H,
        type: 'key', char: rows[r][c],
      })
    }
  }
  // 機能キー行
  const FUNC_Y = KB_TOP + 5 * (KB_KEY_H + KB_PAD) + 6
  VR_PHONE_BUTTONS.push({ x: 4, y: FUNC_Y, w: 58, h: 38, type: 'backspace' })
  VR_PHONE_BUTTONS.push({ x: 66, y: FUNC_Y, w: 50, h: 38, type: 'space' })
  VR_PHONE_BUTTONS.push({ x: 120, y: FUNC_Y, w: 50, h: 38, type: 'clear' })
  VR_PHONE_BUTTONS.push({ x: 174, y: FUNC_Y, w: 50, h: 38, type: 'mode' })
  VR_PHONE_BUTTONS.push({ x: 228, y: FUNC_Y, w: 88, h: 38, type: 'exit' })
  // デモ用ボタン行
  const DEMO_Y = FUNC_Y + 46
  VR_PHONE_BUTTONS.push({ x: 4, y: DEMO_Y, w: 100, h: 38, type: 'demo-seed' })
  VR_PHONE_BUTTONS.push({ x: 108, y: DEMO_Y, w: 100, h: 38, type: 'reset-data' })
  VR_PHONE_BUTTONS.push({ x: 212, y: DEMO_Y, w: 104, h: 38, type: 'shark' })
}
buildVRKeyboardButtons()

function buildVRPhone() {
  vrPhoneCanvas = document.createElement('canvas')
  vrPhoneCanvas.width = 320
  vrPhoneCanvas.height = 540
  vrPhoneCtx = vrPhoneCanvas.getContext('2d')
  vrPhoneTexture = new THREE.CanvasTexture(vrPhoneCanvas)
  vrPhoneTexture.minFilter = THREE.LinearFilter

  const phoneGroup = new THREE.Group()

  // 画面（レイキャスト対象）
  const screenGeo = new THREE.PlaneGeometry(0.35, 0.59)
  const screenMat = new THREE.MeshBasicMaterial({ map: vrPhoneTexture })
  vrPhoneScreen = new THREE.Mesh(screenGeo, screenMat)
  vrPhoneScreen.position.z = 0.011
  phoneGroup.add(vrPhoneScreen)

  // 筐体
  const frameGeo = new THREE.BoxGeometry(0.38, 0.63, 0.02)
  const frameMat = new THREE.MeshPhongMaterial({ color: 0x222222 })
  phoneGroup.add(new THREE.Mesh(frameGeo, frameMat))

  phoneGroup.position.set(-0.25, BRIDGE_Y + 0.9, BRIDGE_Z - 0.5)
  phoneGroup.rotation.set(-0.3, 0.4, 0)

  scene.add(phoneGroup)
  vrPhoneMesh = phoneGroup

  // レーザーポインター（コントローラーから出る線）
  const laserGeo = new THREE.BufferGeometry().setFromPoints([
    new THREE.Vector3(0, 0, 0),
    new THREE.Vector3(0, 0, -3),
  ])
  const laserMat = new THREE.LineBasicMaterial({ color: 0x44aaff, transparent: true, opacity: 0.5 })
  vrPhoneLaser = new THREE.Line(laserGeo, laserMat)
  vrPhoneLaser.visible = false
  scene.add(vrPhoneLaser)

  updateVRPhoneScreen()
}

function updateVRPhoneScreen() {
  if (!vrPhoneCtx) return
  const ctx = vrPhoneCtx
  const w = 320, h = 540
  const text = postText.value || ''

  // 背景
  ctx.fillStyle = '#0f1a2e'
  ctx.fillRect(0, 0, w, h)

  // テキスト表示エリア
  ctx.fillStyle = '#0a1222'
  ctx.fillRect(4, 4, w - 8, 88)
  ctx.strokeStyle = text ? '#4488ff' : '#334466'
  ctx.lineWidth = 2
  ctx.strokeRect(4, 4, w - 8, 88)

  if (text) {
    ctx.fillStyle = '#ffffff'
    ctx.font = '15px sans-serif'
    ctx.textAlign = 'left'
    const maxW = w - 20
    let line = '', ly = 24
    for (const ch of text) {
      const test = line + ch
      if (ctx.measureText(test).width > maxW) {
        ctx.fillText(line, 12, ly)
        line = ch; ly += 18
        if (ly > 80) break
      } else { line = test }
    }
    if (line && ly <= 80) ctx.fillText(line, 12, ly)
    // カーソル点滅
    const cursorX = 12 + ctx.measureText(line).width + 2
    if (Math.floor(Date.now() / 500) % 2 === 0) {
      ctx.fillStyle = '#4488ff'
      ctx.fillRect(cursorX, ly - 12, 2, 16)
    }
  } else {
    ctx.fillStyle = 'rgba(255,255,255,0.25)'
    ctx.font = '13px sans-serif'
    ctx.textAlign = 'center'
    ctx.fillText('ここに文章が表示されます', w / 2, 50)
  }

  // キーボード
  for (let i = 0; i < VR_PHONE_BUTTONS.length; i++) {
    const btn = VR_PHONE_BUTTONS[i]
    const isHover = vrPhoneHoverIdx === i

    if (btn.type === 'key') {
      ctx.fillStyle = isHover ? 'rgba(68,136,255,0.4)' : 'rgba(255,255,255,0.1)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 4)
      ctx.fill()
      ctx.fillStyle = isHover ? '#ffffff' : 'rgba(255,255,255,0.8)'
      ctx.font = '16px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText(btn.char, btn.x + btn.w / 2, btn.y + btn.h / 2 + 6)
    } else if (btn.type === 'backspace') {
      ctx.fillStyle = isHover ? 'rgba(255,160,60,0.5)' : 'rgba(255,160,60,0.2)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = '#ffcc88'
      ctx.font = '13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('削除', btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'space') {
      ctx.fillStyle = isHover ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.08)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = 'rgba(255,255,255,0.5)'
      ctx.font = '13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('空白', btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'clear') {
      ctx.fillStyle = isHover ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.08)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = 'rgba(255,255,255,0.5)'
      ctx.font = '13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('全消', btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'mode') {
      ctx.fillStyle = isHover ? 'rgba(100,200,100,0.5)' : 'rgba(100,200,100,0.2)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = '#aaffaa'
      ctx.font = 'bold 13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText(VR_KB_MODE_LABELS[vrKbMode], btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'exit') {
      ctx.fillStyle = isHover ? 'rgba(255,80,80,0.5)' : 'rgba(255,80,80,0.25)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = '#ffaaaa'
      ctx.font = 'bold 14px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('VR終了', btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'demo-seed') {
      ctx.fillStyle = isHover ? 'rgba(255,160,50,0.5)' : 'rgba(255,160,50,0.2)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = '#ffcc88'
      ctx.font = 'bold 13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('デモ投入', btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'reset-data') {
      ctx.fillStyle = isHover ? 'rgba(255,70,70,0.5)' : 'rgba(255,70,70,0.2)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = '#ffaaaa'
      ctx.font = 'bold 13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('リセット', btn.x + btn.w / 2, btn.y + 24)
    } else if (btn.type === 'shark') {
      ctx.fillStyle = isHover ? 'rgba(100,100,255,0.5)' : 'rgba(100,100,255,0.2)'
      ctx.beginPath()
      ctx.roundRect(btn.x, btn.y, btn.w, btn.h, 6)
      ctx.fill()
      ctx.fillStyle = '#aaccff'
      ctx.font = 'bold 13px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText('サメ襲来', btn.x + btn.w / 2, btn.y + 24)
    }
  }

  // デバッグ表示
  if (vrPhoneDebugMsg) {
    ctx.fillStyle = 'rgba(0,0,0,0.7)'
    ctx.fillRect(0, h - 30, w, 30)
    ctx.fillStyle = '#00ff00'
    ctx.font = 'bold 14px monospace'
    ctx.textAlign = 'left'
    ctx.fillText(vrPhoneDebugMsg, 8, h - 10)
  }

  vrPhoneTexture.needsUpdate = true
  vrPhoneLastText = text
}

// コントローラーからレイキャストしてスマホ画面のUVを取得
function vrPhoneRaycast(worldPos, controllerQuat) {
  if (!vrPhoneScreen) return null
  const dir = new THREE.Vector3(0, 0, -1).applyQuaternion(controllerQuat)
  vrPhoneRaycaster.set(worldPos, dir)

  // レーザー表示
  if (vrPhoneLaser) {
    vrPhoneLaser.position.copy(worldPos)
    vrPhoneLaser.quaternion.copy(controllerQuat)
    vrPhoneLaser.visible = true
  }

  const hits = vrPhoneRaycaster.intersectObject(vrPhoneScreen, false)
  if (hits.length > 0 && hits[0].uv) {
    const uv = hits[0].uv
    // UV → Canvas座標に変換
    const cx = uv.x * 320
    const cy = (1 - uv.y) * 540
    return { cx, cy }
  }
  return null
}

function vrPhoneHitTest(cx, cy) {
  for (let i = 0; i < VR_PHONE_BUTTONS.length; i++) {
    const b = VR_PHONE_BUTTONS[i]
    if (cx >= b.x && cx <= b.x + b.w && cy >= b.y && cy <= b.y + b.h) {
      return i
    }
  }
  // デバッグ: 座標をスマホ画面に表示
  vrPhoneDebugMsg = `tap:(${cx.toFixed(0)},${cy.toFixed(0)})`
  return -1
}

function vrPhonePress(btnIdx) {
  if (btnIdx < 0) return
  const btn = VR_PHONE_BUTTONS[btnIdx]
  if (btn.type === 'key') {
    postText.value += btn.char
  } else if (btn.type === 'backspace') {
    postText.value = postText.value.slice(0, -1)
  } else if (btn.type === 'space') {
    postText.value += '　'
  } else if (btn.type === 'exit') {
    xrSession?.end()
  } else if (btn.type === 'clear') {
    postText.value = ''
  } else if (btn.type === 'mode') {
    const idx = VR_KB_MODE_ORDER.indexOf(vrKbMode)
    vrKbMode = VR_KB_MODE_ORDER[(idx + 1) % VR_KB_MODE_ORDER.length]
    buildVRKeyboardButtons()
  } else if (btn.type === 'demo-seed') {
    vrPhoneDebugMsg = 'PRESSED: demo-seed'
    seedDemoData().catch(e => { vrPhoneDebugMsg = 'ERR:' + e.message })
  } else if (btn.type === 'reset-data') {
    vrPhoneDebugMsg = 'PRESSED: reset'
    resetAllData().catch(e => { vrPhoneDebugMsg = 'ERR:' + e.message })
  } else if (btn.type === 'shark') {
    vrPhoneDebugMsg = 'PRESSED: shark'
    if (!shark) { spawnShark(); wsSend({ type: 'shark-spawn' }) }
  }
  updateVRPhoneScreen()
}

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

  clock = new THREE.Timer()

  // OrbitControls（カメラ操作）
  controls = new OrbitControls(camera, renderer.domElement)
  controls.enableDamping = true
  controls.dampingFactor = 0.05
  controls.maxPolarAngle = Math.PI / 2.0  // 水面ギリギリまで（水底が見える角度に）
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

  // 橋（VR投石用）
  buildBridge()

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

  // 水底（深くして石が積み重なるスペースを確保）
  const BOTTOM_Y = -8
  const bottomGeo = new THREE.PlaneGeometry(WATER_SIZE * 1.2, WATER_SIZE * 1.2)
  const bottomMat = new THREE.MeshPhongMaterial({ color: 0x0a1a3a, emissive: 0x050d1a })
  const bottomMesh = new THREE.Mesh(bottomGeo, bottomMat)
  bottomMesh.rotation.x = -Math.PI / 2
  bottomMesh.position.y = BOTTOM_Y
  scene.add(bottomMesh)

  // --- 物理ワールド初期化 ---
  physicsWorld = new CANNON.World({
    gravity: new CANNON.Vec3(0, -4, 0),  // 水中重力
  })
  physicsWorld.allowSleep = true

  // 共有マテリアル
  const stoneMat = new CANNON.Material('stone')
  const groundMat = new CANNON.Material('ground')
  physicsWorld.defaultContactMaterial = new CANNON.ContactMaterial(
    stoneMat, groundMat,
    { friction: 0.8, restitution: 0.1 }
  )
  // 石同士の衝突
  physicsWorld.addContactMaterial(new CANNON.ContactMaterial(
    stoneMat, stoneMat,
    { friction: 0.9, restitution: 0.15 }  // 高摩擦・わずかに弾く
  ))
  // 物理マテリアルをグローバルに保持
  physicsWorld._stoneMat = stoneMat

  // 水底の地面（物理）
  const groundBody = new CANNON.Body({
    type: CANNON.Body.STATIC,
    shape: new CANNON.Plane(),
    material: groundMat,
  })
  groundBody.quaternion.setFromEuler(-Math.PI / 2, 0, 0)
  groundBody.position.set(0, BOTTOM_Y, 0)
  physicsWorld.addBody(groundBody)

  // 水面の境界壁
  // cannon-es Plane の法線はデフォルト+Z方向。Y軸回転で内向きにする
  const wallSize = WATER_SIZE / 2
  const wallConfigs = [
    { pos: [wallSize, -4, 0], euler: [0, -Math.PI / 2, 0] },   // 右壁（法線-X: 内向き）
    { pos: [-wallSize, -4, 0], euler: [0, Math.PI / 2, 0] },   // 左壁（法線+X: 内向き）
    { pos: [0, -4, wallSize], euler: [0, Math.PI, 0] },         // 手前壁（法線-Z: 内向き）
    { pos: [0, -4, -wallSize], euler: [0, 0, 0] },              // 奥壁（法線+Z: 内向き）
  ]
  for (const w of wallConfigs) {
    const wallBody = new CANNON.Body({
      type: CANNON.Body.STATIC,
      shape: new CANNON.Plane(),
      material: groundMat,
    })
    wallBody.position.set(...w.pos)
    wallBody.quaternion.setFromEuler(...w.euler)
    physicsWorld.addBody(wallBody)
  }

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

  // 泡用パーティクルシステム
  const bubbleGeo = new THREE.BufferGeometry()
  const maxBubble = 200
  const bubblePositions = new Float32Array(maxBubble * 3)
  bubbleGeo.setAttribute('position', new THREE.BufferAttribute(bubblePositions, 3))
  const bubbleMat = new THREE.PointsMaterial({
    color: 0xffccaa,
    size: 0.35,
    transparent: true,
    opacity: 0.5,
    depthWrite: false,
  })
  bubbleParticles = new THREE.Points(bubbleGeo, bubbleMat)
  scene.add(bubbleParticles)

  // 蒸気用パーティクルシステム
  const steamGeo = new THREE.BufferGeometry()
  const maxSteam = 150
  const steamPositions = new Float32Array(maxSteam * 3)
  steamGeo.setAttribute('position', new THREE.BufferAttribute(steamPositions, 3))
  const steamMat = new THREE.PointsMaterial({
    color: 0xffffff,
    size: 0.5,
    transparent: true,
    opacity: 0.4,
    depthWrite: false,
  })
  steamParticles = new THREE.Points(steamGeo, steamMat)
  scene.add(steamParticles)

  // 魚を配置
  createFishes()

  // 初期の石を配置
  createStoneMeshes()
}

// --- 魚 ---
function createFishMesh(color) {
  const group = new THREE.Group()

  // 胴体（楕円球）
  const bodyGeo = new THREE.SphereGeometry(0.4, 8, 6)
  const positions = bodyGeo.attributes.position
  for (let i = 0; i < positions.count; i++) {
    const x = positions.getX(i)
    const y = positions.getY(i)
    const z = positions.getZ(i)
    positions.setXYZ(i, x * 2.0, y * 0.7, z * 0.8)
  }
  bodyGeo.computeVertexNormals()
  const bodyMat = new THREE.MeshPhongMaterial({
    color,
    emissive: new THREE.Color(color).multiplyScalar(0.2),
    specular: 0x444444,
    shininess: 30,
    transparent: true,
    opacity: 0.85,
  })
  const body = new THREE.Mesh(bodyGeo, bodyMat)
  group.add(body)

  // 尾びれ
  const tailGeo = new THREE.ConeGeometry(0.3, 0.5, 4)
  const tailMat = new THREE.MeshPhongMaterial({
    color,
    emissive: new THREE.Color(color).multiplyScalar(0.15),
    transparent: true,
    opacity: 0.8,
  })
  const tail = new THREE.Mesh(tailGeo, tailMat)
  tail.rotation.z = Math.PI / 2
  tail.position.x = -0.9
  tail.name = 'tail'
  group.add(tail)

  // 目（白丸＋黒丸）
  const eyeWhite = new THREE.Mesh(
    new THREE.SphereGeometry(0.08, 6, 6),
    new THREE.MeshBasicMaterial({ color: 0xffffff })
  )
  eyeWhite.position.set(0.55, 0.12, 0.25)
  group.add(eyeWhite)
  const eyeBlack = new THREE.Mesh(
    new THREE.SphereGeometry(0.05, 6, 6),
    new THREE.MeshBasicMaterial({ color: 0x000000 })
  )
  eyeBlack.position.set(0.6, 0.12, 0.28)
  group.add(eyeBlack)

  group.scale.set(0.8, 0.8, 0.8)
  return group
}

// style: 'cold' | 'hot' | 'shark' | デフォルト
function createSpeechBubble(text, style = 'default') {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  canvas.width = 640
  canvas.height = 100

  // スタイル別の色
  const styles = {
    cold:    { bg: 'rgba(30, 80, 180, 0.92)',  border: 'rgba(100, 180, 255, 0.8)', text: '#ffffff' },
    hot:     { bg: 'rgba(180, 30, 20, 0.92)',   border: 'rgba(255, 130, 100, 0.8)', text: '#ffffff' },
    shark:   { bg: 'rgba(20, 20, 30, 0.95)',    border: 'rgba(150, 160, 180, 0.7)', text: '#ffffff' },
    default: { bg: 'rgba(255, 255, 255, 0.92)', border: 'rgba(0, 0, 0, 0.3)',       text: '#333333' },
  }
  const s = styles[style] || styles.default

  // 背景
  ctx.fillStyle = s.bg
  ctx.roundRect(4, 4, canvas.width - 8, canvas.height - 8, 16)
  ctx.fill()
  ctx.strokeStyle = s.border
  ctx.lineWidth = 4
  ctx.roundRect(4, 4, canvas.width - 8, canvas.height - 8, 16)
  ctx.stroke()

  // テキスト（縁取り付き）
  ctx.font = 'bold 40px sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.lineWidth = 5
  ctx.strokeStyle = 'rgba(0, 0, 0, 0.5)'
  ctx.strokeText(text, canvas.width / 2, canvas.height / 2)
  ctx.fillStyle = s.text
  ctx.fillText(text, canvas.width / 2, canvas.height / 2)

  const texture = new THREE.CanvasTexture(canvas)
  const mat = new THREE.SpriteMaterial({
    map: texture,
    transparent: true,
    depthWrite: false,
    depthTest: false,
  })
  const sprite = new THREE.Sprite(mat)
  sprite.scale.set(5.5, 0.85, 1)
  sprite.renderOrder = 3
  // ポップアニメーション用
  sprite.userData.popTime = 0
  sprite.userData.targetScale = { x: 5.5, y: 0.85 }
  sprite.scale.set(0.01, 0.01, 1) // 最初は極小
  return sprite
}

// 吹き出しポップアニメーション（バウンド付きスケールイン）
function animateSpeechPop(sprite, dt) {
  if (!sprite.userData.targetScale) return
  sprite.userData.popTime += dt
  const t = Math.min(sprite.userData.popTime / 0.35, 1) // 0.35秒で完了
  // バウンスイージング
  let scale
  if (t < 0.6) {
    scale = (t / 0.6) * 1.15 // オーバーシュート
  } else if (t < 0.8) {
    scale = 1.15 - ((t - 0.6) / 0.2) * 0.15 // 戻る
  } else {
    scale = 1.0
  }
  const ts = sprite.userData.targetScale
  sprite.scale.set(ts.x * scale, ts.y * scale, 1)
}

function createFishes() {
  const half = WATER_SIZE / 2 * 0.8
  const fishConfigs = [
    { type: 'cold', color: 0x3388ff },
    { type: 'cold', color: 0x3388ff },
    { type: 'hot',  color: 0xff4433 },
    { type: 'hot',  color: 0xff4433 },
  ]

  for (const cfg of fishConfigs) {
    const mesh = createFishMesh(cfg.color)
    const x = (Math.random() - 0.5) * half * 2
    const z = (Math.random() - 0.5) * half * 2
    const y = -3 - Math.random() * 4
    mesh.position.set(x, y, z)
    scene.add(mesh)

    // ランダムな目標地点
    const targetX = (Math.random() - 0.5) * half * 2
    const targetZ = (Math.random() - 0.5) * half * 2

    fishes.push({
      mesh,
      type: cfg.type,
      speed: 1.5 + Math.random() * 1.5,
      targetX,
      targetZ,
      targetY: -2 - Math.random() * 5,
      speech: null,         // 吹き出しsprite
      speechTimer: 0,       // 吹き出し表示残り時間
      cooldown: 0,          // 石との接触クールダウン
      tailPhase: Math.random() * Math.PI * 2,
    })
  }
}

function updateFishes(dt, elapsed) {
  const half = WATER_SIZE / 2 * 0.8
  const INTERACT_DIST = 3.0
  const HEAT_CHANGE = 5

  for (const f of fishes) {
    if (f.eaten) continue
    // --- 移動 ---
    const dx = f.targetX - f.mesh.position.x
    const dy = f.targetY - f.mesh.position.y
    const dz = f.targetZ - f.mesh.position.z
    const dist = Math.sqrt(dx * dx + dy * dy + dz * dz)

    if (dist < 1.0) {
      // 新しい目標地点
      f.targetX = (Math.random() - 0.5) * half * 2
      f.targetZ = (Math.random() - 0.5) * half * 2
      f.targetY = -2 - Math.random() * 5
    }

    const moveSpeed = f.speed * dt
    if (dist > 0.01) {
      f.mesh.position.x += (dx / dist) * moveSpeed
      f.mesh.position.y += (dy / dist) * moveSpeed
      f.mesh.position.z += (dz / dist) * moveSpeed
    }

    // 進行方向を向く（メッシュの頭が+X方向なので90度オフセット）
    f.mesh.rotation.y = Math.atan2(dx, dz) - Math.PI / 2

    // 尾びれアニメーション
    f.tailPhase += dt * 8
    const tail = f.mesh.getObjectByName('tail')
    if (tail) {
      tail.rotation.y = Math.sin(f.tailPhase) * 0.4
    }

    // 上下に軽くゆらゆら
    f.mesh.position.y += Math.sin(elapsed * 2 + f.tailPhase) * 0.003

    // --- 石との接触判定 ---
    f.cooldown = Math.max(0, f.cooldown - dt)

    if (f.cooldown <= 0) {
      for (const s of stoneMeshes) {
        if (s.sinking) continue
        const sx = s.group.position.x - f.mesh.position.x
        const sy = s.group.position.y - f.mesh.position.y
        const sz = s.group.position.z - f.mesh.position.z
        const sDist = Math.sqrt(sx * sx + sy * sy + sz * sz)

        if (sDist < INTERACT_DIST) {
          if (f.type === 'cold') {
            s.post.heat = Math.max(0, (parseFloat(s.post.heat) || 0) - HEAT_CHANGE)
            showFishSpeech(f, 'う、魚w')
          } else {
            s.post.heat = Math.min(100, (parseFloat(s.post.heat) || 0) + HEAT_CHANGE)
            showFishSpeech(f, 'う、うおおおおおお！')
          }
          updateStoneMeshAppearance(s)
          f.cooldown = 4 + Math.random() * 3 // 4〜7秒クールダウン
          break
        }
      }
    }

    // --- 吹き出し更新 ---
    if (f.speechTimer > 0) {
      f.speechTimer -= dt
      if (f.speech) {
        f.speech.position.set(
          f.mesh.position.x,
          f.mesh.position.y + 1.5,
          f.mesh.position.z
        )
        // ポップアニメーション
        animateSpeechPop(f.speech, dt)
        // フェードアウト
        if (f.speechTimer < 0.5) {
          f.speech.material.opacity = f.speechTimer / 0.5
        }
      }
      if (f.speechTimer <= 0 && f.speech) {
        scene.remove(f.speech)
        f.speech.material.dispose()
        f.speech.material.map.dispose()
        f.speech = null
      }
    }
  }
}

function showFishSpeech(fish, text) {
  // 既存の吹き出しを削除
  if (fish.speech) {
    scene.remove(fish.speech)
    fish.speech.material.dispose()
    fish.speech.material.map.dispose()
  }
  const sprite = createSpeechBubble(text, fish.type === 'cold' ? 'cold' : 'hot')
  sprite.position.set(
    fish.mesh.position.x,
    fish.mesh.position.y + 1.2,
    fish.mesh.position.z
  )
  scene.add(sprite)
  fish.speech = sprite
  fish.speechTimer = 2.5
}

// --- サメ ---
function createSharkMesh() {
  const group = new THREE.Group()

  // 胴体
  const bodyGeo = new THREE.SphereGeometry(0.6, 8, 6)
  const pos = bodyGeo.attributes.position
  for (let i = 0; i < pos.count; i++) {
    const x = pos.getX(i)
    const y = pos.getY(i)
    const z = pos.getZ(i)
    // 前を尖らせ、後ろも少し伸ばす
    const xScale = x > 0 ? 3.5 : 2.5
    pos.setXYZ(i, x * xScale, y * 0.6, z * 0.7)
  }
  bodyGeo.computeVertexNormals()
  const bodyMat = new THREE.MeshPhongMaterial({
    color: 0x556677,
    emissive: 0x112233,
    specular: 0x333333,
    shininess: 20,
    transparent: true,
    opacity: 0.9,
  })
  group.add(new THREE.Mesh(bodyGeo, bodyMat))

  // 背びれ
  const finGeo = new THREE.ConeGeometry(0.25, 0.8, 4)
  const finMat = new THREE.MeshPhongMaterial({ color: 0x445566, emissive: 0x112233 })
  const dorsal = new THREE.Mesh(finGeo, finMat)
  dorsal.position.set(-0.2, 0.5, 0)
  dorsal.rotation.z = 0.15
  group.add(dorsal)

  // 尾びれ
  const tailGeo = new THREE.ConeGeometry(0.4, 0.8, 4)
  const tailMat = new THREE.MeshPhongMaterial({ color: 0x445566, emissive: 0x112233 })
  const tail = new THREE.Mesh(tailGeo, tailMat)
  tail.rotation.z = Math.PI / 2
  tail.position.x = -1.7
  tail.name = 'sharkTail'
  group.add(tail)

  // 目
  const eyeW = new THREE.Mesh(
    new THREE.SphereGeometry(0.08, 6, 6),
    new THREE.MeshBasicMaterial({ color: 0xffffff })
  )
  eyeW.position.set(1.4, 0.15, 0.35)
  group.add(eyeW)
  const eyeB = new THREE.Mesh(
    new THREE.SphereGeometry(0.05, 6, 6),
    new THREE.MeshBasicMaterial({ color: 0x000000 })
  )
  eyeB.position.set(1.48, 0.15, 0.38)
  group.add(eyeB)

  // 口（赤い線）
  const mouthGeo = new THREE.BoxGeometry(0.6, 0.03, 0.35)
  const mouthMat = new THREE.MeshBasicMaterial({ color: 0x991111 })
  const mouth = new THREE.Mesh(mouthGeo, mouthMat)
  mouth.position.set(1.5, -0.1, 0)
  group.add(mouth)

  group.scale.set(1.2, 1.2, 1.2)
  return group
}

// VR用3Dカットイン演出
function showVRCutin() {
  if (vrCutinSprite) { scene.remove(vrCutinSprite); vrCutinSprite = null }

  const canvas = document.createElement('canvas')
  canvas.width = 1024
  canvas.height = 256
  const ctx = canvas.getContext('2d')

  // 黒帯背景
  ctx.fillStyle = 'rgba(0, 0, 0, 0.85)'
  ctx.fillRect(0, 0, 1024, 256)

  // 青い横線
  const lineY = 128
  const grad = ctx.createLinearGradient(0, lineY, 1024, lineY)
  grad.addColorStop(0, 'transparent')
  grad.addColorStop(0.15, 'rgba(100, 160, 255, 0.8)')
  grad.addColorStop(0.35, 'transparent')
  grad.addColorStop(0.65, 'transparent')
  grad.addColorStop(0.85, 'rgba(100, 160, 255, 0.8)')
  grad.addColorStop(1, 'transparent')
  ctx.fillStyle = grad
  ctx.fillRect(0, lineY - 2, 1024, 4)

  // フラッシュ効果（白い縁）
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)'
  ctx.lineWidth = 4
  ctx.strokeRect(2, 2, 1020, 252)

  // サメ絵文字
  ctx.font = '100px sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('🦈', 400, 128)

  // テキスト
  ctx.font = 'bold 72px sans-serif'
  ctx.fillStyle = '#ff3333'
  ctx.strokeStyle = '#000000'
  ctx.lineWidth = 8
  ctx.strokeText('サメ 襲来', 620, 128)
  ctx.fillStyle = '#ffffff'
  ctx.fillText('サメ 襲来', 620, 128)
  // グロー
  ctx.shadowColor = 'rgba(255, 100, 100, 0.8)'
  ctx.shadowBlur = 20
  ctx.fillText('サメ 襲来', 620, 128)
  ctx.shadowBlur = 0

  const texture = new THREE.CanvasTexture(canvas)
  const mat = new THREE.SpriteMaterial({
    map: texture,
    transparent: true,
    depthTest: false,
    depthWrite: false,
  })
  vrCutinSprite = new THREE.Sprite(mat)

  // カメラの前に配置
  const camWorldPos = new THREE.Vector3()
  const camWorldDir = new THREE.Vector3()
  camera.getWorldPosition(camWorldPos)
  camera.getWorldDirection(camWorldDir)
  vrCutinSprite.position.copy(camWorldPos).add(camWorldDir.multiplyScalar(1.5))
  vrCutinSprite.scale.set(3, 0.75, 1)

  scene.add(vrCutinSprite)
  vrCutinTimer = 2.2
}

function updateVRCutin(dt) {
  if (!vrCutinSprite) return
  vrCutinTimer -= dt
  // カメラに追従
  const camWorldPos = new THREE.Vector3()
  const camWorldDir = new THREE.Vector3()
  camera.getWorldPosition(camWorldPos)
  camera.getWorldDirection(camWorldDir)
  vrCutinSprite.position.copy(camWorldPos).add(camWorldDir.multiplyScalar(1.5))
  // フェードアウト
  if (vrCutinTimer < 0.5) {
    vrCutinSprite.material.opacity = Math.max(vrCutinTimer / 0.5, 0)
  }
  if (vrCutinTimer <= 0) {
    scene.remove(vrCutinSprite)
    vrCutinSprite.material.dispose()
    vrCutinSprite.material.map.dispose()
    vrCutinSprite = null
  }
}

function spawnShark() {
  if (shark || sharkCutinActive.value) return
  // 赤い魚がいなければ出現しない
  const hotFishes = fishes.filter(f => f.type === 'hot' && !f.eaten)
  if (hotFishes.length === 0) return

  // カットイン演出を開始
  sharkCutinActive.value = true
  // VR用3Dカットインも表示
  if (renderer.xr.isPresenting) showVRCutin()

  // カットイン終了後にサメを実際に配置
  setTimeout(() => {
    sharkCutinActive.value = false

    const mesh = createSharkMesh()
    const half = WATER_SIZE / 2

    // 池の端から登場
    const side = Math.floor(Math.random() * 4)
    let startX, startZ
    if (side === 0)      { startX = -half - 5; startZ = (Math.random() - 0.5) * half }
    else if (side === 1) { startX = half + 5;  startZ = (Math.random() - 0.5) * half }
    else if (side === 2) { startX = (Math.random() - 0.5) * half; startZ = -half - 5 }
    else                 { startX = (Math.random() - 0.5) * half; startZ = half + 5 }

    mesh.position.set(startX, -3, startZ)
    scene.add(mesh)

    shark = {
      mesh,
      speed: 4,
      targetFish: null,
      speech: null,
      speechTimer: 0,
      tailPhase: 0,
      exitTimer: 0,
      exiting: false,
      exitX: startX,
      exitZ: startZ,
    }
  }, 2200) // カットイン演出の長さ
}

function updateShark(dt, elapsed) {
  // 一定確率でサメ出現
  if (!shark) {
    const hotFishes = fishes.filter(f => f.type === 'hot' && !f.eaten)
    // 自動出現は無効化（デモボタンから手動で呼び出す）
    // if (hotFishes.length > 0 && Math.random() < 0.002) {
    //   spawnShark()
    // }
    return
  }

  const s = shark

  // 尾びれアニメーション
  s.tailPhase += dt * 6
  const tail = s.mesh.getObjectByName('sharkTail')
  if (tail) tail.rotation.y = Math.sin(s.tailPhase) * 0.5

  // --- 退場中 ---
  if (s.exiting) {
    const dx = s.exitX - s.mesh.position.x
    const dz = s.exitZ - s.mesh.position.z
    const dist = Math.sqrt(dx * dx + dz * dz)
    if (dist > 0.1) {
      const spd = s.speed * 1.5 * dt
      s.mesh.position.x += (dx / dist) * spd
      s.mesh.position.z += (dz / dist) * spd
      s.mesh.rotation.y = Math.atan2(dx, dz) - Math.PI / 2
    } else {
      // 退場完了
      scene.remove(s.mesh)
      if (s.speech) {
        scene.remove(s.speech)
        s.speech.material.dispose()
        s.speech.material.map.dispose()
      }
      shark = null
      return
    }
    // 吹き出し更新
    updateSharkSpeech(dt)
    return
  }

  // --- ターゲット選択 ---
  const hotFishes = fishes.filter(f => f.type === 'hot' && !f.eaten)
  if (hotFishes.length === 0) {
    // 赤い魚がいなくなった → 退場
    s.exiting = true
    return
  }

  if (!s.targetFish || s.targetFish.eaten) {
    // 最も近い赤い魚をターゲット
    let closest = null
    let closestDist = Infinity
    for (const f of hotFishes) {
      const dx = f.mesh.position.x - s.mesh.position.x
      const dz = f.mesh.position.z - s.mesh.position.z
      const d = dx * dx + dz * dz
      if (d < closestDist) { closestDist = d; closest = f }
    }
    s.targetFish = closest
  }

  // --- 追跡 ---
  if (s.targetFish) {
    const tx = s.targetFish.mesh.position.x
    const ty = s.targetFish.mesh.position.y
    const tz = s.targetFish.mesh.position.z
    const dx = tx - s.mesh.position.x
    const dy = ty - s.mesh.position.y
    const dz = tz - s.mesh.position.z
    const dist = Math.sqrt(dx * dx + dy * dy + dz * dz)

    if (dist < 1.5) {
      // 食べる！
      s.targetFish.eaten = true
      scene.remove(s.targetFish.mesh)
      // 吹き出しも消す
      if (s.targetFish.speech) {
        scene.remove(s.targetFish.speech)
        s.targetFish.speech.material.dispose()
        s.targetFish.speech.material.map.dispose()
        s.targetFish.speech = null
      }
      showSharkSpeech('まじサメるわ〜')
      s.targetFish = null
    } else {
      const spd = s.speed * dt
      s.mesh.position.x += (dx / dist) * spd
      s.mesh.position.y += (dy / dist) * spd * 0.3
      s.mesh.position.z += (dz / dist) * spd
      s.mesh.rotation.y = Math.atan2(dx, dz) - Math.PI / 2
    }
  }

  // 吹き出し更新
  updateSharkSpeech(dt)
}

function showSharkSpeech(text) {
  if (!shark) return
  if (shark.speech) {
    scene.remove(shark.speech)
    shark.speech.material.dispose()
    shark.speech.material.map.dispose()
  }
  const sprite = createSpeechBubble(text, 'shark')
  sprite.position.set(
    shark.mesh.position.x,
    shark.mesh.position.y + 1.8,
    shark.mesh.position.z
  )
  scene.add(sprite)
  shark.speech = sprite
  shark.speechTimer = 2.5
}

function updateSharkSpeech(dt) {
  if (!shark || shark.speechTimer <= 0) return
  shark.speechTimer -= dt
  if (shark.speech) {
    shark.speech.position.set(
      shark.mesh.position.x,
      shark.mesh.position.y + 2.0,
      shark.mesh.position.z
    )
    animateSpeechPop(shark.speech, dt)
    if (shark.speechTimer < 0.5) {
      shark.speech.material.opacity = shark.speechTimer / 0.5
    }
  }
  if (shark.speechTimer <= 0 && shark.speech) {
    scene.remove(shark.speech)
    shark.speech.material.dispose()
    shark.speech.material.map.dispose()
    shark.speech = null
  }
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
  ground.position.y = -8
  scene.add(ground)
}

// --- 石の3Dメッシュ生成 ---
function createStoneMeshes() {
  // 既存の石を削除
  for (const s of stoneMeshes) {
    scene.remove(s.group)
    if (s.body) physicsWorld.removeBody(s.body)
  }
  stoneMeshes.length = 0

  // モック石は水底付近に配置
  posts.value.forEach((p) => {
    addStoneMesh(p, -7)
  })
}

function addStoneMesh(p, startY = 0) {
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

  const heatInit = Math.min((parseFloat(p.heat) || 0) / 100, 1)
  const stoneMat = new THREE.MeshPhongMaterial({
    color: new THREE.Color(0.45 + heatInit * 0.55, 0.45 * (1 - heatInit * 0.6), 0.45 * (1 - heatInit * 0.7)),
    emissive: new THREE.Color(heatInit * 0.4, heatInit * 0.05, 0),
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
  sprite.position.y = size + 1.5
  group.add(sprite)

  // 配置位置
  const wx = p.x * (WATER_SIZE / 2) * 0.8
  const wz = p.z * (WATER_SIZE / 2) * 0.8
  group.position.set(wx, startY, wz)
  scene.add(group)

  // 物理ボディ（扁平な石 → Cylinderで近似）
  const physicsRadius = size * 0.85
  const physicsHalfHeight = size * 0.45  // 見た目の扁平率に合わせる
  const physicsMass = 1 + (p.scale ?? 30) * 0.05  // scale大 → 重い
  const body = new CANNON.Body({
    mass: physicsMass,
    shape: new CANNON.Cylinder(physicsRadius, physicsRadius, physicsHalfHeight * 2, 8),
    position: new CANNON.Vec3(wx, startY, wz),
    linearDamping: startY > 0 ? 0 : 0.9,   // 空中は抵抗なし、水中は高抵抗
    angularDamping: startY > 0 ? 0 : 0.95,
    material: physicsWorld._stoneMat,
    sleepSpeedLimit: 0.3,  // 早めにスリープ
    sleepTimeLimit: 1,
  })
  // 上空から落下する場合は下方向の初速を付ける
  if (startY > 0) {
    body.velocity.set(0, -8, 0)
  }
  physicsWorld.addBody(body)

  // startYが水面より上なら着水時に波紋を出すフラグを立てる
  const needsRipple = startY > 0
  stoneMeshes.push({ group, post: p, body, size, needsRipple })
}

function createTextSprite(text, color) {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  canvas.width = 640
  canvas.height = 80

  // 背景
  ctx.fillStyle = 'rgba(0, 0, 0, 0.7)'
  ctx.roundRect(4, 4, canvas.width - 8, canvas.height - 8, 12)
  ctx.fill()
  ctx.strokeStyle = `rgba(${Math.round(color.r*255)}, ${Math.round(color.g*255)}, ${Math.round(color.b*255)}, 0.5)`
  ctx.lineWidth = 2
  ctx.roundRect(4, 4, canvas.width - 8, canvas.height - 8, 12)
  ctx.stroke()

  // テキスト（黒縁取り付き）
  ctx.font = 'bold 36px sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.lineWidth = 6
  ctx.strokeStyle = 'rgba(0, 0, 0, 0.9)'
  ctx.strokeText(text, canvas.width / 2, canvas.height / 2)
  ctx.fillStyle = '#ffffff'
  ctx.fillText(text, canvas.width / 2, canvas.height / 2)

  const texture = new THREE.CanvasTexture(canvas)
  const mat = new THREE.SpriteMaterial({
    map: texture,
    transparent: true,
    depthWrite: false,
    depthTest: false,
  })
  const sprite = new THREE.Sprite(mat)
  sprite.scale.set(5.5, 0.7, 1)
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

// --- 泡（熱い石からぶくぶく） ---
function spawnBubbles(dt) {
  const HEAT_THRESHOLD = 30
  for (const s of stoneMeshes) {
    if (!s.post || s.sinking) continue
    const heat = parseFloat(s.post.heat) || 0
    if (heat < HEAT_THRESHOLD) continue
    // 熱量に応じて泡の発生頻度を調整
    const intensity = Math.min((heat - HEAT_THRESHOLD) / 70, 1) // 0〜1
    if (Math.random() > intensity * 0.3) continue // 毎フレーム確率で発生
    const pos = s.group.position
    if (pos.y > 0) continue // 水面より上なら出さない
    const spread = s.size * 2.0
    bubbleData.push({
      x: pos.x + (Math.random() - 0.5) * spread,
      y: pos.y + (Math.random() - 0.3) * s.size * 0.9,
      z: pos.z + (Math.random() - 0.5) * spread,
      vy: 1.5 + Math.random() * 2.0,
      vx: (Math.random() - 0.5) * 0.3,
      vz: (Math.random() - 0.5) * 0.3,
      life: 8,
    })
  }
  // 上限を超えたら古いものから削除
  while (bubbleData.length > 200) bubbleData.shift()
}

function updateBubbles(dt) {
  spawnBubbles(dt)
  for (let i = bubbleData.length - 1; i >= 0; i--) {
    const b = bubbleData[i]
    b.x += b.vx * dt
    b.y += b.vy * dt
    b.z += b.vz * dt
    b.vx += (Math.random() - 0.5) * 0.5 * dt // ゆらゆら
    b.vz += (Math.random() - 0.5) * 0.5 * dt
    b.life -= dt * 0.1
    if (b.life <= 0 || b.y > 0.3) {
      bubbleData.splice(i, 1)
    }
  }
  const positions = bubbleParticles.geometry.attributes.position
  for (let i = 0; i < 200; i++) {
    if (i < bubbleData.length) {
      positions.setXYZ(i, bubbleData[i].x, bubbleData[i].y, bubbleData[i].z)
    } else {
      positions.setXYZ(i, 0, -100, 0)
    }
  }
  positions.needsUpdate = true
}

// --- 蒸気（熱い石の着水時） ---
function addSteam(worldX, worldZ, heat) {
  const intensity = Math.min(heat / 100, 1)
  const count = Math.floor(10 + intensity * 30)
  for (let i = 0; i < count; i++) {
    const spread = 0.5 + intensity * 1.5
    steamData.push({
      x: worldX + (Math.random() - 0.5) * spread,
      y: 0.1 + Math.random() * 0.3,
      z: worldZ + (Math.random() - 0.5) * spread,
      vx: (Math.random() - 0.5) * 0.8,
      vy: 2 + Math.random() * 3 * intensity,
      vz: (Math.random() - 0.5) * 0.8,
      life: 1.0 + intensity * 1.0,
    })
  }
  while (steamData.length > 150) steamData.shift()
}

function updateSteam(dt) {
  for (let i = steamData.length - 1; i >= 0; i--) {
    const s = steamData[i]
    s.x += s.vx * dt
    s.y += s.vy * dt
    s.z += s.vz * dt
    s.vx += (Math.random() - 0.5) * 1.0 * dt // ゆらゆら拡散
    s.vz += (Math.random() - 0.5) * 1.0 * dt
    s.vy *= 0.98 // 徐々に減速
    s.life -= dt * 0.8
    if (s.life <= 0) {
      steamData.splice(i, 1)
    }
  }
  const positions = steamParticles.geometry.attributes.position
  for (let i = 0; i < 150; i++) {
    if (i < steamData.length) {
      positions.setXYZ(i, steamData[i].x, steamData[i].y, steamData[i].z)
    } else {
      positions.setXYZ(i, 0, -100, 0)
    }
  }
  positions.needsUpdate = true
  // 残りパーティクルに応じて透明度を調整
  steamParticles.material.opacity = steamData.length > 0 ? 0.4 : 0
}

// --- 水柱 ---
function addWaterColumn(worldX, worldZ, scale) {
  const s = scale / 100
  const maxHeight = 2 + s * 10       // scale 100 → 高さ12
  const radius = 0.15 + s * 0.5      // scale 100 → 半径0.65
  const segments = 12

  const geo = new THREE.CylinderGeometry(radius * 0.3, radius, 0.1, segments, 1, true)
  const mat = new THREE.MeshPhongMaterial({
    color: 0x4499dd,
    emissive: 0x1a3a6a,
    specular: 0xaaddff,
    shininess: 90,
    transparent: true,
    opacity: 0.6,
    side: THREE.DoubleSide,
    depthWrite: false,
  })
  const mesh = new THREE.Mesh(geo, mat)
  mesh.position.set(worldX, 0, worldZ)
  mesh.renderOrder = 1
  scene.add(mesh)

  waterColumns.push({
    mesh,
    x: worldX,
    z: worldZ,
    maxHeight,
    currentHeight: 0.1,
    phase: 'rising',   // rising → holding → falling → done
    holdTime: 0.2 + s * 0.5,  // scale 100 → 0.7秒間維持
    holdTimer: 0,
    speed: 8 + s * 12, // scale大 → 速く立ち上がる
    radius,
  })
}

function updateWaterColumns(dt) {
  for (let i = waterColumns.length - 1; i >= 0; i--) {
    const col = waterColumns[i]

    if (col.phase === 'rising') {
      col.currentHeight += col.speed * dt
      if (col.currentHeight >= col.maxHeight) {
        col.currentHeight = col.maxHeight
        col.phase = 'holding'
      }
    } else if (col.phase === 'holding') {
      col.holdTimer += dt
      if (col.holdTimer >= col.holdTime) {
        col.phase = 'falling'
      }
    } else if (col.phase === 'falling') {
      col.currentHeight -= col.speed * 0.4 * dt
      col.mesh.material.opacity = Math.max(0, col.currentHeight / col.maxHeight * 0.6)
      if (col.currentHeight <= 0) {
        scene.remove(col.mesh)
        col.mesh.geometry.dispose()
        col.mesh.material.dispose()
        waterColumns.splice(i, 1)
        continue
      }
    }

    // ジオメトリを高さに合わせて再生成
    col.mesh.geometry.dispose()
    const topRadius = col.radius * 0.3 * (col.phase === 'rising' ? 1 : col.currentHeight / col.maxHeight)
    col.mesh.geometry = new THREE.CylinderGeometry(topRadius, col.radius, col.currentHeight, 12, 1, true)
    col.mesh.position.y = col.currentHeight / 2
  }
}

// --- 石の投げアニメーション ---
function throwStone(targetX, targetZ, mass, text, scale = 30, fromPos = null) {
  const startX = fromPos ? fromPos.x : -WATER_SIZE / 2 + 2
  const startY = fromPos ? fromPos.y : 8
  const startZ = fromPos ? fromPos.z : WATER_SIZE / 2 - 2

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

  isFlying.value = true
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
    addWaterColumn(f.targetX, f.targetZ, f.scale)
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
    addStoneMesh(newPost, 0)  // 水面から沈み始める

    flyingStone = null
    isFlying.value = false
    return
  }

  // ベジェ補間
  const t = f.progress
  const u = 1 - t
  const midX = (f.startX + f.targetX) / 2
  const midZ = (f.startZ + f.targetZ) / 2

  f.mesh.position.x = u * u * f.startX + 2 * u * t * midX + t * t * f.targetX
  f.mesh.position.z = u * u * f.startZ + 2 * u * t * midZ + t * t * f.targetZ
  f.mesh.position.y = u * u * f.startY + 2 * u * t * f.peakY + t * t * 0
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

// --- 石の物理同期 + 消滅処理 ---
function updateStones(elapsed, dt) {
  // 物理ワールドを進める
  if (physicsWorld) {
    physicsWorld.step(1 / 60, dt, 5)
  }

  for (let i = stoneMeshes.length - 1; i >= 0; i--) {
    const s = stoneMeshes[i]

    if (s.sinking) {
      // 消滅アニメーション（フェードアウト）
      s.group.children.forEach(child => {
        if (child.material) {
          child.material.opacity = Math.max(0, (child.material.opacity || 1) - 0.008)
        }
      })
      if (s.group.children[0]?.material?.opacity <= 0) {
        scene.remove(s.group)
        if (s.body) physicsWorld.removeBody(s.body)
        stoneMeshes.splice(i, 1)
      }
      continue
    }

    // 水平方向の速度制限（吹っ飛び防止、沈む方向は制限しない）
    if (s.body) {
      const v = s.body.velocity
      const hSpeed = Math.sqrt(v.x * v.x + v.z * v.z)
      if (hSpeed > 2) {
        const scale = 2 / hSpeed
        v.x *= scale
        v.z *= scale
      }
      // 物理ボディの位置・回転をThree.jsメッシュに同期
      s.group.position.copy(s.body.position)
      s.group.quaternion.copy(s.body.quaternion)

      // 水面(y=0)を通過したら波紋を発生＋水中抵抗を有効化
      if (s.needsRipple && s.body.position.y <= 0) {
        addRipple3D(s.body.position.x, s.body.position.z, s.post.mass, s.post.scale ?? 30)
        s.body.linearDamping = 0.9
        s.body.angularDamping = 0.95
        s.needsRipple = false
        // 熱い石なら蒸気＋ジュー音
        const heat = parseFloat(s.post.heat) || 0
        if (heat >= 30) {
          addSteam(s.body.position.x, s.body.position.z, heat)
          playSizzleSound(heat)
        }
      }
    }

    // 風化視覚更新（脈動に elapsed を渡す）
    updateStoneMeshAppearance(s, elapsed)
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
  clock.update()
  const dt = Math.min(clock.getDelta(), 0.05)
  const elapsed = clock.getElapsed()

  updateWater(elapsed)
  updateRipples(dt)
  updateSplashes(dt)
  updateBubbles(dt)
  updateSteam(dt)
  updateWaterColumns(dt)
  try { updateFishes(dt, elapsed) } catch (e) { console.error('updateFishes error:', e) }
  try { updateShark(dt, elapsed) } catch (e) { console.error('updateShark error:', e) }
  updateStones(elapsed, dt)
  updateFlyingStone(dt)

  // 熱量の自然減衰（2秒ごと）
  decayTimer += dt
  if (decayTimer > 2) {
    decayTimer = 0
    try { decayHeat() } catch (e) { console.error('decayHeat error:', e) }
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

// --- WebSocket（デバイス間リアルタイム同期） ---
let ws = null
function connectWS() {
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:'
  ws = new WebSocket(`${proto}//${location.host}/ws`)
  ws.onopen = () => console.log('WS connected')
  ws.onclose = () => {
    console.log('WS disconnected, reconnecting...')
    setTimeout(connectWS, 2000)
  }
  ws.onerror = (e) => console.error('WS error:', e)
  ws.onmessage = (event) => {
    try {
      const data = JSON.parse(event.data)
      handleWSMessage(data)
    } catch (e) {
      console.error('WS message parse error:', e)
    }
  }
}

function wsSend(data) {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify(data))
  }
}

async function handleWSMessage(data) {
  if (data.type === 'posts-added') {
    // 他デバイスが石を追加した → データ再読み込みして差分追加
    const existingIds = new Set(posts.value.map(p => p.id))
    await loadPosts()
    posts.value.forEach(p => {
      if (!existingIds.has(p.id)) {
        addStoneMesh(p, 5)
      }
    })
    await loadWinds()
  } else if (data.type === 'posts-reset') {
    // 他デバイスがリセットした → 全石削除
    stoneMeshes.forEach(s => {
      scene.remove(s.group)
      if (s.body) physicsWorld.removeBody(s.body)
    })
    stoneMeshes.length = 0
    posts.value = []
    winds.value = []
    selectedPost.value = null
  } else if (data.type === 'shark-spawn') {
    // 他デバイスがサメを召喚した
    if (!shark && !sharkCutinActive.value) spawnShark()
  } else if (data.type === 'post-liked') {
    // いいね同期
    const post = posts.value.find(p => p.id === data.postId)
    if (post) {
      post.heat = data.heat
      post.likes = (post.likes || 0) + 1
      const sm = stoneMeshes.find(s => s.post?.id === data.postId)
      if (sm) { sm.post = post; updateStoneMeshAppearance(sm) }
    }
  } else if (data.type === 'post-submitted') {
    // 他デバイスが投稿した → データ再読み込み
    const existingIds = new Set(posts.value.map(p => p.id))
    await loadPosts()
    posts.value.forEach(p => {
      if (!existingIds.has(p.id)) {
        addStoneMesh(p, 5)
      }
    })
  }
}

// --- ライフサイクル ---
onMounted(async () => {
  await loadPosts()
  loadWinds()
  initThree()
  animate()
  checkApiStatus()
  checkXRSupport()
  connectWS()
  window.addEventListener('resize', onResize)
  // ローディング画面をフェードアウト
  const loader = document.getElementById('initial-loading')
  if (loader) {
    loader.classList.add('fade-out')
    setTimeout(() => loader.remove(), 600)
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', onResize)
  if (animationId) cancelAnimationFrame(animationId)
  if (renderer) renderer.dispose()
  if (ws) ws.close()
})

// --- 投稿 ---
// --- 投稿 ---
// Logic APIのURL
const logicApiUrl = import.meta.env.VITE_API_LOGIC_URL || '/api/logic'

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
        lastGravityCoef.value = data.gravity_coefficient ?? 1
      }
    } catch {
      console.warn('Gravity API未接続。フォールバック値を使用')
      lastGravityCoef.value = 1
    }

    lastMass.value = mass
    lastGravity.value = gravity
    lastScale.value = subjectScale

    // ランダムな着水位置（水面中央付近）
    const targetX = (Math.random() - 0.5) * WATER_SIZE * 0.6
    const targetZ = (Math.random() - 0.5) * WATER_SIZE * 0.6

    // Logic APIで投稿作成
    try {
      const createRes = await fetch(`${logicApiUrl}/posts`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          text: postText.value,
          x: targetX,
          y: targetZ, // 3Dのzをyとして使用
          mass: mass
        })
      })

      if (createRes.ok) {
        const createData = await createRes.json()
        posts.value.push(createData.post)
      } else {
        const errorData = await createRes.json().catch(() => ({}))
        throw new Error(errorData.error || '投稿作成に失敗しました')
      }
    } catch (logicError) {
      console.error('Logic APIエラー:', logicError)
      alert(`投稿作成に失敗しました: ${logicError.message}`)
    }

    // 3D表示は常に行う
    throwStone(targetX, targetZ, mass, postText.value, subjectScale)
    wsSend({ type: 'post-submitted' })
    postText.value = ''
  } catch (error) {
    console.error('投稿送信エラー:', error)
    alert('投稿送信中にエラーが発生しました')
  } finally {
    isSubmitting.value = false
  }
}

// --- WebXR (Pico コントローラーで投石) ---
const xrSupported = ref(false)
const xrActive = ref(false)
let xrSession = null
let xrRefSpace = null
let xrGripPose = null
let xrPrevPos = null
let xrCameraRig = null  // カメラリグ（VR俯瞰視点用）
let xrRemotePollTimer = null  // リモコンポーリング用
let xrGrabbing = false
let xrGrabFrames = 0
let xrVelocity = new THREE.Vector3()
let xrPeakSpeed = 0
let xrPeakVelocity = new THREE.Vector3()
// 直近N フレームの手の位置を記録（移動距離ベースの投げ判定用）
const XR_POS_HISTORY_SIZE = 8
let xrPosHistory = []  // { pos: Vector3, time: number }
let xrStoneMesh = null  // 手に持っている石のメッシュ
let xrHandMesh = null   // メイン手のモデル
let xrSubHandMesh = null // もう片方の手のモデル
let xrTriggerWasPressed = false  // トリガー前フレーム状態（スマホ操作用）
const VR_PHRASES = [
  'VRから一石！',
  '波紋を広げよ',
  '議論に参戦',
  '異議あり！',
  'これは譲れない',
  '世界は変わる',
  '人類の未来を考える',
]

function createHandMesh(side = 'left') {
  const hand = new THREE.Group()
  const skinColor = 0xdeb896
  const skinMat = new THREE.MeshPhongMaterial({ color: skinColor, flatShading: true })
  const mirror = side === 'right' ? -1 : 1

  // 手のひら
  const palmGeo = new THREE.BoxGeometry(0.08, 0.03, 0.10)
  const palm = new THREE.Mesh(palmGeo, skinMat)
  hand.add(palm)

  // 指5本（ピボット付きで曲げられる）
  const fingerConfigs = [
    { x: -0.03, z: -0.07, len: 0.06, rotY: 0, name: 'finger0' },      // 小指
    { x: -0.015, z: -0.075, len: 0.07, rotY: 0, name: 'finger1' },     // 薬指
    { x: 0, z: -0.08, len: 0.075, rotY: 0, name: 'finger2' },           // 中指
    { x: 0.015, z: -0.075, len: 0.07, rotY: 0, name: 'finger3' },       // 人差し指
    { x: 0.04, z: -0.03, len: 0.045, rotY: 0.5, name: 'thumb' },        // 親指
  ]
  for (const f of fingerConfigs) {
    const pivot = new THREE.Group()
    pivot.position.set(f.x * mirror, 0, f.z)
    pivot.rotation.y = f.rotY * mirror
    pivot.name = f.name

    const fingerGeo = new THREE.BoxGeometry(0.015, 0.015, f.len)
    const finger = new THREE.Mesh(fingerGeo, skinMat)
    finger.position.set(0, 0, -f.len / 2)
    pivot.add(finger)
    hand.add(pivot)
  }

  hand.userData.side = side
  return hand
}

// グリップ値(0〜1)に応じて指を曲げる
function setHandGrip(handMesh, grip) {
  if (!handMesh) return
  const curl = -grip * Math.PI * 0.45 // 最大約80度（内側に曲げる）
  for (const name of ['finger0', 'finger1', 'finger2', 'finger3']) {
    const pivot = handMesh.getObjectByName(name)
    if (pivot) pivot.rotation.x = curl
  }
  // 親指は内側に曲げる
  const thumb = handMesh.getObjectByName('thumb')
  if (thumb) thumb.rotation.x = -grip * Math.PI * 0.3
}

async function checkXRSupport() {
  if (navigator.xr) {
    xrSupported.value = await navigator.xr.isSessionSupported('immersive-vr').catch(() => false)
  }
}

async function toggleXR() {
  if (xrActive.value) {
    xrSession?.end()
    return
  }
  try {
    xrSession = await navigator.xr.requestSession('immersive-vr', {
      optionalFeatures: ['local-floor', 'hand-tracking'],
    })
    xrActive.value = true
    xrRefSpace = await xrSession.requestReferenceSpace('local-floor')

    // カメラリグを橋の上に配置（橋から池を見下ろして投げる）
    xrCameraRig = new THREE.Group()
    xrCameraRig.position.set(0, BRIDGE_Y + 0.2, BRIDGE_Z)
    scene.add(xrCameraRig)
    xrCameraRig.add(camera)

    // 手モデルを生成（両手）
    xrHandMesh = createHandMesh()
    scene.add(xrHandMesh)
    xrSubHandMesh = createHandMesh('right')
    scene.add(xrSubHandMesh)

    // VR内スマホパネルを生成
    buildVRPhone()

    renderer.xr.enabled = true
    renderer.xr.setSession(xrSession)

    xrSession.addEventListener('end', () => {
      xrActive.value = false
      xrSession = null
      renderer.xr.enabled = false
      if (xrRemotePollTimer) { clearInterval(xrRemotePollTimer); xrRemotePollTimer = null }
      // カメラリグを解除してカメラをシーンに戻す
      if (xrCameraRig) {
        xrCameraRig.remove(camera)
        scene.remove(xrCameraRig)
        scene.add(camera)
        camera.position.set(0, 18, 22)
        camera.lookAt(0, 0, 0)
        xrCameraRig = null
      }
      // 手持ち石・手モデルを消す
      if (xrStoneMesh) { scene.remove(xrStoneMesh); xrStoneMesh = null }
      if (xrHandMesh) { scene.remove(xrHandMesh); xrHandMesh = null }
      if (xrSubHandMesh) { scene.remove(xrSubHandMesh); xrSubHandMesh = null }
      if (vrPhoneMesh) { scene.remove(vrPhoneMesh); vrPhoneMesh = null; vrPhoneScreen = null }
      if (vrPhoneLaser) { scene.remove(vrPhoneLaser); vrPhoneLaser = null }
    })

    renderer.setAnimationLoop(xrAnimateLoop)
  } catch (e) {
    console.warn('WebXR開始失敗:', e)
  }
}

function xrAnimateLoop(timestamp, frame) {
  if (!frame) return
  clock.update()
  const dt = Math.min(clock.getDelta(), 0.05)
  const elapsed = clock.getElapsed()

  // 通常の更新処理
  updateWater(elapsed)
  updateRipples(dt)
  updateSplashes(dt)
  updateBubbles(dt)
  updateSteam(dt)
  updateWaterColumns(dt)
  try { updateFishes(dt, elapsed) } catch (e) { console.error('updateFishes error:', e) }
  try { updateShark(dt, elapsed) } catch (e) { console.error('updateShark error:', e) }
  updateVRCutin(dt)
  updateStones(elapsed, dt)
  updateFlyingStone(dt)
  decayTimer += dt
  if (decayTimer > 2) { decayTimer = 0; try { decayHeat() } catch (e) { console.error('decayHeat error:', e) } }

  // コントローラー処理
  const session = frame.session
  // 全コントローラーからgripSpace付きを収集（左手=0, 右手=1）
  const controllers = []
  for (const source of session.inputSources) {
    if (source.gripSpace) controllers.push(source)
  }
  controllers.sort((a, b) => (a.handedness === 'left' ? -1 : 1))

  // サブハンド（2本目）の位置更新
  if (controllers.length >= 2 && xrSubHandMesh) {
    const subPose = frame.getPose(controllers[1].gripSpace, xrRefSpace)
    if (subPose) {
      const rigOffset = xrCameraRig ? xrCameraRig.position : new THREE.Vector3()
      const subPos = new THREE.Vector3(
        subPose.transform.position.x,
        subPose.transform.position.y,
        subPose.transform.position.z
      ).add(rigOffset)
      const sq = subPose.transform.orientation
      xrSubHandMesh.position.copy(subPos)
      xrSubHandMesh.quaternion.set(sq.x, sq.y, sq.z, sq.w)
      // サブハンドの握り反映
      const subGamepad = controllers[1].gamepad
      const subGrip = subGamepad ? Math.max(subGamepad.buttons[0]?.value ?? 0, subGamepad.buttons[1]?.value ?? 0) : 0
      setHandGrip(xrSubHandMesh, subGrip)
    }
  } else if (xrSubHandMesh) {
    xrSubHandMesh.position.set(0, -100, 0) // 隠す
  }

  // メインコントローラー（1本目）で操作
  const controllerSource = controllers[0] || null
  if (controllerSource) {
    const pose = frame.getPose(controllerSource.gripSpace, xrRefSpace)
    if (pose) {
      // XR空間でのコントローラー位置（リグオフセットなし＝速度計算用）
      const xrPos = new THREE.Vector3(
        pose.transform.position.x,
        pose.transform.position.y,
        pose.transform.position.z
      )

      // ワールド座標（リグの位置を加算）
      const rigOffset = xrCameraRig ? xrCameraRig.position : new THREE.Vector3()
      const worldPos = xrPos.clone().add(rigOffset)

      // 速度はXR空間の差分で計算（リグは動かないので同じ）
      if (xrPrevPos) {
        xrVelocity.subVectors(xrPos, xrPrevPos).divideScalar(dt || 1 / 72)
      }
      xrPrevPos = xrPos.clone()

      // 手モデルをコントローラー位置に追従
      const q = pose.transform.orientation
      const controllerQuat = new THREE.Quaternion(q.x, q.y, q.z, q.w)
      if (xrHandMesh) {
        xrHandMesh.position.copy(worldPos)
        xrHandMesh.quaternion.copy(controllerQuat)
      }

      const gamepad = controllerSource.gamepad

      // メインハンドの握り反映
      const mainGrip = gamepad ? (gamepad.buttons[1]?.value ?? 0) : 0
      setHandGrip(xrHandMesh, Math.max(mainGrip, gamepad ? (gamepad.buttons[0]?.value ?? 0) : 0))

      // --- スマホ操作（トリガー = buttons[0]でポイント＆クリック） ---
      const triggerValue = gamepad ? (gamepad.buttons[0]?.value ?? 0) : 0
      const triggerPressed = triggerValue > 0.5

      // レイキャストでスマホ画面をホバー
      const phoneHit = vrPhoneRaycast(worldPos, controllerQuat)
      if (phoneHit) {
        const hitIdx = vrPhoneHitTest(phoneHit.cx, phoneHit.cy)
        if (hitIdx !== vrPhoneHoverIdx) {
          vrPhoneHoverIdx = hitIdx
          updateVRPhoneScreen()
        }
        // トリガー押した瞬間にボタン実行
        if (triggerPressed && !xrTriggerWasPressed) {
          if (hitIdx >= 0) {
            vrPhonePress(hitIdx)
          } else {
            vrPhoneDebugMsg = `miss:(${phoneHit.cx.toFixed(0)},${phoneHit.cy.toFixed(0)})`
          }
          updateVRPhoneScreen()
        }
      } else {
        if (vrPhoneHoverIdx !== -1) {
          vrPhoneHoverIdx = -1
          updateVRPhoneScreen()
        }
        if (vrPhoneLaser) vrPhoneLaser.visible = false
      }
      xrTriggerWasPressed = triggerPressed

      // --- スティックで体の向きを回転（axes[2]が左右） ---
      if (gamepad && gamepad.axes.length >= 4 && xrCameraRig) {
        const stickX = gamepad.axes[2] // 左右
        if (Math.abs(stickX) > 0.5) {
          xrCameraRig.rotation.y -= stickX * dt * 2.0 // スナップ風の回転
        }
      }

      // --- スマホ掴み移動（グリップ + スマホにレイが当たっている時） ---
      const gripValue = gamepad ? (gamepad.buttons[1]?.value ?? 0) : 0
      const gripPressed = gripValue > 0.5

      if (gripPressed && !vrPhoneGrabbing && !xrGrabbing && phoneHit && vrPhoneMesh) {
        // スマホを掴み始める
        vrPhoneGrabbing = true
        vrPhoneGrabOffset.copy(vrPhoneMesh.position).sub(worldPos)
      }
      if (vrPhoneGrabbing) {
        if (gripPressed && vrPhoneMesh) {
          // 掴み中: コントローラーに追従
          vrPhoneMesh.position.copy(worldPos).add(vrPhoneGrabOffset)
          // ユーザーの方を向く
          const camWorldPos = new THREE.Vector3()
          camera.getWorldPosition(camWorldPos)
          vrPhoneMesh.lookAt(camWorldPos)
        } else {
          // グリップ離した: 掴み終了
          vrPhoneGrabbing = false
        }
      }

      // --- 石の掴み＆投げ（グリップ = buttons[1]、スマホ掴み中でない時） ---
      if (gripPressed && !xrGrabbing && !vrPhoneGrabbing && !isFlying.value && postText.value.trim()) {
        // 掴み開始 → テキストがある時だけ手元に石を生成
        xrGrabbing = true
        xrGrabFrames = 0
        xrPeakSpeed = 0
        xrPeakVelocity.set(0, 0, 0)
        xrPosHistory = []

        if (!xrStoneMesh) {
          const stoneGeo = new THREE.SphereGeometry(0.05, 8, 6)
          const verts = stoneGeo.attributes.position
          for (let i = 0; i < verts.count; i++) {
            verts.setY(i, verts.getY(i) * 0.5)
            const n = 0.7 + 0.6 * Math.abs(Math.sin(i * 3.7) * Math.cos(i * 2.3))
            verts.setX(i, verts.getX(i) * n)
            verts.setZ(i, verts.getZ(i) * n)
          }
          stoneGeo.computeVertexNormals()

          // MeshBasicMaterialならライティング不要で確実に見える
          const stoneMat = new THREE.MeshBasicMaterial({ color: 0x66bbff })
          xrStoneMesh = new THREE.Mesh(stoneGeo, stoneMat)

          // 光る球体（グロー）
          const glowGeo = new THREE.SphereGeometry(0.08, 12, 8)
          const glowMat = new THREE.MeshBasicMaterial({
            color: 0x4488ff,
            transparent: true,
            opacity: 0.3,
          })
          const glow = new THREE.Mesh(glowGeo, glowMat)
          glow.name = 'glow'
          xrStoneMesh.add(glow)

          scene.add(xrStoneMesh)
        }
      } else if (gripPressed && xrGrabbing) {
        // 掴み中 → 石を手のワールド座標に追従
        xrGrabFrames++
        if (xrStoneMesh) {
          xrStoneMesh.position.copy(worldPos)
          // グローを脈動
          const glow = xrStoneMesh.getObjectByName('glow')
          if (glow) {
            const pulse = 1 + Math.sin(elapsed * 8) * 0.3
            glow.scale.set(pulse, pulse, pulse)
          }
        }
        // 位置履歴を記録（移動距離ベースの投げ判定用）
        xrPosHistory.push({ pos: worldPos.clone(), time: elapsed })
        if (xrPosHistory.length > XR_POS_HISTORY_SIZE) xrPosHistory.shift()
      } else if (!gripPressed && xrGrabbing) {
        // 離した → 投石！
        xrGrabbing = false
        const releaseWorldPos = xrStoneMesh ? xrStoneMesh.position.clone() : worldPos.clone()

        // 手持ち石を消す
        if (xrStoneMesh) {
          scene.remove(xrStoneMesh)
          xrStoneMesh = null
        }

        if (xrGrabFrames >= 2 && xrPosHistory.length >= 2) {
          // 直近の位置履歴から、手の実移動距離と方向を計算
          const hist = xrPosHistory
          const first = hist[0]
          const last = hist[hist.length - 1]
          const dt = last.time - first.time

          // 始点→終点のベクトル（投げの方向）をカメラリグの回転に合わせる
          const displacement = new THREE.Vector3().subVectors(last.pos, first.pos)
          if (xrCameraRig) {
            displacement.applyAxisAngle(new THREE.Vector3(0, 1, 0), xrCameraRig.rotation.y)
          }
          // 実際の経路長（各フレーム間距離の合計）
          let pathLength = 0
          for (let i = 1; i < hist.length; i++) {
            pathLength += hist[i].pos.distanceTo(hist[i - 1].pos)
          }

          // 手の移動速度 = 経路長 / 時間（ノイズに強い）
          const throwSpeed = dt > 0 ? pathLength / dt : 0

          // 最低速度閾値（手ブレ: ~0.02m/frame → ~1.4m/s at 72Hz）
          if (throwSpeed > 0.8) {
            // 方向は始点→終点ベクトルから（経路長ではなく直線方向）
            const LR_AMPLIFY = 2.0
            const flatDisp = new THREE.Vector2(displacement.x * LR_AMPLIFY, displacement.z)
            const flatLen = flatDisp.length()

            let dirX, dirZ
            if (flatLen > 0.01) {
              dirX = flatDisp.x / flatLen
              dirZ = flatDisp.y / flatLen
            } else {
              dirX = 0
              dirZ = -1
            }

            // 指数カーブ: 遠くに飛ばすには指数的に力が必要
            // throwSpeed 0.8→足元, 2.0→近い, 4.0→中距離, 6.0+→遠距離
            const MIN_DIST = 0.5
            const MAX_DIST = WATER_SIZE * 0.9  // 橋から池の反対端まで
            const normalized = Math.min((throwSpeed - 0.8) / 6.0, 1)
            const expDist = Math.pow(normalized, 3)  // 三乗カーブ
            const distance = MIN_DIST + (MAX_DIST - MIN_DIST) * expDist

            // プレイヤー位置（橋の上）からの相対座標で着地点を計算
            const playerX = 0
            const playerZ = BRIDGE_Z
            const halfW = WATER_SIZE / 2
            const targetX = THREE.MathUtils.clamp(playerX + dirX * distance, -halfW, halfW)
            const targetZ = THREE.MathUtils.clamp(playerZ + dirZ * distance, -halfW, halfW)

            xrSubmitPost(targetX, targetZ, releaseWorldPos)
          }
        }
      }
    }
  }

  // VR内スマホ画面を更新（テキスト変更時のみ）
  if (vrPhoneCtx && postText.value !== vrPhoneLastText) {
    updateVRPhoneScreen()
  }

  renderer.render(scene, camera)
}

function xrSubmitPost(targetX, targetZ, fromPos = null) {
  if (isFlying.value) return
  // テキスト入力があればそれを使う、なければVR定型文
  const text = postText.value.trim()
  if (!text) return

  // 即座にフォールバック値で投げる（API待ちなし）
  const fallbackMass = text.length * 0.1 + (text.match(/[！!？?]/g) || []).length * 20
  const fallbackScale = 30
  throwStone(targetX, targetZ, fallbackMass, text, fallbackScale, fromPos)
  postText.value = ''
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
  flex-wrap: wrap;
}

.list-weathering {
  color: #aaaaaa;
}

.list-weathering.danger {
  color: #ff6b6b;
  animation: blink 1s ease-in-out infinite;
}

@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
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

.vr-btn {
  margin-top: 8px;
  padding: 6px 14px;
  background: rgba(120, 80, 255, 0.3);
  border: 1px solid rgba(120, 80, 255, 0.5);
  color: #c8b8ff;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.85rem;
  transition: background 0.2s;
}
.vr-btn:hover {
  background: rgba(120, 80, 255, 0.5);
}

.xr-hint {
  margin-top: 4px;
  font-size: 0.75rem;
  color: rgba(200, 184, 255, 0.7);
}
.demo-seed-btn {
  margin-top: 10px;
  padding: 6px 14px;
  background: rgba(255, 160, 50, 0.25);
  border: 1px solid rgba(255, 160, 50, 0.5);
  color: #ffd6a0;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.8rem;
  transition: background 0.2s;
  width: 100%;
}
.demo-seed-btn:hover {
  background: rgba(255, 160, 50, 0.45);
}
.demo-seed-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.reset-data-btn {
  margin-top: 6px;
  padding: 6px 14px;
  background: rgba(255, 70, 70, 0.2);
  border: 1px solid rgba(255, 70, 70, 0.4);
  color: #ffaaaa;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.8rem;
  transition: background 0.2s;
  width: 100%;
}
.reset-data-btn:hover {
  background: rgba(255, 70, 70, 0.4);
}
.reset-data-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.shark-btn {
  margin-top: 6px;
  padding: 6px 14px;
  background: rgba(100, 100, 255, 0.2);
  border: 1px solid rgba(100, 100, 255, 0.4);
  color: #aaccff;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.8rem;
  transition: background 0.2s;
  width: 100%;
}
.shark-btn:hover {
  background: rgba(100, 100, 255, 0.4);
}
.shark-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* --- VRリモコン（スマホ用） --- */
.remote-container {
  width: 100%;
  min-height: 100vh;
  background: linear-gradient(135deg, #0a1628 0%, #1a2a4a 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40px 20px;
  color: white;
  box-sizing: border-box;
}
.remote-title {
  font-size: 1.8rem;
  margin: 0 0 4px;
  background: linear-gradient(90deg, #88bbff, #aaddff);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}
.remote-sub {
  font-size: 0.9rem;
  color: rgba(255,255,255,0.5);
  margin: 0 0 24px;
}
.remote-textarea {
  width: 100%;
  max-width: 400px;
  min-height: 120px;
  padding: 16px;
  border-radius: 12px;
  border: 2px solid rgba(100, 180, 255, 0.3);
  background: rgba(255,255,255,0.08);
  color: white;
  font-size: 1.1rem;
  resize: vertical;
  outline: none;
  transition: border-color 0.3s;
}
.remote-textarea:focus {
  border-color: rgba(100, 180, 255, 0.7);
}
.remote-textarea::placeholder {
  color: rgba(255,255,255,0.3);
}
.remote-status {
  margin: 16px 0;
  font-size: 0.95rem;
}
.status-ok {
  color: #66ff88;
}
.status-wait {
  color: rgba(255,255,255,0.4);
}
.remote-exit-btn {
  margin-top: 32px;
  padding: 14px 40px;
  background: rgba(255, 80, 80, 0.3);
  border: 1px solid rgba(255, 80, 80, 0.5);
  color: #ffaaaa;
  border-radius: 10px;
  font-size: 1.1rem;
  cursor: pointer;
  transition: background 0.2s;
}
.remote-exit-btn:hover {
  background: rgba(255, 80, 80, 0.5);
}

/* --- AI要約（風）のアニメーション設定 --- */
.wind-overlay {
  position: absolute;
  top: 15%;
  width: 100%;
  pointer-events: none;
  z-index: 40;
  overflow: hidden;
  height: 120px;
}

.wind-message {
  position: absolute;
  background: rgba(255, 255, 255, 0.12);
  backdrop-filter: blur(8px);
  padding: 10px 24px;
  border-radius: 30px;
  color: #e0f4ff;
  border: 1px solid rgba(100, 200, 255, 0.2);
  white-space: nowrap;
  font-size: 0.95em;
  animation: float-wind 15s linear infinite;
  opacity: 0;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.wind-icon {
  margin-right: 8px;
}

@keyframes float-wind {
  0% { transform: translateX(100vw); opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  100% { transform: translateX(-120vw); opacity: 0; }
}

/* --- サメカットイン演出 --- */
.shark-cutin-overlay {
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  z-index: 9999;
  pointer-events: none;
}
.cutin-bar {
  position: absolute;
  left: 0; width: 100%;
  height: 0;
  background: #000;
  animation: cutin-bar-in 0.3s ease-out forwards;
}
.cutin-bar-top { top: 0; }
.cutin-bar-bottom { bottom: 0; }
@keyframes cutin-bar-in {
  0% { height: 0; }
  100% { height: 18vh; }
}
.cutin-center {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 100%; height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.cutin-flash {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(255, 255, 255, 0.9);
  animation: cutin-flash-anim 0.4s ease-out forwards;
}
@keyframes cutin-flash-anim {
  0% { opacity: 1; }
  100% { opacity: 0; }
}
.cutin-shark {
  position: relative;
  display: flex;
  align-items: center;
  gap: 16px;
  background: linear-gradient(90deg, transparent 0%, rgba(20, 30, 60, 0.95) 15%, rgba(20, 30, 60, 0.95) 85%, transparent 100%);
  padding: 20px 80px;
  animation: cutin-shark-slide 0.5s cubic-bezier(0.2, 0.8, 0.3, 1) forwards;
  transform: translateX(120vw);
}
@keyframes cutin-shark-slide {
  0% { transform: translateX(120vw) skewX(-8deg); }
  60% { transform: translateX(-5vw) skewX(-3deg); }
  80% { transform: translateX(2vw) skewX(0deg); }
  100% { transform: translateX(0) skewX(0deg); }
}
.cutin-shark-emoji {
  font-size: 80px;
  filter: drop-shadow(0 0 20px rgba(100, 160, 255, 0.6));
  animation: cutin-shark-shake 0.1s ease-in-out 0.5s 6 alternate;
}
@keyframes cutin-shark-shake {
  0% { transform: translateX(-3px) rotate(-2deg); }
  100% { transform: translateX(3px) rotate(2deg); }
}
.cutin-shark-text {
  font-size: 48px;
  font-weight: 900;
  color: #fff;
  letter-spacing: 12px;
  text-shadow:
    0 0 10px rgba(100, 160, 255, 0.8),
    0 0 30px rgba(100, 160, 255, 0.4),
    2px 2px 0 #0a1628;
  animation: cutin-text-glow 0.3s ease-in-out 0.5s 4 alternate;
}
@keyframes cutin-text-glow {
  0% { text-shadow: 0 0 10px rgba(100, 160, 255, 0.8), 0 0 30px rgba(100, 160, 255, 0.4), 2px 2px 0 #0a1628; }
  100% { text-shadow: 0 0 20px rgba(255, 100, 100, 1), 0 0 60px rgba(255, 100, 100, 0.6), 2px 2px 0 #0a1628; }
}
.cutin-line {
  position: absolute;
  top: 50%;
  height: 3px;
  width: 25vw;
  transform: translateY(-50%);
  animation: cutin-line-extend 0.4s ease-out 0.2s forwards;
}
.cutin-line-left {
  right: 70%;
  background: linear-gradient(90deg, transparent, rgba(100, 160, 255, 0.8));
  transform-origin: right;
}
.cutin-line-right {
  left: 70%;
  background: linear-gradient(90deg, rgba(100, 160, 255, 0.8), transparent);
  transform-origin: left;
}
@keyframes cutin-line-extend {
  0% { width: 0; opacity: 0; }
  100% { width: 25vw; opacity: 1; }
}
/* カットイン退場 */
.cutin-leave-active {
  transition: opacity 0.4s ease;
}
.cutin-leave-to {
  opacity: 0;
}

</style>
