import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader.js';
import { DRACOLoader } from 'three/examples/jsm/loaders/DRACOLoader.js';

// =========================
// 0) 前后端分离：从 URL 取参 + 拉取 JSON
// =========================
const API = {
  // 你后端一会儿改 PHP：做成这个接口即可
  sceneDetail: (sceneId) => `/tv/api/sceneDetail?scene_id=${encodeURIComponent(sceneId)}`,
};

function qs(name) {
  const u = new URL(location.href);
  return u.searchParams.get(name);
}

const toastEl = document.getElementById('toast');
function toast(msg) {
  toastEl.textContent = String(msg || '');
  toastEl.classList.add('show');
  setTimeout(() => toastEl.classList.remove('show'), 5200);
}

async function fetchSceneAndModels(sceneId) {
  const url = API.sceneDetail(sceneId);
  const r = await fetch(url, {
    method: 'GET',
    headers: { Accept: 'application/json' },
    cache: 'no-store',
    credentials: 'same-origin',
  });

  if (!r.ok) {
    const text = await r.text().catch(() => '');
    throw new Error(`API HTTP ${r.status} ${r.statusText}\n${url}\n${text}`);
  }

  const json = await r.json().catch(() => null);
  if (!json || typeof json !== 'object') {
    throw new Error(`API 返回非 JSON：${url}`);
  }
  if (json.code !== 0) {
    throw new Error(`API code!=0：${json.msg || 'unknown'}\n${url}`);
  }

  const data = json.data || {};
  const scene = data.scene || null;
  const models = Array.isArray(data.models) ? data.models : [];
  return { scene, models };
}

// =========================
// 1) 颜色策略
// =========================
const FALLBACK_PALETTE = [
  '#D9DEE7', '#BFC9D6', '#C9D3C1', '#D6C7B8',
  '#C7CCD3', '#D1D7DD', '#C9C2C9', '#D0D0C8',
];
const pickFallbackColor = (idx) => FALLBACK_PALETTE[idx % FALLBACK_PALETTE.length];

function normalizeColorHex(s) {
  if (!s) return '';
  const t = String(s).trim();
  if (!t) return '';
  if (t[0] === '#') return t;
  if (/^[0-9a-fA-F]{6}$/.test(t)) return `#${t}`;
  return t;
}
function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// =========================
// 2) 分类（按中文 display_name）
// =========================
const CN_SETS = {
  cardio: new Set([
    '心脏', '主动脉', '肺静脉', '左心耳',
    '上腔静脉', '下腔静脉', '头臂干', '右锁骨下动脉', '左锁骨下动脉',
    '右颈总动脉', '左颈总动脉', '左头臂静脉', '右头臂静脉',
  ]),
  organ: new Set([
    '肝脏', '胆囊', '胰腺', '脾脏', '胃', '十二指肠', '门静脉及脾静脉',
    '右肾上腺', '左肾上腺', '右肾', '左肾', '食管',
  ]),
  lung: new Set([
    '左肺', '右肺', '左肺上叶', '左肺下叶', '右肺上叶', '右肺中叶', '右肺下叶',
    '气管',
  ]),
  bone: new Set(['骨骼']),
};

function getCNName(m) {
  return (m?.display_name || m?.name || m?.title || '').toString().trim();
}
function categorizeModelByCN(m) {
  const cn = getCNName(m);
  if (CN_SETS.cardio.has(cn)) return 'cardio';
  if (CN_SETS.lung.has(cn)) return 'lung';
  if (CN_SETS.organ.has(cn)) return 'organ';
  if (CN_SETS.bone.has(cn)) return 'bone';
  return 'other';
}

const CATEGORIES = [
  { id: 'cardio', title: '心血管系统', icon: 'CV', open: false },
  { id: 'lung', title: '肺', icon: 'LU', open: false },
  { id: 'organ', title: '内脏', icon: 'OR', open: false },
  { id: 'bone', title: '骨骼', icon: 'BO', open: false },
  { id: 'other', title: '其他', icon: 'ET', open: false },
];

