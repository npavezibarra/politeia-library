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
  const $msg          = $('#prs-sr-msg');

  // End/Save
  const $rowEnd       = $('#prs-sr-row-end');
  const $endPage      = $('#prs-sr-end-page');
  const $rowSave      = $('#prs-sr-row-save');
  const $saveBtn      = $('#prs-sr-save');
  const $saveMsg      = $('#prs-sr-save-msg');

  // ---- helpers de tiempo
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

  // ---- util UI
  const setText   = (el, t) => { if (el) el.textContent = t || ''; };
  const toggle    = (el, show) => { if (el) el.style.display = show ? '' : 'none'; };
  const toggleRow = (row, show) => { if (row) row.style.display = show ? '' : 'none'; };

  const validStart = () => {
    const v = Number($startPage?.value || 0);
    return Number.isInteger(v) && v > 0;
  };
  const validEnd = () => {
    const s = Number($startPage?.value || 0);
    const e = Number($endPage?.value || 0);
    return Number.isInteger(e) && e >= s && e > 0;
  };

  function setIdle() {
    toggle($startPage, true);   toggle($startView, false);
    toggle($chapter, true);     toggle($chapterView, false);
    toggleRow($rowActions, true);
    toggle($startBtn, true);    toggle($stopBtn, false);
    toggleRow($rowEnd, false);  toggleRow($rowSave, false);
    setText($msg, '');          setText($saveMsg, '');
    if ($startBtn) $startBtn.disabled = !validStart();
  }

  function setRunning() {
    toggle($startPage, false);  toggle($startView, true);
    toggle($chapter, false);    toggle($chapterView, true);
    toggleRow($rowActions, true);
    toggle($startBtn, false);   toggle($stopBtn, true);
    toggleRow($rowEnd, false);  toggleRow($rowSave, false);
    setText($msg, '');
  }

  function setStopped() {
    toggle($startBtn, false);   toggle($stopBtn, false);
    toggleRow($rowActions, false);  // ocultamos toda la fila → no deja hueco
    toggleRow($rowEnd, true);   toggleRow($rowSave, true);
    if ($saveBtn) $saveBtn.disabled = !validEnd();
  }

  // Habilitar/deshabilitar por input
  $startPage?.addEventListener('input', () => { if ($startBtn) $startBtn.disabled = !validStart(); });
  $endPage?.addEventListener('input',   () => { if ($saveBtn)  $saveBtn.disabled  = !validEnd();   });

  // ---- API
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

    let out;
    try { out = await r.json(); }
    catch (e) { return { success: false, message: 'bad_json' }; }

    return out;
  }

  let durationSec = 0;

  // ---- Start
  $startBtn?.addEventListener('click', async () => {
    if (!validStart()) return;

    const startPage = Number($startPage.value);
    const chapter   = ($chapter.value || '').trim();

    setText($startView, String(startPage));
    setText($chapterView, chapter || '—');

    setRunning();
    startTimer();
    setText($msg, 'Starting…');

    try {
      const out = await api(ACTION_START, { start_page: startPage, chapter_name: chapter });
      if (out?.success) {
        setText($msg, 'Running');
      } else {
        console.error('Start reading error', out);
        setText($msg, out?.data?.message || out?.message || 'Error (start)');
      }
    } catch (e) {
      console.error(e);
      setText($msg, 'Error (network)');
    }
  });

  // ---- Stop
  $stopBtn?.addEventListener('click', () => {
    durationSec = stopTimer();
    setStopped();
    // En UX podemos pre-rellenar endPage con startPage como mínimo
    if ($endPage && $startPage && !$endPage.value) {
      $endPage.value = String(Number($startPage.value));
      if ($saveBtn) $saveBtn.disabled = !validEnd();
    }
  });

  // ---- Save
  $saveBtn?.addEventListener('click', async () => {
    if (!validEnd()) return;

    $saveBtn.disabled = true;
    setText($saveMsg, 'Saving…');

    try {
      const payload = {
        start_page:  Number($startPage.value),
        end_page:    Number($endPage.value),
        chapter_name: ($chapter.value || '').trim(),
        duration_sec: durationSec
      };

      const out = await api(ACTION_SAVE, payload);
      if (out?.success) {
        setText($saveMsg, 'Saved');

        // Actualiza "Last session page" visual si existe
        const lastNode = document.querySelector('.prs-sr-last strong');
        if (lastNode) lastNode.textContent = String(payload.end_page);

        // Dejar preparado para la próxima: start = end
        if ($startPage) $startPage.value = String(payload.end_page);
        setIdle();
      } else {
        console.error('Save reading error', out);
        setText($saveMsg, out?.data?.message || out?.message || 'Error (save)');
        $saveBtn.disabled = false;
      }
    } catch (e) {
      console.error(e);
      setText($saveMsg, 'Error (network)');
      $saveBtn.disabled = false;
    }
  });

  // ---- Estado inicial
  setIdle();
  if (PRS_SR.last_end_page && !$startPage.value) {
    $startPage.value = PRS_SR.last_end_page;
    if ($startBtn) $startBtn.disabled = !validStart();
  }
});
