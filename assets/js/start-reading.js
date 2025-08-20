/* global PRS_SR */
document.addEventListener('DOMContentLoaded', () => {
  if (typeof PRS_SR === 'undefined') return;
  const $ = (s) => document.querySelector(s);

  // Inputs / vistas
  const $startPage    = $('#prs-sr-start-page');
  const $startView    = $('#prs-sr-start-page-view');
  const $chapter      = $('#prs-sr-chapter');
  const $chapterView  = $('#prs-sr-chapter-view');

  // Timer y acciones
  const $timer        = $('#prs-sr-timer');
  const $rowActions   = document.getElementById('prs-sr-row-actions');
  const $startBtn     = $('#prs-sr-start');
  const $stopBtn      = $('#prs-sr-stop');

  // End/Save
  const $rowEnd       = $('#prs-sr-row-end');
  const $endPage      = $('#prs-sr-end-page');
  const $rowSave      = $('#prs-sr-row-save');
  const $saveBtn      = $('#prs-sr-save');

  // Flash + wrapper formulario
  const $flash        = $('#prs-sr-flash');
  const $flashPages   = $('#prs-sr-flash-pages');
  const $flashTime    = $('#prs-sr-flash-time');
  const $formWrap     = $('#prs-sr-formwrap');

  // Aviso falta de pages
  const $rowNeedsPages = document.getElementById('prs-sr-row-needs-pages');

  // helpers de tiempo
  let t0 = 0, raf = 0;
  const pad = (n) => String(n).padStart(2, '0');
  const hms = (ms) => {
    const s = Math.floor(ms / 1000);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    return `${pad(h)}:${pad(m)}:${pad(r)}`;
  };
  const tick = () => { if ($timer) $timer.textContent = hms(Date.now() - t0); raf = requestAnimationFrame(tick); };
  const startTimer = () => { t0 = Date.now(); cancelAnimationFrame(raf); raf = requestAnimationFrame(tick); };
  const stopTimer  = () => { cancelAnimationFrame(raf); raf = 0; return Math.floor((Date.now() - t0) / 1000); };

  // util UI
  const setText   = (el, t) => { if (el) el.textContent = t || ''; };
  const toggle    = (el, show) => { if (el) el.style.display = show ? '' : 'none'; };
  const toggleRow = (row, show) => { if (row) row.style.display = show ? '' : 'none'; };

  // Validaciones de campos
  function validStart() {
    const v = Number($startPage?.value || 0);
    return Number.isInteger(v) && v > 0;
  }
  function validEnd() {
    const s = Number($startPage?.value || 0);
    const e = Number($endPage?.value || 0);
    return Number.isInteger(e) && e >= s && e > 0;
  }

  // Bloqueo por estado de posesión
  const BLOCKED = new Set(['borrowed', 'lost', 'sold']);
  const $owningSelect = document.getElementById('owning-status-select'); // si existe en la misma página

  function statusValue() {
    const v = ($owningSelect && $owningSelect.value)
      ? String($owningSelect.value).trim()
      : (PRS_SR.owning_status || 'in_shelf');
    return v;
  }
  function canStartByStatus() {
    return !BLOCKED.has(statusValue());
  }

  // Bloqueo por Pages
  function hasPages() {
    return Number(PRS_SR.total_pages || 0) > 0;
  }

  // Mensajes de tooltip en Start
  function applyStartTitle() {
    let title = '';
    if (!hasPages()) title = 'Set total Pages for this book before starting a session.';
    else if (!canStartByStatus()) title = 'You cannot start a session: the book is not in your possession (Borrowed, Lost or Sold).';
    if ($startBtn) {
      if (title) $startBtn.title = title;
      else $startBtn.removeAttribute('title');
    }
  }

  function updateStartEnabled() {
    const ok = hasPages() && canStartByStatus() && validStart();
    if ($startBtn) {
      $startBtn.disabled = !ok;
      $startBtn.setAttribute('aria-disabled', $startBtn.disabled ? 'true' : 'false');
    }
    toggleRow($rowNeedsPages, !hasPages());
    applyStartTitle();
  }

  // Flash helpers (igualar dimensiones y mostrar)
  function hideFlash() {
    if ($flash) { $flash.style.display = 'none'; }
    if ($formWrap) $formWrap.style.display = '';
  }
  function showFlash(pagesText, timeText, ms = 4200) {
    if ($flash && $formWrap) {
      const inner = $flash.querySelector('.prs-sr-flash-inner');
      if (inner) {
        const h = $formWrap.offsetHeight;
        if (h) inner.style.minHeight = `${h}px`;
      }
      setText($flashPages, pagesText);
      setText($flashTime, timeText);
      $flash.style.display = 'block';
      $formWrap.style.display = 'none';
      window.setTimeout(hideFlash, ms);
    }
  }

  // Estados UI
  function setIdle() {
    toggle($startPage, true);   toggle($startView, false);
    toggle($chapter, true);     toggle($chapterView, false);
    toggleRow($rowActions, true);
    toggle($startBtn, true);    toggle($stopBtn, false);
    toggleRow($rowEnd, false);  toggleRow($rowSave, false);
    updateStartEnabled();
  }
  function setRunning() {
    hideFlash();
    toggle($startPage, false);  toggle($startView, true);
    toggle($chapter, false);    toggle($chapterView, true);
    toggleRow($rowActions, true);
    toggle($startBtn, false);   toggle($stopBtn, true);
    toggleRow($rowEnd, false);  toggleRow($rowSave, false);
  }
  function setStopped() {
    toggle($startBtn, false);   toggle($stopBtn, false);
    toggleRow($rowActions, false);
    toggleRow($rowEnd, true);   toggleRow($rowSave, true);
    if ($saveBtn) $saveBtn.disabled = !validEnd();
  }

  // Eventos de inputs
  $startPage?.addEventListener('input', updateStartEnabled);
  $endPage?.addEventListener('input',   () => { if ($saveBtn) $saveBtn.disabled = !validEnd(); });
  $owningSelect?.addEventListener('change', updateStartEnabled);

  // Si el usuario guarda "Pages" en el panel del libro (mismo DOM)
  const $pagesSave = document.getElementById('pages-save');
  const $pagesInput = document.getElementById('pages-input');
  if ($pagesSave && $pagesInput) {
    $pagesSave.addEventListener('click', () => {
      const v = Number($pagesInput.value || 0);
      if (Number.isInteger(v) && v > 0) {
        PRS_SR.total_pages = v;  // actualiza cache local
        updateStartEnabled();
      }
    });
  }

  // API
  const ACTION_START = (PRS_SR?.actions?.start) || 'prs_start_reading';
  const ACTION_SAVE  = (PRS_SR?.actions?.save)  || 'prs_save_reading';

  async function api(action, payload) {
    const body = new URLSearchParams({
      action,
      nonce: PRS_SR.nonce,
      user_id: PRS_SR.user_id,
      book_id: PRS_SR.book_id,
      ...payload
    });
    const r = await fetch(PRS_SR.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });
    let out; try { out = await r.json(); } catch { out = { success:false, message:'bad_json' }; }
    return out;
  }

  // === NUEVO: mantener y enviar session_id ===
  let sessionId = null;
  let durationSec = 0;

  // Start
  $startBtn?.addEventListener('click', async (e) => {
    if ($startBtn.disabled || !hasPages() || !canStartByStatus() || !validStart()) {
      e.preventDefault();
      updateStartEnabled();
      return;
    }
    sessionId = null; // reset
    const startPageVal = Number($startPage.value);
    const chapterVal   = ($chapter?.value || '').trim();

    setText($startView, String(startPageVal));
    setText($chapterView, chapterVal || '—');

    setRunning();
    startTimer();

    try {
      const out = await api(ACTION_START, { start_page: startPageVal, chapter_name: chapterVal });
      if (out?.success) {
        // <<< guardar session_id del backend
        sessionId = out?.data?.session_id ?? null;
      } else {
        // Revertimos si backend bloquea (doble seguridad)
        stopTimer();
        setIdle();
        console.error('Start reading error', out);
        if (out?.message === 'pages_required' || out?.data?.message === 'pages_required') {
          alert('You must set total Pages to start a session.');
        }
      }
    } catch (err) {
      console.error(err);
      stopTimer();
      setIdle();
      alert('Network error while starting the session.');
    }
  });

  // Stop
  $stopBtn?.addEventListener('click', () => {
    durationSec = stopTimer();
    setStopped();
    if ($endPage && $startPage && !$endPage.value) {
      $endPage.value = String(Number($startPage.value));
      if ($saveBtn) $saveBtn.disabled = !validEnd();
    }
  });

  // Save
  $saveBtn?.addEventListener('click', async () => {
    if (!validEnd()) return;
    $saveBtn.disabled = true;

    try {
      const start = Number($startPage.value);
      const end   = Number($endPage.value);
      const chapterVal = ($chapter?.value || '').trim();

      const out = await api(ACTION_SAVE, {
        session_id: sessionId || '', // <<< enviar session_id para actualizar placeholder
        start_page: start,
        end_page: end,
        chapter_name: chapterVal,
        duration_sec: durationSec
      });

      if (out?.success) {
        // Limpiar sessionId: la sesión quedó cerrada
        sessionId = null;

        // Actualiza “Last session page” en la UI
        const lastNode = document.querySelector('.prs-sr-last strong');
        if (lastNode) lastNode.textContent = String(end);

        // Prepara próxima sesión: start = end
        if ($startPage) $startPage.value = String(end);

        // Fancy texts
        const pages = Math.max(0, end - start);
        const pagesTxt = (pages === 1) ? '1 página' : `${pages} páginas`;
        const mins  = Math.round(durationSec / 60);
        const minsTxt = durationSec < 60
          ? 'menos de un minuto'
          : (mins === 1 ? '1 minuto' : `${mins} minutos`);

        // Dejar UI lista y mostrar flash
        setIdle();
        showFlash(pagesTxt, minsTxt, 4200);
      } else {
        console.error('Save reading error', out);
        $saveBtn.disabled = false;
        alert('Could not save the session.');
      }
    } catch (err) {
      console.error(err);
      $saveBtn.disabled = false;
      alert('Network error while saving the session.');
    }
  });

  // Estado inicial
  setIdle();
  if (PRS_SR.last_end_page && !$startPage.value) {
    $startPage.value = PRS_SR.last_end_page;
  }
  updateStartEnabled();
});