// =========================
// 3) three.js 场景
// =========================
const container = document.getElementById('wrap');
const scene3 = new THREE.Scene();
scene3.background = new THREE.Color(0x0f141c);

const camera = new THREE.PerspectiveCamera(55, container.clientWidth / container.clientHeight, 0.1, 200000);
camera.position.set(0, 300, 500);

const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.setSize(container.clientWidth, container.clientHeight);
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.15;
container.appendChild(renderer.domElement);

const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;

scene3.add(new THREE.HemisphereLight(0xeff5ff, 0x0b0f14, 0.45));
scene3.add(new THREE.AmbientLight(0xffffff, 0.75));
const dir1 = new THREE.DirectionalLight(0xffffff, 0.9);
dir1.position.set(1.2, 1.4, 0.8);
scene3.add(dir1);

const dir2 = new THREE.DirectionalLight(0xffffff, 0.55);
dir2.position.set(-1.1, 0.8, -0.8);
scene3.add(dir2);

const rim = new THREE.DirectionalLight(0xadd8ff, 0.35);
rim.position.set(0.2, 0.8, 1.4);
scene3.add(rim);

const grid = new THREE.GridHelper(1000, 20, 0x2a3442, 0x1d2531);
grid.position.y = 0;
grid.material.opacity = 0.25;
grid.material.transparent = true;
scene3.add(grid);

const dracoLoader = new DRACOLoader();
dracoLoader.setDecoderPath('/static/draco/');

const gltfLoader = new GLTFLoader();
gltfLoader.setDRACOLoader(dracoLoader);

const stlLoader = new STLLoader();
const objLoader = new OBJLoader();

const globalBox = new THREE.Box3();
const modelRoot = new THREE.Group();
scene3.add(modelRoot);

// =========================
// 4) UI：文件夹 + 条目
// =========================
const modelListEl = document.getElementById('modelList');
const progressFill = document.getElementById('progressFill');
const progressText = document.getElementById('progressText');
const progressState = { loaded: 0, total: 0 };

function updateProgress(label = '正在下载…') {
  const pct = progressState.total > 0 ? Math.min(100, (progressState.loaded / progressState.total) * 100) : 0;
  progressFill.style.width = `${pct}%`;
  progressText.textContent = `${label}（${pct.toFixed(0)}%）`;
}

// folderRefs: catId -> { details, body, countEl, emptyEl, count, visible, visWrap, visInput, visText }
const folderRefs = new Map();
const entries = []; // { wrapper, material, card, meta, catId }

function isFolderVisible(catId) {
  const ref = folderRefs.get(catId);
  return ref ? !!ref.visible : true;
}
function refreshEntryVisible(entry) {
  const folderOk = isFolderVisible(entry.catId);
  entry.wrapper.visible = folderOk && entry.card.toggle.checked;
  entry.card.status.textContent = entry.wrapper.visible ? '展示中' : '已隐藏';
}
function setFolderVisible(catId, visible) {
  const ref = folderRefs.get(catId);
  if (!ref) return;
  ref.visible = !!visible;

  if (ref.visInput) ref.visInput.checked = ref.visible;
  if (ref.visText) ref.visText.textContent = ref.visible ? '可见' : '隐藏';
  if (ref.visWrap) ref.visWrap.classList.toggle('toggle-on', ref.visible);

  for (const e of entries) {
    if (!e?.wrapper || !e?.card) continue;
    if (e.catId !== catId) continue;
    refreshEntryVisible(e);
  }
}

