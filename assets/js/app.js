/* ─────────────────────────────────────────────────────────────
   T—BANEN / OSLO  ·  app.js  ·  V2
   ───────────────────────────────────────────────────────────── */

const LC = { 1:'#5B9BD5', 2:'#E8751A', 3:'#8B5EA7', 4:'#C8102E', 5:'#3D9A5C' };
const LN = { 1:'Linje 1', 2:'Linje 2', 3:'Linje 3', 4:'Linje 4', 5:'Linje 5' };

let DATA, stationMap = {}, lineSeqs = {};
let cur = { id: null, line: 1, networkLine: 1 };
let mapFocusLine = null;

const LINE_ORDER = [1, 2, 3, 4, 5];

// True only for the first photo load when arriving from the splash
let isEntryLoad = location.search.includes('enter');

// ── Init ────────────────────────────────────────────────────────────
async function init() {
  // Reveal the page — fades in from black (tunnel entry from splash)
  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.documentElement.classList.add('visible');
  }));

  const r = await fetch('/tbanen/data/stations.json');
  DATA = await r.json();
  DATA.stations.forEach(s => stationMap[s.id] = s);
  lineSeqs = DATA.lineSequences;

  qs('#btn-menu').addEventListener('click', openMenu);
  qs('#btn-close-menu').addEventListener('click', closeMenu);
  qs('#menu-btn-map').addEventListener('click', () => { closeMenu(); openNetwork(); });
  qs('#menu-btn-alpha').addEventListener('click', () => { window.location.href = '/tbanen/alle.html'; });
  qs('#btn-network').addEventListener('click', openNetwork);
  qs('#btn-close-network').addEventListener('click', closeNetwork);
  qs('#btn-prev').addEventListener('click', () => { stopSlideshow(); navPrev(); });
  qs('#btn-next').addEventListener('click', () => { stopSlideshow(); navNext(); });
  qs('#tap-prev').addEventListener('click', () => { stopSlideshow(); navPrev(); });
  qs('#tap-next').addEventListener('click', () => { stopSlideshow(); navNext(); });
  qs('#btn-play').addEventListener('click', toggleSlideshow);

  setupSwipe();
  setupKeyboard();
  buildLineTabs();
  buildMapSVG();
  buildMapLegend();
  setupMapTooltip();

  if (isEntryLoad) {
    // Panel starts hidden — will arrive after the first photo loads
    qs('#station-panel').classList.add('panel-wait');
    enterAt('nationaltheateret', 1);
  }
}

const qs  = s => document.querySelector(s);
const qsa = s => document.querySelectorAll(s);


// ── Navigation ──────────────────────────────────────────────────────
function enterAt(id, line) {
  const s = stationMap[id];
  if (!s || !s.image) return;
  cur.id   = id;
  cur.line = line || s.lines[0];
  renderStation();
  show('station');
}

// dir: 'next' | 'prev' | null
function navPrev() {
  const t = qs('#btn-prev').dataset.target;
  if (t) enterAtDir(t, cur.line, 'prev');
}
function navNext() {
  const t = qs('#btn-next').dataset.target;
  if (t) enterAtDir(t, cur.line, 'next');
}

function enterAtDir(id, line, dir) {
  const s = stationMap[id];
  if (!s || !s.image) return;

  const img = qs('#photo-img');

  // Exit current image in the direction of travel
  if (dir === 'next') {
    img.classList.add('exit-left');
  } else if (dir === 'prev') {
    img.classList.add('exit-right');
  }

  // After exit transition, switch station
  setTimeout(() => {
    img.classList.remove('exit-left', 'exit-right', 'loaded');

    cur.id   = id;
    cur.line = line || s.lines[0];
    renderStationMeta();
    loadPhotoDir(s, dir);
    markMapCurrent();
  }, 450);
}


