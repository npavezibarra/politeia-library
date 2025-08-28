(function () {
  // Fuente de verdad para user_book_id / book_id
  function getContext() {
    const g = (window.PRS_BOOK || {});
    return {
      user_book_id: g.user_book_id || 0,
      book_id: g.book_id || 0
    };
  }

  // Helpers
  const $ = (s, r = document) => r.querySelector(s);
  const el = (t, cls) => { const e = document.createElement(t); if (cls) e.className = cls; return e; };

  // Estado de la imagen en el stage
  const STAGE_W = 280; // más pequeño => cabe sin scroll
  const STAGE_H = 450;

  let modal, stage, imgEl, slider, saveBtn, cancelBtn, fileInput, statusEl;
  let naturalW = 0, naturalH = 0;
  let scale = 1, minScale = 1;

  function openModal() {
    if (modal) { modal.remove(); modal = null; }

    // Overlay
    modal = el('div', 'prs-cover-modal');
    const panel = el('div', 'prs-cover-modal__content');

    const title = el('div', 'prs-cover-modal__title');
    title.textContent = 'Upload Book Cover';

    // Controles
    const topBar = el('div', 'prs-cover-modal__topbar');
    fileInput = el('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    topBar.appendChild(fileInput);

    // Stage centrado
    const wrap = el('div', 'prs-crop-wrap'); // centra horizontalmente
    stage = el('div', 'prs-crop-stage');
    stage.style.width = STAGE_W + 'px';
    stage.style.height = STAGE_H + 'px';
    wrap.appendChild(stage);

    // Slider visible sin scroll
    const controls = el('div', 'prs-crop-controls');
    const label = el('span', 'prs-crop-label');
    label.textContent = 'Zoom';
    slider = el('input', 'prs-crop-slider');
    slider.type = 'range';
    slider.min = '1';
    slider.max = '4';
    slider.step = '0.01';
    slider.value = '1';
    controls.appendChild(label);
    controls.appendChild(slider);

    // Footer
    const footer = el('div', 'prs-cover-modal__footer');
    statusEl = el('span', 'prs-cover-status');
    cancelBtn = el('button', 'prs-btn prs-btn--ghost');
    cancelBtn.textContent = 'Cancel';
    saveBtn = el('button', 'prs-btn');
    saveBtn.textContent = 'Save';
    footer.append(statusEl, cancelBtn, saveBtn);

    // Armar
    panel.append(title, topBar, wrap, controls, footer);
    modal.appendChild(panel);
    document.body.appendChild(modal);

    // Eventos
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });
    cancelBtn.addEventListener('click', closeModal);
    fileInput.addEventListener('change', onPickFile);
    slider.addEventListener('input', onZoomChange);
    saveBtn.addEventListener('click', onSave);
  }

  function closeModal() {
    if (modal) modal.remove();
    modal = null;
    imgEl = null; naturalW = naturalH = 0; scale = minScale = 1;
  }

  function setStatus(txt) { if (statusEl) statusEl.textContent = txt || ''; }

  function onPickFile(e) {
    const f = e.target.files && e.target.files[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = () => loadIntoStage(reader.result);
    reader.readAsDataURL(f);
  }

  function loadIntoStage(dataUrl) {
    stage.innerHTML = '';
    imgEl = el('img', 'prs-crop-img');
    imgEl.onload = () => {
      naturalW = imgEl.naturalWidth;
      naturalH = imgEl.naturalHeight;

      // Calcular minScale para cubrir stage (sin barras negras)
      const sX = STAGE_W / naturalW;
      const sY = STAGE_H / naturalH;
      minScale = Math.max(sX, sY);
      scale = minScale;             // iniciar centrado y a "cover"
      slider.value = '1';           // 1 => minScale
      applyTransform();
    };
    imgEl.src = dataUrl;
    stage.appendChild(imgEl);
  }

  function applyTransform() {
    if (!imgEl) return;
    // transform-origin: center; escalamos desde el centro
    imgEl.style.width = naturalW + 'px';
    imgEl.style.height = naturalH + 'px';
    imgEl.style.transformOrigin = 'center center';
    imgEl.style.transform = `translate(-50%, -50%) scale(${scale})`;
  }

  function onZoomChange() {
    // rango visual 1..4 => escala real = minScale * value
    const v = parseFloat(slider.value || '1');
    scale = minScale * v;
    applyTransform();
  }

  function onSave() {
    if (!imgEl) { setStatus('Choose an image'); return; }

    // Pintar a 240x450
    const W = (window.PRS_COVER && PRS_COVER.coverWidth)  || 240;
    const H = (window.PRS_COVER && PRS_COVER.coverHeight) || 450;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext('2d');

    // Escala real que estamos usando (natural -> stage cover)
    // minScale cubre el stage. Luego multiplicamos por slider (v).
    const v = parseFloat(slider.value || '1');
    const realScale = minScale * v;

    // Centro del stage (en coord del stage)
    const cx = STAGE_W / 2;
    const cy = STAGE_H / 2;

    // Area visible del stage en coordenadas de la imagen original:
    // El img está centrado en el stage con translate(-50%, -50%), así que:
    const viewW = STAGE_W / realScale;
    const viewH = STAGE_H / realScale;
    const sx = (naturalW / 2) - (viewW / 2);
    const sy = (naturalH / 2) - (viewH / 2);

    // Dibujar en canvas con recorte (centrado)
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(
      imgEl,
      sx, sy, viewW, viewH,  // recorte fuente
      0, 0, W, H             // destino (portada)
    );

    canvas.toBlob(async (blob) => {
      if (!blob) { setStatus('Render error'); return; }
      setStatus('Saving…');

      const dataUrl = await new Promise(res => {
        const r = new FileReader();
        r.onload = () => res(r.result);
        r.readAsDataURL(blob);
      });

      const { user_book_id, book_id } = getContext();
      const body = new URLSearchParams({
        action: 'prs_cover_save_crop',
        nonce: (window.PRS_COVER && PRS_COVER.nonce) || '',
        user_book_id, book_id,
        image: dataUrl
      });

      try {
        const resp = await fetch((window.PRS_COVER && PRS_COVER.ajax) || '', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          credentials: 'same-origin',
          body
        });
        const out = await resp.json();
        if (!out || !out.success) {
          setStatus(out?.data?.message || out?.message || 'Error');
          return;
        }
        setStatus('Saved');

        // Reemplazar la portada del front
        const src = out.data.src;
        const img = document.getElementById('prs-cover-img');
        const ph  = document.getElementById('prs-cover-placeholder');
        const frame = document.getElementById('prs-cover-frame');

        if (img) {
          img.src = src + (src.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
        } else if (frame) {
          if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
          const n = document.createElement('img');
          n.id = 'prs-cover-img';
          n.className = 'prs-cover-img';
          n.alt = '';
          n.src = src;
          frame.appendChild(n);
        }

        closeModal();
      } catch (e) {
        setStatus('Error');
        console.error('[PRS] cover save error', e);
      }
    }, 'image/jpeg', 0.9);
  }

  // Abrir modal
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#prs-cover-open');
    if (!btn) return;
    e.preventDefault();
    openModal();
  });
})();