function createFolder(cat) {
  const details = document.createElement('details');
  details.className = 'folder';
  if (cat.open) details.open = true;

  const summary = document.createElement('summary');
  summary.innerHTML = `
      <div class="folder-left">
        <span class="folder-icon">${escapeHtml(cat.icon)}</span>
        <span>${escapeHtml(cat.title)}</span>
      </div>
      <div class="folder-meta">
        <div class="folder-vis toggle-on" role="button" tabindex="0" title="显示/隐藏此目录下全部模型">
          <span class="folder-vis-text">可见</span>
          <label class="switch" aria-label="目录显示/隐藏">
            <input class="folder-vis-input" type="checkbox" checked>
            <span class="slider"></span>
          </label>
        </div>
        <span class="folder-count">0</span>
        <span class="chev">›</span>
      </div>
    `;

  const body = document.createElement('div');
  body.className = 'folder-body';

  const empty = document.createElement('div');
  empty.className = 'folder-empty';
  empty.textContent = '暂无模型';
  body.appendChild(empty);

  details.appendChild(summary);
  details.appendChild(body);
  modelListEl.appendChild(details);

  const countEl = summary.querySelector('.folder-count');
  const visWrap = summary.querySelector('.folder-vis');
  const visInput = summary.querySelector('.folder-vis-input');
  const visText = summary.querySelector('.folder-vis-text');

  const ref = { details, body, countEl, emptyEl: empty, count: 0, visible: true, visWrap, visInput, visText };
  folderRefs.set(cat.id, ref);

  const stop = (e) => { e.preventDefault(); e.stopPropagation(); };

  visWrap.addEventListener('click', (e) => {
    stop(e);
    visInput.checked = !visInput.checked;
    visInput.dispatchEvent(new Event('change', { bubbles: true }));
  });
  visWrap.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      stop(e);
      visInput.checked = !visInput.checked;
      visInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });

  visInput.addEventListener('click', (e) => e.stopPropagation());
  visInput.addEventListener('change', (e) => {
    e.stopPropagation();
    setFolderVisible(cat.id, e.target.checked);
  });
}

CATEGORIES.forEach(createFolder);

function bumpFolderCount(catId) {
  const ref = folderRefs.get(catId);
  if (!ref) return;
  ref.count += 1;
  ref.countEl.textContent = String(ref.count);
  if (ref.emptyEl) ref.emptyEl.style.display = 'none';
}

function addModelCard(m, color, parentEl, defaultOpacity = 100) {
  const div = document.createElement('div');
  div.className = 'item';

  const dot = `<span class="dot" style="background:${color}"></span>`;
  const op = Math.max(0, Math.min(100, Number(defaultOpacity) || 100));

  div.innerHTML = `
      <div class="row">
        <div class="name">${dot}${escapeHtml(m.display_name || '未命名')}</div>
        <label class="switch">
          <input type="checkbox" checked disabled aria-label="显示 / 隐藏模型">
          <span class="slider"></span>
        </label>
      </div>
      <div class="sub">
        ${m.material_text ? `材料：${escapeHtml(m.material_text)}<br/>` : ''}
        ${m.color_hex ? `颜色：${escapeHtml(m.color_hex)}<br/>` : ''}
        <span class="status">准备加载…</span>
      </div>
      <div class="card-controls">
        <div class="range">
          <span>透明度</span>
          <input type="range" min="0" max="100" value="${op}" disabled aria-label="调整透明度">
          <span class="range-value">${op}%</span>
        </div>
        <button class="btn small" disabled>聚焦</button>
      </div>
    `;

  (parentEl || modelListEl).appendChild(div);

  return {
    root: div,
    toggle: div.querySelector('input[type=checkbox]'),
    opacityRange: div.querySelector('input[type=range]'),
    opacityValue: div.querySelector('.range-value'),
    focusBtn: div.querySelector('button'),
    status: div.querySelector('.status'),
  };
}