// ── Render station ──────────────────────────────────────────────────
function renderStation() {
  const s   = stationMap[cur.id];
  const seq = lineSeqs[String(cur.line)];
  const idx = seq.indexOf(cur.id);

  let prevId = null, nextId = null;
  for (let i = idx - 1; i >= 0; i--)
    if (stationMap[seq[i]]?.image) { prevId = seq[i]; break; }
  for (let i = idx + 1; i < seq.length; i++)
    if (stationMap[seq[i]]?.image) { nextId = seq[i]; break; }

  renderStationMeta(prevId, nextId);
  loadPhoto(s);
  markMapCurrent();
}

function renderStationMeta(prevId, nextId) {
  const s   = stationMap[cur.id];
  const seq = lineSeqs[String(cur.line)];
  const idx = seq.indexOf(cur.id);

  // Recompute if not passed
  if (prevId === undefined) {
    prevId = null; nextId = null;
    for (let i = idx - 1; i >= 0; i--)
      if (stationMap[seq[i]]?.image) { prevId = seq[i]; break; }
    for (let i = idx + 1; i < seq.length; i++)
      if (stationMap[seq[i]]?.image) { nextId = seq[i]; break; }
  }

  qs('#station-name').textContent = s.name;

  const metaEl = qs('#station-meta');
  if (metaEl) {
    const parts = [];
    if (s.year)                   parts.push(s.year);
    if (s.elevation !== undefined) parts.push(s.elevation + ' moh');
    metaEl.textContent = parts.join(' · ');
  }

  const il = qs('#immersive-label');
  if (il) il.textContent = s.name;

  const ind = qs('#line-indicator');
  ind.innerHTML = s.lines.map(l =>
    `<span style="color:${LC[l]}">Linje ${l}</span>`
  ).join('<span class="line-ind-sep">·</span>');

  const homeDot = qs('#home-dot');
  if (homeDot) homeDot.style.backgroundColor = LC[cur.line] || '#fff';

  const pb = qs('#btn-prev');
  if (prevId) { pb.dataset.target = prevId; pb.disabled = false; }
  else        { pb.dataset.target = '';     pb.disabled = true;  }

  const nb = qs('#btn-next');
  if (nextId) { nb.dataset.target = nextId; nb.disabled = false; }
  else        { nb.dataset.target = '';     nb.disabled = true;  }

  // Direction labels — transit wayfinding toward each terminus
  const startName = stationMap[seq[0]]?.name;
  const endName   = stationMap[seq[seq.length - 1]]?.name;

  const prevDest = qs('.nav-dest-prev');
  const nextDest = qs('.nav-dest-next');
  if (prevDest) {
    prevDest.textContent = prevId && startName ? `← ${startName}` : '';
    prevDest.classList.toggle('has-target', !!prevId);
  }
  if (nextDest) {
    nextDest.textContent = nextId && endName ? `${endName} →` : '';
    nextDest.classList.toggle('has-target', !!nextId);
  }
}

function loadPhoto(s) {
  const img = qs('#photo-img');
  const ldr = qs('#photo-loading');

  img.classList.remove('loaded', 'exit-left', 'exit-right', 'enter-left', 'enter-right');
  ldr.classList.remove('hidden');

  img.alt = s.name;
  img.src = `/tbanen/images/stations/${s.image}`;
  img.onload = () => {
    img.classList.add('loaded');
    ldr.classList.add('hidden');
    // On entry from splash: panel arrives after photo breathes in
    if (isEntryLoad) {
      isEntryLoad = false;
      setTimeout(() => {
        const panel = qs('#station-panel');
        panel.classList.add('panel-arrive');
        requestAnimationFrame(() => panel.classList.remove('panel-wait'));
      }, 650);
    }
  };
  img.onerror = () => { ldr.classList.add('hidden'); };
}

