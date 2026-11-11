'use strict';
const ELV = window.ELV || {seasons: [], weekend: 0, saved: [], loggedIn: false, csrf: ''};
const money = n => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP', minimumFractionDigits: 0, maximumFractionDigits: 2}).format(n);
const $ = id => document.getElementById(id);

/* ---------- Views: each nav button shows only its own page ---------- */
const views = [...document.querySelectorAll('[data-view]')];
const viewNames = new Set(views.map(v => v.dataset.view));
let currentView = null;
function route() {
  let h = decodeURIComponent(location.hash.replace(/^#\/?/, ''));
  if (!viewNames.has(h)) h = 'home';
  views.forEach(v => { v.hidden = v.dataset.view !== h; });
  document.querySelectorAll('[data-nav]').forEach(a => {
    if (a.dataset.nav === h) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
  if (currentView !== h) window.scrollTo(0, 0);
  currentView = h;
}
window.addEventListener('hashchange', route);
route();

/* ---------- Toast ---------- */
let toastTimer;
function toast(msg) {
  const t = $('toast'); if (!t) return;
  t.textContent = msg; t.hidden = false;
  clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 3500);
}

/* ---------- Pricing (mirrors lib.php so the preview matches the server) ---------- */
function seasonPercent(ymd) {
  let best = 0;
  for (const s of ELV.seasons || []) if (ymd >= s.s && ymd <= s.e && Math.abs(s.p) > Math.abs(best)) best = s.p;
  return best;
}
function nightlyRate(base, d) {   // d: Date at UTC midnight, the night that starts that day
  let f = 1; const dow = d.getUTCDay();
  if (dow === 5 || dow === 6) f *= 1 + (ELV.weekend || 0) / 100;
  f *= 1 + seasonPercent(d.toISOString().slice(0, 10)) / 100;
  return Math.round(base * f * 100) / 100;
}
function stayTotal(base, inVal, outVal) {
  let total = 0, n = 0;
  for (let d = new Date(inVal + 'T00:00:00Z'), end = new Date(outVal + 'T00:00:00Z'); d < end; d = new Date(d.getTime() + 86400000)) { total += nightlyRate(base, d); n++; }
  return {total: Math.round(total * 100) / 100, nights: n};
}

/* ---------- Room list: filters, sorting, saved rooms ---------- */
const grid = $('room-grid');
if (grid) {
  const cards = [...grid.querySelectorAll('.room-card')];
  let filter = 'all';
  let saved = [];
  try { saved = JSON.parse(localStorage.getItem('elvora-saved') || '[]'); if (!Array.isArray(saved)) saved = []; } catch {}
  if (ELV.loggedIn) {
    const local = saved.map(String); saved = (ELV.saved || []).map(String);
    // Move hearts saved while logged out onto the account, once.
    const missing = local.filter(id => !saved.includes(id));
    if (local.length) { try { localStorage.removeItem('elvora-saved'); } catch {} }
    missing.forEach(id => { saved.push(id); post({action: 'toggle_save', room_id: id}); });
  }
  function post(fields) {
    const fd = new FormData(); fd.append('csrf', ELV.csrf);
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    return fetch('index.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(r => r.json());
  }
  window.elvPost = post;
  const filterRooms = () => {
    let count = 0;
    cards.forEach(c => {
      const show = filter === 'all' || (filter === 'couples' && +c.dataset.capacity === 2) || (filter === 'family' && +c.dataset.capacity >= 4) ||
        (filter === 'suite' && c.dataset.suite === '1') || (filter === 'saved' && saved.includes(c.dataset.id));
      c.hidden = !show; if (show) count++;
    });
    $('empty-rooms').hidden = count > 0 || cards.length === 0;
  };
  const paintHearts = () => document.querySelectorAll('[data-save]').forEach(b => {
    const on = saved.includes(b.dataset.save);
    b.textContent = on ? '♥' : '♡'; b.setAttribute('aria-pressed', String(on));
  });
  paintHearts();
  document.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', () => {
    const id = b.dataset.save;
    if (ELV.loggedIn) {
      post({action: 'toggle_save', room_id: id}).then(r => {
        if (!r.ok) return toast(r.message || 'Could not save.');
        saved = r.saved ? [...saved, id] : saved.filter(x => x !== id);
        paintHearts(); filterRooms();
      }).catch(() => toast('Could not save. Check your connection.'));
    } else {
      saved = saved.includes(id) ? saved.filter(x => x !== id) : [...saved, id];
      try { localStorage.setItem('elvora-saved', JSON.stringify(saved)); } catch {}
      paintHearts(); filterRooms();
      if (saved.includes(id)) toast('Saved on this device. Log in to keep it on your account.');
    }
  }));
  document.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => {
    filter = b.dataset.filter;
    document.querySelectorAll('[data-filter]').forEach(x => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', String(x === b)); });
    filterRooms();
  }));
  $('sort').addEventListener('change', e => {
    const v = e.target.value;
    cards.sort((a, b) => v === 'high' ? b.dataset.price - a.dataset.price : v === 'capacity' ? b.dataset.capacity - a.dataset.capacity :
      v === 'rating' ? b.dataset.rating - a.dataset.rating : a.dataset.price - b.dataset.price).forEach(c => grid.append(c));
  });
  filterRooms();
}