function materialForModel(m, idx) {
  const hasColor = !!normalizeColorHex(m.color_hex);
  const hasMaterial = !!(m.material_text && String(m.material_text).trim());
  let color = null;

  if (hasMaterial && !hasColor) color = '#D9DEE7';
  else if (hasColor) color = normalizeColorHex(m.color_hex);
  else if (!hasColor && !hasMaterial) color = pickFallbackColor(idx);
  else color = normalizeColorHex(m.color_hex) || '#D9DEE7';

  const mat = new THREE.MeshStandardMaterial({
    color: new THREE.Color(color),
    metalness: 0.05,
    roughness: 0.85,
    transparent: true,
    opacity: 1,
    side: THREE.DoubleSide,
  });
  mat.flatShading = false;
  return { mat, color };
}

function bindCard(card, entry) {
  if (!card) return;

  card.status.textContent = '展示中';
  card.toggle.disabled = false;
  card.opacityRange.disabled = false;
  card.focusBtn.disabled = false;

  entries.push({ ...entry, card });

  card.toggle.addEventListener('change', () => refreshEntryVisible({ ...entry, card }));

  const applyOpacity = (value) => {
    const clamped = Math.max(0, Math.min(100, value));
    const v = clamped / 100;

    entry.material.opacity = v;
    entry.material.transparent = v < 1;
    entry.material.depthWrite = (v === 1);
    entry.material.depthTest = true;

    entry.wrapper.traverse((o) => { if (o.isMesh) o.renderOrder = (v < 1) ? 2 : 0; });
    entry.material.needsUpdate = true;
    card.opacityValue.textContent = `${Math.round(clamped)}%`;
  };

  applyOpacity(Number(card.opacityRange.value || 100));
  card.opacityRange.addEventListener('input', (e) => applyOpacity(Number(e.target.value)));
  card.focusBtn.addEventListener('click', () => fitToObject(entry.wrapper));

  refreshEntryVisible({ ...entry, card });
}

function fitToBox(box) {
  if (!box || box.isEmpty()) return;

  const size = new THREE.Vector3();
  const center = new THREE.Vector3();
  box.getSize(size);
  box.getCenter(center);

  controls.target.copy(center);

  const maxDim = Math.max(size.x, size.y, size.z);
  const fov = camera.fov * (Math.PI / 180);
  let dist = (maxDim / 2) / Math.tan(fov / 2);
  dist *= 1.6;

  const dir = new THREE.Vector3(1, 0.9, 1).normalize();
  camera.position.copy(center.clone().add(dir.multiplyScalar(dist)));
  camera.near = Math.max(0.1, dist / 1000);
  camera.far = Math.max(2000, dist * 10);
  camera.updateProjectionMatrix();
  controls.update();
}
function fitToObject(obj) {
  if (!obj) return;
  const box = new THREE.Box3().setFromObject(obj);
  fitToBox(box);
}

document.getElementById('btnFit').addEventListener('click', () => fitToBox(globalBox));
document.getElementById('btnReset').addEventListener('click', () => {
  camera.position.set(0, 300, 500);
  controls.target.set(0, 0, 0);
  controls.update();
});

// 面板开关
const body = document.body;
const btnTogglePanel = document.getElementById('btnTogglePanel');
const topbarEl = document.querySelector('.topbar');
let panelOpen = window.innerWidth > 900;

function syncPanelState(forceOpen = null) {
  if (forceOpen !== null) panelOpen = forceOpen;
  body.classList.toggle('panel-hidden', !panelOpen);
  body.classList.toggle('panel-visible', panelOpen);
  btnTogglePanel.textContent = panelOpen ? '收起控制' : '模型控制';
}

syncPanelState(panelOpen);
btnTogglePanel.addEventListener('click', () => syncPanelState(!panelOpen));

const updateTopbarHeight = () => {
  const h = Math.max(56, Math.round(topbarEl?.getBoundingClientRect().height || 56));
  document.documentElement.style.setProperty('--topbar-height', `${h}px`);
};
updateTopbarHeight();
const topbarObserver = new ResizeObserver(updateTopbarHeight);
if (topbarEl) topbarObserver.observe(topbarEl);