function loadPhotoDir(s, dir) {
  const img = qs('#photo-img');
  const ldr = qs('#photo-loading');

  // Pre-position: enter from opposite side
  img.classList.remove('loaded', 'exit-left', 'exit-right', 'enter-left', 'enter-right');
  if (dir === 'next') img.classList.add('enter-right');
  else if (dir === 'prev') img.classList.add('enter-left');

  ldr.classList.remove('hidden');

  img.alt = s.name;
  img.src = `/tbanen/images/stations/${s.image}`;
  img.onload = () => {
    // Small rAF delay to ensure enter class is painted before removing it
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        img.classList.remove('enter-left', 'enter-right');
        img.classList.add('loaded');
        ldr.classList.add('hidden');
      });
    });
  };
  img.onerror = () => { ldr.classList.add('hidden'); };
}


// ── Touch swipe ─────────────────────────────────────────────────────
function setupSwipe() {
  const zone = qs('#photo-zone');
  let sx = 0, sy = 0;

  zone.addEventListener('touchstart', e => {
    sx = e.touches[0].clientX;
    sy = e.touches[0].clientY;
  }, { passive: true });

  zone.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - sx;
    const dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > Math.abs(dy) * 1.5 && Math.abs(dx) > 44) {
      stopSlideshow();
      dx < 0 ? navNext() : navPrev();
    }
  }, { passive: true });
}


// ── Keyboard ────────────────────────────────────────────────────────
function setupKeyboard() {
  document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT') return;
    if (e.key === 'ArrowLeft')  { stopSlideshow(); navPrev(); }
    if (e.key === 'ArrowRight') { stopSlideshow(); navNext(); }
    if (e.key === 'Escape') { stopSlideshow(); closeNetwork(); closeMenu(); }
    if (e.key === 'm' || e.key === 'M') {
      if (!qs('#view-network').hidden) closeNetwork();
      else if (cur.id) openNetwork();
    }
    if (e.key === ' ') { e.preventDefault(); toggleSlideshow(); }
  });
}


// ── Slideshow ───────────────────────────────────────────────────────
let slideshowTimer = null;
let immersiveTimer = null;
const SLIDE_INTERVAL = 7000;

function toggleSlideshow() {
  slideshowTimer ? stopSlideshow() : startSlideshow();
}

function startSlideshow() {
  if (!qs('#btn-next').dataset.target) {
    const seq = lineSeqs[String(cur.line)];
    const first = seq.find(id => stationMap[id]?.image);
    if (first) enterAt(first, cur.line);
  }
  qs('#btn-play').classList.add('playing');
  qs('#play-icon').style.display  = 'none';
  qs('#pause-icon').style.display = '';
  advanceSlideshow();
  scheduleImmersive();
  document.addEventListener('mousemove', onSlideshowMouseMove);
}

function stopSlideshow() {
  if (!slideshowTimer) return;
  clearTimeout(slideshowTimer);
  slideshowTimer = null;
  qs('#btn-play').classList.remove('playing');
  qs('#play-icon').style.display  = '';
  qs('#pause-icon').style.display = 'none';
  exitImmersive();
  document.removeEventListener('mousemove', onSlideshowMouseMove);
}

function scheduleImmersive() {
  clearTimeout(immersiveTimer);
  immersiveTimer = setTimeout(() => {
    document.body.classList.add('immersive');
  }, 3000);
}

function exitImmersive() {
  clearTimeout(immersiveTimer);
  immersiveTimer = null;
  document.body.classList.remove('immersive');
}

function onSlideshowMouseMove() {
  exitImmersive();
  scheduleImmersive();
}

function advanceSlideshow() {
  const nextId = qs('#btn-next').dataset.target;
  if (!nextId) {
    const nextLine = LINE_ORDER[(LINE_ORDER.indexOf(cur.line) + 1) % LINE_ORDER.length];
    const seq = lineSeqs[String(nextLine)];
    const first = seq.find(id => stationMap[id]?.image);
    if (first) {
      slideshowTimer = setTimeout(() => {
        enterAtDir(first, nextLine, 'next');
        setTimeout(advanceSlideshow, 450);
      }, SLIDE_INTERVAL);
    }
    return;
  }
  slideshowTimer = setTimeout(() => {
    enterAtDir(nextId, cur.line, 'next');
    setTimeout(advanceSlideshow, 450);
  }, SLIDE_INTERVAL);
}