/* ---------- Reviews pop-up ---------- */
const modal = $('reviews-modal');
if (modal) {
  $('reviews-close').addEventListener('click', () => modal.close());
  modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
  document.querySelectorAll('[data-reviews]').forEach(b => b.addEventListener('click', () => {
    $('reviews-title').textContent = b.dataset.name + ' — reviews';
    const body = $('reviews-body'); body.textContent = 'Loading…';
    modal.showModal();
    fetch('index.php?api=reviews&room=' + encodeURIComponent(b.dataset.reviews), {credentials: 'same-origin'}).then(r => r.json()).then(data => {
      body.replaceChildren();
      if (!data.reviews.length) { body.textContent = 'No reviews yet. Guests who have stayed can review from their account.'; return; }
      const sum = document.createElement('p'); sum.className = 'review-sum'; sum.textContent = '★ ' + data.avg + ' average · ' + data.count + ' review(s)'; body.append(sum);
      data.reviews.forEach(r => {
        const item = document.createElement('div'); item.className = 'review';
        const head = document.createElement('strong'); head.textContent = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating) + '  ' + r.name;
        const when = document.createElement('small'); when.textContent = ' · ' + r.date;
        const text = document.createElement('p'); text.textContent = r.comment;
        item.append(head, when, text); body.append(item);
      });
    }).catch(() => { body.textContent = 'Could not load reviews right now.'; });
  }));
}

/* ---------- Booking form: live total, promo ---------- */
const form = $('booking-form');
if (form) {
  const room = $('room-select'), arrival = $('book-in'), departure = $('book-out'), guests = $('book-guests');
  let promo = null;   // {code,type,value,min} once applied

  function update() {
    const opt = room.selectedOptions[0], rate = +(opt?.dataset.rate || 0), capacity = +(opt?.dataset.capacity || 6);
    const st = stayTotal(rate, arrival.value, departure.value), nights = st.nights;
    const valid = Number.isFinite(nights) && nights > 0 && nights <= 30, guestCount = +guests.value;
    guests.max = capacity;
    guests.setCustomValidity(guestCount > capacity ? 'This room accommodates up to ' + capacity + ' guests.' : '');
    departure.setCustomValidity(valid ? '' : 'Choose a stay between 1 and 30 nights.');
    $('capacity-hint').textContent = rate ? 'This room accommodates up to ' + capacity + ' guests.' : 'Choose a room to see its guest capacity.';
    $('summary-room').textContent = rate ? opt.textContent.split(' — ')[0] : 'A stay to look forward to';
    $('summary-dates').textContent = valid ? arrival.value + ' → ' + departure.value + ' · ' + guestCount + ' guest(s)' : 'Choose valid dates';

    const roomTotal = valid ? st.total : 0;
    $('night-label').textContent = valid && rate ? nights + ' night(s) · base ' + money(rate) : 'Room subtotal';
    $('room-subtotal').textContent = money(roomTotal);
    const note = $('rate-note');
    if (valid && rate && Math.abs(roomTotal - rate * nights) > 0.005) { note.hidden = false; note.textContent = 'Includes weekend / seasonal rates for your dates.'; } else note.hidden = true;

    let extras = 0; const lines = $('extra-lines'); lines.replaceChildren();
    form.querySelectorAll('[name="addons[]"]:checked').forEach(a => {
      const amount = +a.dataset.price * (a.dataset.unit === 'guest/night' ? (valid ? nights : 0) * guestCount : 1);
      extras += amount;
      const row = document.createElement('div'); row.className = 'summary-row';
      const name = document.createElement('span'), cost = document.createElement('strong');
      name.textContent = a.nextElementSibling.querySelector('strong').textContent; cost.textContent = money(amount);
      row.append(name, cost); lines.append(row);
    });

    let discount = 0;
    if (promo && valid && nights >= promo.min) discount = Math.round(Math.min(promo.type === 'percent' ? roomTotal * promo.value / 100 : promo.value, roomTotal) * 100) / 100;
    $('discount-line').hidden = discount <= 0;
    $('discount-amount').textContent = '−' + money(discount);
    if (promo && valid && nights < promo.min) $('promo-msg').textContent = 'Promo ' + promo.code + ' needs at least ' + promo.min + ' night(s).';
    $('grand-total').textContent = money(roomTotal - discount + extras);
  }

  $('promo-apply').addEventListener('click', () => {
    const code = $('promo-input').value.trim();
    if (!code) { promo = null; $('promo-msg').textContent = ''; update(); return; }
    const fd = new FormData();
    fd.append('csrf', ELV.csrf); fd.append('action', 'check_promo'); fd.append('promo', code);
    fd.append('check_in', arrival.value); fd.append('check_out', departure.value);
    fetch('index.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(r => r.json()).then(r => {
      promo = r.ok ? {code: r.code, type: r.type, value: r.value, min: r.min} : null;
      $('promo-msg').textContent = r.message || '';
      update();
    }).catch(() => { $('promo-msg').textContent = 'Could not check the code right now. It will be checked when you reserve.'; });
  });
  $('promo-input').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('promo-apply').click(); } });
  $('promo-input').addEventListener('input', () => { promo = null; $('promo-msg').textContent = ''; update(); });

  form.addEventListener('input', update); form.addEventListener('change', update);
  form.addEventListener('submit', () => {
    if (form.checkValidity()) { const b = $('reserve-button'); b.disabled = true; b.textContent = 'Sending your request…'; }
  });
  document.querySelectorAll('[data-book]').forEach(b => b.addEventListener('click', () => {
    room.value = b.dataset.book; update(); location.hash = 'reserve';
  }));
  if ($('promo-input').value.trim()) $('promo-apply').click();
  update();
}