window.addEventListener('resize', () => {
  if (window.innerWidth > 900 && !panelOpen) syncPanelState(true);
});

// =========================
// 5) 载入模型
// =========================
async function loadOne(m, idx) {
  const url = m.file_path;
  if (!url) return;

  const catId = categorizeModelByCN(m);
  const folder = folderRefs.get(catId) || folderRefs.get('other');
  const parentEl = folder?.body || modelListEl;

  const defaultOpacity = (catId === 'lung') ? 70 : 100;

  const { mat, color } = materialForModel(m, idx);
  const card = addModelCard(m, color, parentEl, defaultOpacity);
  bumpFolderCount(folder ? catId : 'other');

  const modelTotal = Number(m.file_size || m.file_size_bytes) || 1;
  let modelLoaded = 0;
  const progressLabel = `下载 ${m.display_name || m.file_type || '模型'}`;

  const markFailed = () => {
    card.status.textContent = '加载失败';
    card.toggle.disabled = true;
    card.opacityRange.disabled = true;
    card.focusBtn.disabled = true;
  };

  const handleProgress = (evt) => {
    if (!evt || typeof evt.loaded !== 'number') return;
    let current = evt.loaded;
    if (evt.lengthComputable && evt.total > 0) {
      const ratio = modelTotal / evt.total;
      current = Math.min(modelTotal, evt.loaded * ratio);
    } else current = Math.min(modelTotal, evt.loaded);

    const delta = current - modelLoaded;
    if (delta > 0) {
      modelLoaded += delta;
      progressState.loaded += delta;
      updateProgress(progressLabel);
    }
  };

  const finalizeProgress = () => {
    const delta = modelTotal - modelLoaded;
    if (delta > 0) {
      modelLoaded = modelTotal;
      progressState.loaded += delta;
      updateProgress(progressLabel);
    }
  };

  const type = (m.file_type || '').toLowerCase();

  const addModelToScene = (object) => {
    const wrapper = new THREE.Group();
    wrapper.add(object);

    // 你原本逻辑：Z-up -> Y-up
    wrapper.rotation.x = -Math.PI / 2;

    modelRoot.add(wrapper);
    globalBox.expandByObject(wrapper);
    return wrapper;
  };

  if (type === 'glb' || type === 'gltf') {
    await new Promise((resolve, reject) => {
      gltfLoader.load(url, (gltf) => {
        const obj = gltf.scene || gltf.scenes?.[0];
        if (!obj) { finalizeProgress(); resolve(); return; }
        obj.traverse((child) => { if (child.isMesh) child.material = mat; });
        const wrapper = addModelToScene(obj);
        bindCard(card, { wrapper, material: mat, meta: m, catId });
        finalizeProgress();
        resolve({ wrapper });
      }, handleProgress, (err) => { finalizeProgress(); markFailed(); reject(err); });
    });
    return;
  }

  if (type === 'drc' || type === 'draco') {
    await new Promise((resolve, reject) => {
      dracoLoader.load(url, (geometry) => {
        geometry.computeVertexNormals?.();
        const mesh = new THREE.Mesh(geometry, mat);
        const wrapper = addModelToScene(mesh);
        bindCard(card, { wrapper, material: mat, meta: m, catId });
        finalizeProgress();
        resolve({ wrapper });
      }, handleProgress, (err) => { finalizeProgress(); markFailed(); reject(err); });
    });
    return;
  }

  if (type === 'stl') {
    await new Promise((resolve, reject) => {
      stlLoader.load(url, (geometry) => {
        geometry.computeVertexNormals?.();
        const mesh = new THREE.Mesh(geometry, mat);
        const wrapper = addModelToScene(mesh);
        bindCard(card, { wrapper, material: mat, meta: m, catId });
        finalizeProgress();
        resolve({ wrapper });
      }, handleProgress, (err) => { finalizeProgress(); markFailed(); reject(err); });
    });
    return;
  }

  if (type === 'obj') {
    await new Promise((resolve, reject) => {
      objLoader.load(url, (obj) => {
        obj.traverse((child) => { if (child.isMesh) child.material = mat; });
        const wrapper = addModelToScene(obj);
        bindCard(card, { wrapper, material: mat, meta: m, catId });
        finalizeProgress();
        resolve({ wrapper });
      }, handleProgress, (err) => { finalizeProgress(); markFailed(); reject(err); });
    });
    return;
  }

  console.warn('Unsupported file_type:', type, m);
  card.status.textContent = '格式暂不支持';
}