// ── View switching ──────────────────────────────────────────────────
function show(view) {
  qs('#view-station').hidden = view !== 'station';
}

function openNetwork() {
  stopSlideshow();
  mapFocusLine = cur.line || null;
  cur.networkLine = cur.line || 1;
  buildStationList(cur.networkLine);
  activateTab(cur.networkLine);
  markMapCurrent();
  qs('#view-network').removeAttribute('hidden');
}

function closeNetwork() {
  qs('#view-network').setAttribute('hidden', '');
  if (cur.id) show('station');
}


// ── Network: line tabs ──────────────────────────────────────────────
function buildLineTabs() {
  const tabs = qs('#line-tabs');
  [1,2,3,4,5].forEach(n => {
    const btn = document.createElement('button');
    btn.className = 'line-tab';
    btn.dataset.line = n;
    btn.setAttribute('role', 'tab');
    btn.innerHTML = `<div class="line-tab-pip" style="background:${LC[n]}"></div><span>${n}</span>`;
    btn.addEventListener('click', () => {
      cur.networkLine = n;
      activateTab(n);
      buildStationList(n);
    });
    tabs.appendChild(btn);
  });
}

function activateTab(lineNum) {
  qsa('.line-tab').forEach(btn => {
    const n = parseInt(btn.dataset.line);
    btn.classList.toggle('active', n === lineNum);
    btn.style.borderBottomColor = n === lineNum ? LC[n] : 'transparent';
    btn.style.color = n === lineNum ? LC[n] : '';
  });
}


// ── Network: station list (mobile) ──────────────────────────────────
function buildStationList(lineNum) {
  const list = qs('#station-list');
  list.innerHTML = '';
  const seq = lineSeqs[String(lineNum)] || [];
  const color = LC[lineNum];
  const seen = new Set();

  seq.forEach(id => {
    if (seen.has(id)) return;
    seen.add(id);
    const s = stationMap[id];
    if (!s) return;

    const hasPhoto  = !!s.image;
    const isCurrent = id === cur.id;
    const xfers     = s.lines.filter(l => l !== lineNum);

    const item = document.createElement('div');
    item.className = 'list-item' +
      (hasPhoto  ? ' has-photo'  : ' no-photo') +
      (isCurrent ? ' is-current' : '');
    item.setAttribute('role', 'listitem');

    const dot = document.createElement('div');
    dot.className = 'item-dot' + (hasPhoto ? ' filled' : '');
    dot.style.color = color;

    const name = document.createElement('div');
    name.className = 'item-name';
    name.textContent = s.name;

    item.appendChild(dot);
    item.appendChild(name);

    if (xfers.length) {
      const xferEl = document.createElement('div');
      xferEl.className = 'item-xfers';
      xfers.forEach(l => {
        const pip = document.createElement('span');
        pip.className = 'xfer-pip';
        pip.style.background = LC[l];
        pip.title = LN[l];
        xferEl.appendChild(pip);
      });
      item.appendChild(xferEl);
    }

    if (hasPhoto) {
      item.addEventListener('click', () => {
        enterAt(id, lineNum);
        closeNetwork();
      });
    }

    list.appendChild(item);
  });

  const current = list.querySelector('.is-current');
  if (current) setTimeout(() => current.scrollIntoView({ block: 'center' }), 80);

  const photographed = seq.filter(id => stationMap[id]?.image);
  const unique = [...new Set(photographed)];
  qs('#list-footer').textContent =
    `${unique.length} av ${new Set(seq).size} stasjoner fotografert · ${LN[lineNum]}`;
}


// ══════════════════════════════════════════════════════════════════
// SVG MAP
// ══════════════════════════════════════════════════════════════════

