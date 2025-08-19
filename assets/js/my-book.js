/* global PRS_BOOK */
document.addEventListener('DOMContentLoaded', () => {
  // ====== util ======
  const NEEDS_CONTACT = new Set(['borrowed', 'borrowing', 'sold']);
  const $ = (sel) => document.querySelector(sel);

  async function postMeta(payload) {
    const body = new URLSearchParams({
      action: 'prs_update_user_book_meta',
      nonce: PRS_BOOK.nonce,
      user_book_id: PRS_BOOK.user_book_id,
      ...payload
    });
    const res = await fetch(PRS_BOOK.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });
    return res.json();
  }

  // ====== ELEMENTOS ======
  // Pages
  const $pagesView   = $('#pages-view');
  const $pagesEdit   = $('#pages-edit');
  const $pagesForm   = $('#pages-form');
  const $pagesInput  = $('#pages-input');
  const $pagesSave   = $('#pages-save');
  const $pagesCancel = $('#pages-cancel');
  const $pagesMsg    = $('#pages-status');

  // Purchase date
  const $pdView   = $('#purchase-date-view');
  const $pdEdit   = $('#purchase-date-edit');
  const $pdForm   = $('#purchase-date-form');
  const $pdInput  = $('#purchase-date-input');
  const $pdSave   = $('#purchase-date-save');
  const $pdCancel = $('#purchase-date-cancel');
  const $pdMsg    = $('#purchase-date-status');

  // Purchase channel
  const $pcView   = $('#purchase-channel-view');
  const $pcEdit   = $('#purchase-channel-edit');
  const $pcForm   = $('#purchase-channel-form');
  const $pcSelect = $('#purchase-channel-select');
  const $pcPlace  = $('#purchase-place-input');
  const $pcSave   = $('#purchase-channel-save');
  const $pcCancel = $('#purchase-channel-cancel');
  const $pcMsg    = $('#purchase-channel-status');

  // Reading status
  const $readingSelect = $('#reading-status-select');
  const $readingMsg    = $('#reading-status-status');

  // Owning + contact
  const $owningSelect = $('#owning-status-select');
  const $owningMsg    = $('#owning-status-status');
  const $returnBtn    = $('#owning-return-shelf');

  const $contactForm  = $('#owning-contact-form');
  const $contactName  = $('#owning-contact-name');
  const $contactEmail = $('#owning-contact-email');
  const $contactSave  = $('#owning-contact-save');
  const $contactMsg   = $('#owning-contact-status');
  const $contactView  = $('#owning-contact-view');

  // Derived "Location"
  const $locText = $('#derived-location-text');

  // ====== helpers ======
  const setText = (el, txt) => { if (el) el.textContent = txt; };
  const showEl = (el, show) => { if (el) el.style.display = show ? 'block' : 'none'; };
  const showInline = (el, show) => { if (el) el.style.display = show ? 'inline-block' : 'none'; };
  const isValidEmail = (e) => !e || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);

  function hasContact() {
    if (typeof PRS_BOOK !== 'undefined' && 'has_contact' in PRS_BOOK) return !!Number(PRS_BOOK.has_contact);
    const n = ($contactName?.value || '').trim();
    const e = ($contactEmail?.value || '').trim();
    const v = ($contactView?.textContent || '').trim();
    return !!(n || e || v);
  }

  function setLocationDerived(val) {
    const inShelf = (!val || val === 'borrowing'); // default/'' or borrowing => contigo
    setText($locText, inShelf ? 'In Shelf' : 'Not In Shelf');
  }

  function toggleContactVisibility(val, fromChange = false) {
    if (!$contactForm) return;
    const need = NEEDS_CONTACT.has(val);
    if (fromChange) showEl($contactForm, need);
    else showEl($contactForm, need && !hasContact());
    // al mostrar, aplicar estado del botón
    if (need) updateContactSaveState();
  }

  function toggleReturnBtn(val) {
    if (!$returnBtn) return;
    $returnBtn.style.display = (val === 'borrowed' || val === 'borrowing') ? 'inline-block' : 'none';
  }

  // ====== Pages ======
  $pagesEdit?.addEventListener('click', (e) => {
    e.preventDefault();
    showInline($pagesForm, true);
    showInline($pagesEdit, false);
    $pagesInput?.focus();
  });
  $pagesCancel?.addEventListener('click', () => {
    showInline($pagesForm, false);
    showInline($pagesEdit, true);
    setText($pagesMsg, '');
  });
  $pagesSave?.addEventListener('click', async () => {
    const v = parseInt($pagesInput?.value || '', 10);
    setText($pagesMsg, 'Saving…');
    try {
      const out = await postMeta({ pages: isFinite(v) && v > 0 ? v : '' });
      if (out?.success) {
        setText($pagesView, isFinite(v) && v > 0 ? String(v) : '—');
        setText($pagesMsg, 'Saved');
        showInline($pagesForm, false);
        showInline($pagesEdit, true);
      } else setText($pagesMsg, out?.message || 'Error');
    } catch { setText($pagesMsg, 'Error'); }
  });

  // ====== Purchase date ======
  $pdEdit?.addEventListener('click', (e) => {
    e.preventDefault();
    showInline($pdForm, true);
    showInline($pdEdit, false);
    $pdInput?.focus();
  });
  $pdCancel?.addEventListener('click', () => {
    showInline($pdForm, false);
    showInline($pdEdit, true);
    setText($pdMsg, '');
  });
  $pdSave?.addEventListener('click', async () => {
    const v = ($pdInput?.value || '').trim();
    setText($pdMsg, 'Saving…');
    try {
      const out = await postMeta({ purchase_date: v });
      if (out?.success) {
        setText($pdView, v || '—');
        setText($pdMsg, 'Saved');
        showInline($pdForm, false);
        showInline($pdEdit, true);
      } else setText($pdMsg, out?.message || 'Error');
    } catch { setText($pdMsg, 'Error'); }
  });

  // ====== Purchase channel ======
  $pcEdit?.addEventListener('click', (e) => {
    e.preventDefault();
    showInline($pcForm, true);
    showInline($pcEdit, false);
  });
  $pcCancel?.addEventListener('click', () => {
    showInline($pcForm, false);
    showInline($pcEdit, true);
    setText($pcMsg, '');
  });
  $pcSelect?.addEventListener('change', () => {
    showInline($pcPlace, !!($pcSelect?.value || '').trim());
  });
  $pcSave?.addEventListener('click', async () => {
    const ch = ($pcSelect?.value || '').trim();
    const pl = ($pcPlace?.value || '').trim();
    setText($pcMsg, 'Saving…');
    try {
      const out = await postMeta({ purchase_channel: ch, purchase_place: pl });
      if (out?.success) {
        const label = ch ? (ch.charAt(0).toUpperCase() + ch.slice(1)) + (pl ? ' — ' + pl : '') : '—';
        setText($pcView, label);
        setText($pcMsg, 'Saved');
        showInline($pcForm, false);
        showInline($pcEdit, true);
      } else setText($pcMsg, out?.message || 'Error');
    } catch { setText($pcMsg, 'Error'); }
  });

  // ====== Reading status ======
  $readingSelect?.addEventListener('change', async () => {
    const val = $readingSelect.value;
    setText($readingMsg, 'Saving…');
    try {
      const out = await postMeta({ reading_status: val });
      setText($readingMsg, out?.success ? 'Saved' : (out?.message || 'Error'));
    } catch { setText($readingMsg, 'Error'); }
  });

  // ====== Owning + contact ======
  function setStatusUI(val, fromChange) {
    setLocationDerived(val);
    toggleContactVisibility(val, fromChange);
    toggleReturnBtn(val);
  }

  $owningSelect?.addEventListener('change', async () => {
    const val = ($owningSelect.value || '').trim();
    setText($owningMsg, 'Saving…');
    try {
      const out = await postMeta({ owning_status: val });
      if (out?.success) {
        setText($owningMsg, 'Saved');
        setStatusUI(val, true);
        if (!NEEDS_CONTACT.has(val)) showEl($contactForm, false);
  
        // NEW: if returned to shelf, clear contact line
        if (val === '') {
          if ($contactView)  $contactView.textContent = '';
          if (typeof PRS_BOOK !== 'undefined') PRS_BOOK.has_contact = 0;
        }
      } else {
        setText($owningMsg, out?.message || 'Error');
      }
    } catch {
      setText($owningMsg, 'Error');
    }
  });  

  // Return to shelf (owning_status = '')
  $returnBtn?.addEventListener('click', async () => {
    setText($owningMsg, 'Saving…');
    try {
      const out = await postMeta({ owning_status: '' });
      if (out?.success) {
        if ($owningSelect) $owningSelect.value = '';
        setText($owningMsg, 'Saved');
        setStatusUI('', true);
        showEl($contactForm, false);
  
        // NEW: clear contact view + inputs + memory
        if ($contactView)  $contactView.textContent = '';
        if ($contactName)  $contactName.value = '';
        if ($contactEmail) $contactEmail.value = '';
        if (typeof PRS_BOOK !== 'undefined') PRS_BOOK.has_contact = 0;
      } else {
        setText($owningMsg, out?.message || 'Error');
      }
    } catch {
      setText($owningMsg, 'Error');
    }
  });  

  // ---- Contact validation ----
  function updateContactSaveState() {
    if (!$contactSave) return;
    const name  = ($contactName?.value || '').trim();
    const email = ($contactEmail?.value || '').trim();
    const enabled = (name || email) && isValidEmail(email);
    $contactSave.disabled = !enabled;
  }
  [$contactName, $contactEmail].forEach($el => {
    $el?.addEventListener('input', updateContactSaveState);
  });

  // Guardar contacto
  async function saveContact() {
    const name  = ($contactName?.value || '').trim();
    const email = ($contactEmail?.value || '').trim();

    // bloqueo front: requiere nombre o email, y email válido si viene
    if (!name && !email) {
      setText($contactMsg, 'Enter a name or an email');
      return;
    }
    if (!isValidEmail(email)) {
      setText($contactMsg, 'Invalid email');
      return;
    }

    setText($contactMsg, 'Saving…');
    $contactSave?.setAttribute('disabled', 'disabled');
    try {
      const out = await postMeta({ counterparty_name: name, counterparty_email: email });
      if (out?.success) {
        const parts = [];
        if (name) parts.push(name);
        if (email) parts.push(email);
        if ($contactView) $contactView.textContent = parts.join(' · ');
        if (typeof PRS_BOOK !== 'undefined') PRS_BOOK.has_contact = 1;
        showEl($contactForm, false);
        setText($contactMsg, 'Saved');
      } else {
        setText($contactMsg, out?.message || 'Error');
      }
    } catch {
      setText($contactMsg, 'Error');
    } finally {
      $contactSave?.removeAttribute('disabled');
      updateContactSaveState();
    }
  }
  $contactSave?.addEventListener('click', saveContact);
  [$contactName, $contactEmail].forEach($el => $el?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); saveContact(); }
  }));

  // ====== Estado inicial ======
  const initialStatus = (PRS_BOOK.owning_status || '').trim();
  setStatusUI(initialStatus, false);
  showInline($pcPlace, !!($pcSelect?.value || '').trim());
  updateContactSaveState(); // inicia Save deshabilitado si corresponde
});
