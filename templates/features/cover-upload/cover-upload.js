(() => {
  const $ = (s, r=document) => r.querySelector(s);

  function openModal(){ const m=$('#prs-cu-modal'); if(!m) return; m.hidden=false; document.body.classList.add('prs-cu-lock'); }
  function closeModal(){ const m=$('#prs-cu-modal'); if(!m) return; m.hidden=true; document.body.classList.remove('prs-cu-lock'); }

  let imgEl, stageEl, zoomEl, fileEl, saveBtn;
  let naturalW=0, naturalH=0, baseScale=1, currentScale=1;

  const TARGET_W=240, TARGET_H=450;

  function stageSize(){ const r=stageEl.getBoundingClientRect(); return {w:r.width, h:r.height}; }

  // Centra y calcula la escala mínima para "cubrir" el stage (cover)
  function fitCenter(){
    const {w:sw,h:sh}=stageSize(); if(!sw||!sh||!naturalW||!naturalH) return;
    baseScale=Math.max(sw/naturalW, sh/naturalH);
    currentScale=baseScale;

    imgEl.style.width = naturalW+'px';
    imgEl.style.height= naturalH+'px';
    imgEl.style.transform=`translate(-50%,-50%) scale(${currentScale})`;

    zoomEl.min   = baseScale.toFixed(4);
    zoomEl.max   = (baseScale*3).toFixed(4);
    zoomEl.step  = '0.01';
    zoomEl.value = baseScale.toFixed(4);
  }

  function onZoom(){
    const v=parseFloat(zoomEl.value||baseScale);
    currentScale = (isFinite(v) && v>=baseScale) ? v : baseScale;
    imgEl.style.transform=`translate(-50%,-50%) scale(${currentScale})`;
  }

  function readFile(file){
    return new Promise((res,rej)=>{
      const rdr=new FileReader();
      rdr.onload=()=>res(rdr.result);
      rdr.onerror=()=>rej(rdr.error);
      rdr.readAsDataURL(file);
    });
  }

  async function onFile(e){
    const f=e.target.files && e.target.files[0];
    if(!f) return;
    const url=await readFile(f);
    imgEl.onload=()=>{ naturalW=imgEl.naturalWidth; naturalH=imgEl.naturalHeight; fitCenter(); };
    imgEl.src=url;
  }

  // Calcula el recorte visible (ventana del stage) y exporta canvas 240x450
  function renderBlob(){
    const {w:sw,h:sh}=stageSize();
    const sx=Math.max(0,(naturalW - sw/currentScale)/2);
    const sy=Math.max(0,(naturalH - sh/currentScale)/2);
    const sW=Math.min(naturalW, sw/currentScale);
    const sH=Math.min(naturalH, sh/currentScale);

    const cv=document.createElement('canvas');
    cv.width=TARGET_W; cv.height=TARGET_H;
    const ctx=cv.getContext('2d');
    ctx.imageSmoothingQuality='high';
    ctx.drawImage(imgEl, sx, sy, sW, sH, 0, 0, TARGET_W, TARGET_H);
    return new Promise(r=>cv.toBlob(b=>r(b),'image/jpeg',0.85));
  }

  async function save(){
    if(!window.PRS_BOOK || !PRS_BOOK.user_book_id) return;
    saveBtn.disabled=true;
    try{
      const blob=await renderBlob();
      const fd=new FormData();
      fd.append('action','prs_cover_save_crop');
      fd.append('nonce', (window.PRS_COVER&&PRS_COVER.nonce)||'');
      fd.append('user_book_id', PRS_BOOK.user_book_id);
      fd.append('file', blob, 'cover-'+Date.now()+'.jpg');

      const res=await fetch(PRS_COVER.ajax_url,{method:'POST',body:fd,credentials:'same-origin'});
      const out=await res.json();
      if(!out?.success) throw new Error(out?.data?.message||out?.message||'upload_fail');

      const url=(out.data.src||'') + ((out.data.src||'').includes('?')?'&':'?') + 't='+Date.now();
      const frame=document.getElementById('prs-cover-frame');
      if(frame){
        let img=document.getElementById('prs-cover-img');
        const ph=document.getElementById('prs-cover-placeholder');
        if(ph&&ph.parentNode) ph.parentNode.removeChild(ph);
        if(!img){ img=document.createElement('img'); img.id='prs-cover-img'; img.className='prs-cover-img'; frame.appendChild(img); }
        img.src=url; frame.classList.add('has-image');
      }
      closeModal();
    }catch(err){
      console.error('[PRS] cover save error', err);
      alert('Error saving cover.');
    }finally{
      saveBtn.disabled=false;
    }
  }

  document.addEventListener('DOMContentLoaded',()=>{
    const openBtn=document.getElementById('prs-cover-upload-btn');
    if(openBtn) openBtn.addEventListener('click', openModal);

    const modal=document.getElementById('prs-cu-modal');
    if(!modal) return;

    imgEl   = document.getElementById('prs-cu-img');
    stageEl = document.getElementById('prs-cu-stage');
    zoomEl  = document.getElementById('prs-cu-zoom');
    fileEl  = document.getElementById('prs-cu-file');
    saveBtn = document.getElementById('prs-cu-save');

    modal.addEventListener('click', (e)=>{ if(e.target.matches('[data-close]')||e.target===modal) closeModal(); });
    window.addEventListener('resize', ()=>{ if(!modal.hidden && naturalW&&naturalH) fitCenter(); });

    fileEl.addEventListener('change', onFile);
    zoomEl.addEventListener('input', onZoom);
    saveBtn.addEventListener('click', save);
  });
})();