const COORDS = {
  majorstua:         [430, 310],
  nationaltheateret: [508, 310],
  stortinget:        [574, 310],
  jernbanetorget:    [640, 310],
  gronland:          [706, 310],
  toyen:             [772, 310],

  frognerseteren:    [120, 68],
  voksenkollen:      [148, 98],
  vettakollen:       [178, 128],
  besserud:          [208, 158],
  holmenkollen:      [236, 186],
  midtstuen:         [256, 206],
  skadalen:          [274, 224],
  voksenlia:         [292, 242],
  gullerasen:        [308, 258],
  graakammen:        [324, 274],
  slemdal:           [340, 288],
  ris:               [356, 300],
  gaustad:           [372, 308],
  vinderen:          [390, 310],
  steinerud:         [406, 310],
  froen:             [418, 310],

  osteraas:          [120, 238],
  lijordet:          [163, 238],
  eiksmarka:         [206, 238],
  ekraveien:         [249, 238],
  roa:               [292, 238],
  hovseter:          [324, 252],
  holmen:            [352, 264],
  makrellbekken:     [376, 276],
  smestad:           [396, 286],
  borgen:            [412, 296],

  kolsas:            [120, 548],
  hauger:            [156, 512],
  gjettum:           [190, 478],
  avlos:             [222, 446],
  haslum:            [252, 416],
  gjonnes:           [280, 388],
  bekkestua:         [306, 362],
  ringstabekk:       [330, 338],
  jar:               [352, 316],
  bjornsletta:       [366, 304],
  aasjordet:         [378, 298],
  ullernaasen:       [390, 294],
  montebello:        [400, 290],

  ullevaal:          [430, 120],
  forskningsparken:  [430, 152],
  blindern:          [430, 184],

  sognsvann:         [120, 162],
  kringsjaa:         [164, 162],
  holstein:          [208, 162],
  osthorn:           [252, 162],
  tasen:             [292, 162],
  berg:              [330, 162],

  nydalen:           [772, 120],
  storo:             [772, 178],
  sinsen:            [772, 222],
  carlberner:        [772, 266],

  loren:             [840, 222],

  okern:             [800, 100],
  hasle:             [813, 91],
  rislokka:          [826, 82],
  vollebekk:         [854, 68],
  linderud:          [882, 62],
  veitvet:           [910, 62],
  rodtvet:           [936, 68],
  kalbakken:         [960, 80],
  ammerud:           [982, 96],
  grorud:            [1002, 114],
  romsos:            [1018, 134],
  rommen:            [1030, 156],
  stovner:           [1038, 180],
  vestli:            [1042, 206],

  ensjo:             [820, 340],
  helsfyr:           [848, 370],
  brynseng:          [866, 400],
  hoyenhall:         [878, 428],
  manglerud:         [884, 456],
  ryen:              [884, 482],
  brattlikollen:     [876, 506],
  karlsrud:          [862, 528],
  lambertseter:      [840, 548],
  munkelia:          [812, 564],
  bergkrystallen:    [780, 578],

  hellerud:          [840, 310],
  tveita:            [874, 288],
  haugerud:          [904, 266],
  trosterud:         [930, 244],
  lindeberg:         [952, 222],
  furuset:           [970, 200],
  ellingsrudaasen:   [984, 176],

  godlia:            [852, 342],
  skoyenaasen:       [846, 370],
  oppsal:            [836, 398],
  ulsrud:            [822, 424],
  boler:             [804, 448],
  bogerud:           [782, 470],
  skullerud:         [756, 490],
  mortensrud:        [728, 508],
};