// =========================
// 6) 启动：先请求场景，再加载模型
// =========================
function applySceneToTopbar(sceneObj, sceneId) {
  const titleEl = document.getElementById('sceneTitle');
  const idEl = document.getElementById('sceneIdText');
  const metaEl = document.getElementById('sceneMeta');

  const title = (sceneObj?.title || '场景').toString();
  const idText = `#${sceneObj?.id ?? sceneId ?? '-'}`;

  titleEl.innerHTML = `${escapeHtml(title)} <span style="opacity:.7">${escapeHtml(idText)}</span>`;
  idEl.textContent = idText;

  const imagingNumber = sceneObj?.imaging_number ?? '-';
  const imagingDate = sceneObj?.imaging_date ?? '-';
  const reconDate = sceneObj?.recon_date ?? '-';
  metaEl.textContent = `影像号：${imagingNumber}　影像日期：${imagingDate}　重建日期：${reconDate}`;

  document.title = `${title} - Scene ${sceneObj?.id ?? sceneId ?? ''}`.trim();
}

async function boot() {
  const sceneId = qs('scene_id');
  if (!sceneId) {
    progressText.textContent = '缺少 scene_id';
    modelListEl.innerHTML = '<div class="sub">URL 里需要带 scene_id，例如：<br/><b>?scene_id=16</b></div>';
    toast('URL 缺少 scene_id 参数');
    return;
  }

  progressText.textContent = '正在获取场景数据…';
  updateProgress('准备下载模型…');

  let sceneObj = null;
  let models = [];
  try {
    const ret = await fetchSceneAndModels(sceneId);
    sceneObj = ret.scene;
    models = ret.models;
  } catch (e) {
    console.error(e);
    progressText.textContent = '获取场景失败';
    modelListEl.innerHTML = `<div class="sub">获取场景数据失败。请检查接口：<br/><b>${escapeHtml(API.sceneDetail(sceneId))}</b></div>`;
    toast(String(e?.message || e));
    return;
  }

  applySceneToTopbar(sceneObj, sceneId);

  const list = (models || []).filter((m) => m && m.file_path);
  if (!list.length) {
    modelListEl.innerHTML = '<div class="sub">该场景暂无模型数据。</div>';
    progressText.textContent = '无可下载的模型';
    return;
  }

  progressState.total = list.reduce((sum, m) => sum + (Number(m.file_size || m.file_size_bytes) || 1), 0);
  progressState.loaded = 0;
  updateProgress('准备下载模型…');

  for (let i = 0; i < list.length; i += 1) {
    try { await loadOne(list[i], i); }
    catch (e) { console.error('Load failed:', list[i], e); }
  }

  for (const cat of CATEGORIES) {
    const ref = folderRefs.get(cat.id);
    if (!ref) continue;
    if (ref.count <= 0 && ref.emptyEl) ref.emptyEl.style.display = 'block';
  }

  progressState.loaded = progressState.total;
  updateProgress('下载完成，模型已就绪');
  fitToBox(globalBox);
}

boot();

// =========================
// 7) 渲染循环 + resize
// =========================
function animate() {
  controls.update();
  renderer.render(scene3, camera);
  requestAnimationFrame(animate);
}
animate();

window.addEventListener('resize', () => {
  const w = container.clientWidth;
  const h = container.clientHeight;
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
  renderer.setSize(w, h);
});