const LINE_PATHS = {
  1: ['frognerseteren','voksenkollen','vettakollen','besserud','holmenkollen','midtstuen','skadalen','voksenlia','gullerasen','graakammen','slemdal','ris','gaustad','vinderen','steinerud','froen','majorstua','nationaltheateret','stortinget','jernbanetorget','gronland','toyen','ensjo','helsfyr','brynseng','hoyenhall','manglerud','ryen','brattlikollen','karlsrud','lambertseter','munkelia','bergkrystallen'],
  2: ['osteraas','lijordet','eiksmarka','ekraveien','roa','hovseter','holmen','makrellbekken','smestad','borgen','majorstua','nationaltheateret','stortinget','jernbanetorget','gronland','toyen','ensjo','helsfyr','brynseng','hellerud','tveita','haugerud','trosterud','lindeberg','furuset','ellingsrudaasen'],
  3: ['kolsas','hauger','gjettum','avlos','haslum','gjonnes','bekkestua','ringstabekk','jar','bjornsletta','aasjordet','ullernaasen','montebello','smestad','borgen','majorstua','nationaltheateret','stortinget','jernbanetorget','gronland','toyen','ensjo','helsfyr','brynseng','hellerud','godlia','skoyenaasen','oppsal','ulsrud','boler','bogerud','skullerud','mortensrud'],
  4: ['vestli','stovner','rommen','romsos','grorud','ammerud','kalbakken','rodtvet','veitvet','linderud','vollebekk','rislokka','okern','nydalen','storo','sinsen','carlberner','toyen','gronland','jernbanetorget','stortinget','nationaltheateret','majorstua','blindern','forskningsparken','ullevaal','nydalen','okern','rislokka','vollebekk','linderud','veitvet','rodtvet','kalbakken','ammerud','grorud','romsos','rommen','stovner','vestli'],
  5: ['sognsvann','kringsjaa','holstein','osthorn','tasen','berg','ullevaal','forskningsparken','blindern','majorstua','nationaltheateret','stortinget','jernbanetorget','gronland','toyen','carlberner','sinsen','storo','nydalen','okern','rislokka','vollebekk','linderud','veitvet','rodtvet','kalbakken','ammerud','grorud','romsos','rommen','stovner','vestli'],
};

const SPUR_PATHS = [
  { ids: ['sinsen','loren'], line: 4 },
  { ids: ['sinsen','loren'], line: 5 },
];

const ALWAYS_LABEL = new Set([
  'frognerseteren','sognsvann','osteraas','kolsas',
  'vestli','ellingsrudaasen','bergkrystallen','mortensrud',
]);

const JUNCTIONS = new Set([
  'majorstua','toyen','nydalen','ullevaal',
  'nationaltheateret','stortinget','jernbanetorget','gronland',
  'carlberner','sinsen','storo',
]);

const LABEL_POS = {
  frognerseteren:  [10, 0,   'start'],
  sognsvann:       [10, 0,   'start'],
  osteraas:        [10, 0,   'start'],
  kolsas:          [10, 0,   'start'],
  vestli:          [10, 0,   'start'],
  ellingsrudaasen: [8,  -8,  'start'],
  bergkrystallen:  [-8, 8,   'end'],
  mortensrud:      [-8, 8,   'end'],
  majorstua:       [0,  14,  'middle'],
  toyen:           [0,  14,  'middle'],
  nydalen:         [0,  -9,  'middle'],
  ullevaal:        [-8, 0,   'end'],
};

function buildMapSVG() {
  const svg = qs('#network-svg');
  svg.setAttribute('viewBox', '0 0 1120 620');

  const linesG = document.createElementNS('http://www.w3.org/2000/svg', 'g');

  const L4_WEST  = ['bergkrystallen','munkelia','lambertseter','karlsrud','brattlikollen','ryen','manglerud','hoyenhall','brynseng','helsfyr','ensjo','toyen','gronland','jernbanetorget','stortinget','nationaltheateret','majorstua','blindern','forskningsparken','ullevaal','nydalen'];
  const L4_EAST  = ['nydalen','okern','rislokka','vollebekk','linderud','veitvet','rodtvet','kalbakken','ammerud','grorud','romsos','rommen','stovner','vestli'];
  const L4_INNER = ['nydalen','storo','sinsen','carlberner','toyen'];

  const linesToDraw = [
    { n: 5, ids: LINE_PATHS[5] },
    { n: 4, ids: L4_WEST },
    { n: 4, ids: L4_EAST },
    { n: 4, ids: L4_INNER },
    { n: 3, ids: LINE_PATHS[3] },
    { n: 2, ids: LINE_PATHS[2] },
    { n: 1, ids: LINE_PATHS[1] },
  ];

  linesToDraw.forEach(({ n, ids }) => {
    const pts = ids.map(id => COORDS[id]).filter(Boolean);
    if (pts.length < 2) return;
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) d += ` L${pts[i][0]},${pts[i][1]}`;
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', d);
    path.setAttribute('stroke', LC[n]);
    path.setAttribute('stroke-width', '1.5');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke-linejoin', 'round');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('opacity', '0.15');
    path.setAttribute('data-line', n);
    path.setAttribute('class', 'svg-line');
    linesG.appendChild(path);
  });

  SPUR_PATHS.forEach(({ ids, line }) => {
    const pts = ids.map(id => COORDS[id]).filter(Boolean);
    if (pts.length < 2) return;
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', `M${pts[0][0]},${pts[0][1]} L${pts[1][0]},${pts[1][1]}`);
    path.setAttribute('stroke', LC[line]);
    path.setAttribute('stroke-width', '1.5');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('opacity', '0.55');
    path.setAttribute('data-line', line);
    path.setAttribute('class', 'svg-line');
    linesG.appendChild(path);
  });

  svg.appendChild(linesG);

  const nodesG = document.createElementNS('http://www.w3.org/2000/svg', 'g');
  const drawn = new Set();

  const allIds = new Set();
  Object.values(LINE_PATHS).forEach(ids => ids.forEach(id => allIds.add(id)));
  SPUR_PATHS.forEach(({ ids }) => ids.forEach(id => allIds.add(id)));

  allIds.forEach(id => {
    if (drawn.has(id) || !COORDS[id]) return;
    drawn.add(id);

    const s = stationMap[id];
    if (!s) return;

    const [x, y]    = COORDS[id];
    const hasPhoto   = !!s.image;
    const isJunction = JUNCTIONS.has(id);
    const showLabel  = ALWAYS_LABEL.has(id);

    const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('class', [
      'station-node',
      hasPhoto   ? 'has-photo'  : 'no-photo',
      isJunction ? 'junction'   : '',
      showLabel  ? 'show-label' : '',
    ].filter(Boolean).join(' '));
    g.setAttribute('data-id', id);
    g.setAttribute('data-lines', s.lines.join(','));
    g.setAttribute('transform', `translate(${x},${y})`);

    let r = hasPhoto ? 2 : 1.5;
    if (isJunction) r = 3;
    else if (showLabel) r = 2.5;

    const color = LC[s.lines[0]];
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('class', 'node-circle');
    circle.setAttribute('r', r);
    circle.setAttribute('fill', '#0c0c0c');
    circle.setAttribute('stroke', color);
    circle.setAttribute('stroke-width', isJunction ? '1.5' : '1');
    g.appendChild(circle);

    if (showLabel) {
      const pos = LABEL_POS[id] || [0, 13, 'middle'];
      const [lx, ly, anchor] = pos;

      const lbg = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      lbg.setAttribute('class', 'node-label-bg');
      lbg.setAttribute('x', lx); lbg.setAttribute('y', ly);
      lbg.setAttribute('text-anchor', anchor);
      lbg.textContent = s.name;
      g.appendChild(lbg);

      const lbl = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      lbl.setAttribute('class', 'node-label');
      lbl.setAttribute('x', lx); lbl.setAttribute('y', ly);
      lbl.setAttribute('text-anchor', anchor);
      lbl.textContent = s.name;
      g.appendChild(lbl);
    }

    if (hasPhoto) {
      g.addEventListener('click', () => {
        enterAt(id, s.lines[0]);
        closeNetwork();
      });
    }

    nodesG.appendChild(g);
  });

  svg.appendChild(nodesG);
}

function markMapCurrent() {
  const fl = mapFocusLine ? String(mapFocusLine) : null;

  qsa('.svg-line').forEach(p => {
    p.setAttribute('opacity', !fl ? '0.55' : (p.dataset.line === fl ? '1' : '0.08'));
  });

  qsa('.station-node').forEach(n => {
    const isCurrent = n.dataset.id === cur.id;
    n.classList.toggle('is-current', isCurrent);
    if (!fl) n.classList.remove('off-line');
    else {
      const onLine = n.dataset.lines.split(',').includes(fl);
      n.classList.toggle('off-line', !onLine);
    }
  });

  qsa('.map-legend-item').forEach(btn => {
    btn.classList.toggle('active', fl !== null && btn.dataset.line === fl);
  });
}

function setupMapTooltip() {
  const tip     = qs('#map-tooltip');
  const mapView = qs('#network-map-view');
  const svg     = qs('#network-svg');
  if (!tip || !mapView) return;

  qsa('.station-node').forEach(node => {
    const id = node.dataset.id;
    const s  = stationMap[id];
    if (!s) return;

    node.addEventListener('mouseenter', () => {
      const svgR = svg.getBoundingClientRect();
      const mapR = mapView.getBoundingClientRect();
      const vb   = svg.viewBox.baseVal;
      const [cx, cy] = COORDS[id] || [0, 0];
      const sx = svgR.left - mapR.left + cx * (svgR.width  / vb.width);
      const sy = svgR.top  - mapR.top  + cy * (svgR.height / vb.height);

      tip.textContent = s.name;
      tip.style.left = sx + 'px';
      tip.style.top  = sy + 'px';
      tip.classList.add('visible');
    });
    node.addEventListener('mouseleave', () => tip.classList.remove('visible'));
  });
}


// ── Menu overlay ────────────────────────────────────────────────────
let missingBuilt = false;

function buildMissingList() {
  const missing = DATA.stations
    .filter(s => !s.image)
    .sort((a, b) => a.name.localeCompare(b.name, 'no'));

  const title = qs('#menu-missing-title');
  const list  = qs('#menu-missing-list');
  if (!title || !list) return;

  title.textContent = `Ikke dokumentert (${missing.length})`;

  missing.forEach(s => {
    const item = document.createElement('div');
    item.className = 'menu-station-item menu-station-item--missing';

    const name = document.createElement('span');
    name.className = 'menu-station-name';
    name.textContent = s.name;

    const lines = document.createElement('span');
    lines.className = 'menu-station-lines';
    lines.innerHTML = s.lines.map(l =>
      `<span class="menu-line-pip" style="color:${LC[l]}">L${l}</span>`
    ).join('');

    item.appendChild(name);
    item.appendChild(lines);
    list.appendChild(item);
  });
}

function openMenu() {
  stopSlideshow();
  if (!missingBuilt && DATA) { buildMissingList(); missingBuilt = true; }
  qs('#menu-open-icon').style.display = 'none';
  qs('#menu-close-icon').style.display = '';
  qs('#view-menu').removeAttribute('hidden');
}

function closeMenu() {
  qs('#menu-open-icon').style.display = '';
  qs('#menu-close-icon').style.display = 'none';
  qs('#view-menu').setAttribute('hidden', '');
}


function buildMapLegend() {
  const legend = qs('#map-legend');
  if (!legend) return;
  LINE_ORDER.forEach(n => {
    const btn = document.createElement('button');
    btn.className = 'map-legend-item';
    btn.dataset.line = n;
    btn.setAttribute('aria-label', LN[n]);
    btn.innerHTML = `
      <span class="legend-pip" style="background:${LC[n]}"></span>
      <span class="legend-label">Linje ${n}</span>
    `;
    btn.addEventListener('click', () => {
      mapFocusLine = (mapFocusLine === n) ? null : n;
      markMapCurrent();
    });
    legend.appendChild(btn);
  });
}

document.addEventListener('DOMContentLoaded', init);
