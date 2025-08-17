(function () {
  if (typeof PRS_BOOK === 'undefined') return;

  const ajax = (payload) =>
    fetch(PRS_BOOK.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(
        Object.assign(
          {
            action: 'prs_update_user_book_meta',
            nonce: PRS_BOOK.nonce,
            user_book_id: String(PRS_BOOK.user_book_id),
          },
          payload
        )
      ),
    }).then((r) => r.json());

  const q = (sel) => document.querySelector(sel);

  const needsContact = (value) =>
    ['borrowed', 'borrowing', 'sold'].indexOf(String(value || '')) !== -1;

  const fmtServerDateOrLocal = (res) => {
    if (res && res.success && res.data && res.data.loan_start_date) {
      return res.data.loan_start_date;
    }
    try {
      return new Date().toLocaleDateString();
    } catch (_) {
      return '';
    }
  };

  /* ------------------------------- Pages ------------------------------- */
  (function () {
    const view = q('#pages-view');
    const edit = q('#pages-edit');
    const form = q('#pages-form');
    const input = q('#pages-input');
    const save = q('#pages-save');
    const cancel = q('#pages-cancel');
    const status = q('#pages-status');
    if (!view || !edit) return;

    const show = (on) => {
      form.style.display = on ? 'inline-block' : 'none';
      edit.style.display = on ? 'none' : 'inline';
    };

    edit.addEventListener('click', (e) => {
      e.preventDefault();
      show(true);
      input && input.focus();
    });

    cancel.addEventListener('click', () => {
      show(false);
      status.textContent = '';
    });

    save.addEventListener('click', async () => {
      const pages = parseInt(input.value, 10);
      if (!pages || pages < 1) {
        status.textContent = 'Enter a positive number';
        return;
      }
      status.textContent = 'Saving...';
      const res = await ajax({ pages });
      if (res && res.success) {
        view.textContent = String(res.data.pages || '—');
        status.textContent = 'Saved ✓';
        setTimeout(() => {
          status.textContent = '';
          show(false);
        }, 600);
      } else {
        status.textContent = 'Error';
      }
    });
  })();

  /* --------------------------- Purchase Date --------------------------- */
  (function () {
    const v = q('#purchase-date-view');
    const e = q('#purchase-date-edit');
    const f = q('#purchase-date-form');
    const i = q('#purchase-date-input');
    const s = q('#purchase-date-save');
    const c = q('#purchase-date-cancel');
    const st = q('#purchase-date-status');
    if (!e) return;

    const show = (on) => {
      f.style.display = on ? 'inline-block' : 'none';
      e.style.display = on ? 'none' : 'inline';
    };

    e.addEventListener('click', (ev) => {
      ev.preventDefault();
      show(true);
      i && i.focus();
    });

    c.addEventListener('click', () => {
      show(false);
      st.textContent = '';
    });

    s.addEventListener('click', async () => {
      const purchase_date = i.value || '';
      st.textContent = 'Saving...';
      const res = await ajax({ purchase_date });
      if (res && res.success) {
        v.textContent = res.data.purchase_date || '—';
        st.textContent = 'Saved ✓';
        setTimeout(() => {
          st.textContent = '';
          show(false);
        }, 600);
      } else {
        st.textContent = 'Error';
      }
    });
  })();

  /* -------------------- Purchase Channel + Which? ---------------------- */
  (function () {
    const v = q('#purchase-channel-view');
    const e = q('#purchase-channel-edit');
    const f = q('#purchase-channel-form');
    const sel = q('#purchase-channel-select');
    const plc = q('#purchase-place-input');
    const s = q('#purchase-channel-save');
    const c = q('#purchase-channel-cancel');
    const st = q('#purchase-channel-status');
    if (!e) return;

    const show = (on) => {
      f.style.display = on ? 'inline-block' : 'none';
      e.style.display = on ? 'none' : 'inline';
    };

    e.addEventListener('click', (ev) => {
      ev.preventDefault();
      show(true);
      (sel.value ? plc : sel).focus();
    });

    c.addEventListener('click', () => {
      show(false);
      st.textContent = '';
    });

    sel && sel.addEventListener('change', () => {
      if (!plc) return;
      plc.style.display = sel.value ? 'inline-block' : 'none';
      if (sel.value) plc.focus();
    });

    s.addEventListener('click', async () => {
      const purchase_channel = sel.value || '';
      const purchase_place = plc ? plc.value || '' : '';
      st.textContent = 'Saving...';
      const res = await ajax({ purchase_channel, purchase_place });
      if (res && res.success) {
        let label = '—';
        if (res.data.purchase_channel) {
          label =
            res.data.purchase_channel.charAt(0).toUpperCase() +
            res.data.purchase_channel.slice(1);
          if (res.data.purchase_place) label += ' — ' + res.data.purchase_place;
        }
        v.textContent = label;
        st.textContent = 'Saved ✓';
        setTimeout(() => {
          st.textContent = '';
          show(false);
        }, 600);
      } else {
        st.textContent = 'Error';
      }
    });
  })();

  /* ---------------- Reading / Owning Status selects -------------------- */
  (function () {
    const rs = q('#reading-status-select');
    const os = q('#owning-status-select');
    const rss = q('#reading-status-status');
    const oss = q('#owning-status-status');

    const contactForm = q('#owning-contact-form');
    const contactName = q('#owning-contact-name');
    const contactEmail = q('#owning-contact-email');
    const contactSave = q('#owning-contact-save');
    const contactStatus = q('#owning-contact-status');
    const contactView = q('#owning-contact-view');

    const toggleContact = (value) => {
      if (!contactForm) return;
      contactForm.style.display = needsContact(value) ? 'block' : 'none';
      if (!needsContact(value) && contactView) {
        contactView.textContent = '';
      }
    };

    if (rs) {
      rs.addEventListener('change', async () => {
        rss.textContent = 'Saving...';
        const res = await ajax({ reading_status: rs.value });
        rss.textContent = res && res.success ? 'Saved ✓' : 'Error';
        setTimeout(() => {
          rss.textContent = '';
        }, 700);
      });
    }

    if (os) {
      os.addEventListener('change', async () => {
        oss.textContent = 'Saving...';
        const value = os.value;
        const res = await ajax({ owning_status: value });
        oss.textContent = res && res.success ? 'Saved ✓' : 'Error';
        setTimeout(() => {
          oss.textContent = '';
        }, 700);

        toggleContact(value);

        if (needsContact(value) && contactView) {
          const dateLabel = fmtServerDateOrLocal(res);
          if (!contactView.textContent || !contactView.textContent.includes(dateLabel)) {
            const base = contactView.textContent.trim();
            contactView.textContent = base ? base + ' · ' + dateLabel : dateLabel;
          }
        }
      });
    }

    if (contactSave) {
      contactSave.addEventListener('click', async () => {
        const payload = {
          counterparty_name: contactName ? contactName.value || '' : '',
          counterparty_email: contactEmail ? contactEmail.value || '' : '',
          owning_effective_date: new Date().toISOString().slice(0, 10), // 👈 ESTA LÍNEA ES LA CLAVE
        };
        contactStatus.textContent = 'Saving...';
        const res = await ajax(payload);
        if (res && res.success) {
          let txt = '';
          if (payload.counterparty_name) txt += payload.counterparty_name;
          if (payload.counterparty_email) {
            txt += (txt ? ' · ' : '') + payload.counterparty_email;
          }
          const dateLabel = fmtServerDateOrLocal(res);
          if (dateLabel) txt += (txt ? ' · ' : '') + dateLabel;
          if (contactView) contactView.textContent = txt;
          contactStatus.textContent = 'Saved ✓';
          setTimeout(() => {
            contactStatus.textContent = '';
          }, 700);
        } else {
          contactStatus.textContent = 'Error';
        }
      });
    }
  })();
})();


/* global PRS_BOOK */
document.addEventListener('DOMContentLoaded', () => {
  const NEEDS = new Set(['borrowed','borrowing','sold']);

  const $status  = document.getElementById('owning-status-select');
  const $form    = document.getElementById('owning-contact-form');
  const $name    = document.getElementById('owning-contact-name');
  const $email   = document.getElementById('owning-contact-email');
  const $saveBtn = document.getElementById('owning-contact-save');
  const $msg     = document.getElementById('owning-contact-status');
  const $view    = document.getElementById('owning-contact-view');

  // --- helpers ---
  function showForm(show) {
    if (!$form) return;
    $form.style.display = show ? 'block' : 'none';
    $form.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function setMsg(text) { if ($msg) $msg.textContent = text || ''; }

  // ¿Ya hay contacto guardado?
  function hasContact() {
    if (typeof PRS_BOOK !== 'undefined' && 'has_contact' in PRS_BOOK) {
      return !!Number(PRS_BOOK.has_contact);
    }
    // Fallback: deduce por los inputs/visualización
    const n = ($name?.value || '').trim();
    const e = ($email?.value || '').trim();
    const v = ($view?.textContent || '').trim();
    return !!(n || e || v);
  }

  // Guarda un campo simple
  async function saveField(field, value) {
    const body = new URLSearchParams({
      action: 'prs_update_user_book_meta',
      nonce: PRS_BOOK.nonce,
      user_book_id: PRS_BOOK.user_book_id,
      field,
      value
    });
    const res = await fetch(PRS_BOOK.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body
    });
    return res.json();
  }

  // Guarda contacto (nombre + email)
  async function saveContact(name, email) {
    const body = new URLSearchParams({
      action: 'prs_update_user_book_meta',
      nonce: PRS_BOOK.nonce,
      user_book_id: PRS_BOOK.user_book_id,
      field: 'contact',
      counterparty_name: name,
      counterparty_email: email
    });
    const res = await fetch(PRS_BOOK.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body
    });
    return res.json();
  }

  // Muestra/oculta según estado.
  // fromChange=true => el usuario cambió el dropdown: siempre mostrar si requiere contacto (para editar/crear).
  // fromChange=false => carga inicial: mostrar sólo si requiere contacto Y NO hay contacto guardado.
  function toggleByStatus(fromChange = false) {
    const v = ($status?.value || '').trim();
    if (fromChange) {
      showForm(NEEDS.has(v));
    } else {
      showForm(NEEDS.has(v) && !hasContact());
    }
  }

  // --- eventos ---
  if ($status) {
    $status.addEventListener('change', async () => {
      const val = $status.value;
      setMsg('Saving…');
      try {
        const out = await saveField('owning_status', val);
        if (out?.success) {
          setMsg('Saved');
          // Si no requiere contacto, oculta; si requiere, muéstralo (para crear/editar)
          toggleByStatus(true);
          if (!NEEDS.has(val)) showForm(false);
        } else {
          setMsg(out?.message || 'Error');
        }
      } catch {
        setMsg('Error');
      }
    });
  }

  if ($saveBtn) {
    // Guardar con click
    $saveBtn.addEventListener('click', onSaveContact);
    // Guardar con Enter en inputs
    [$name, $email].forEach($el => {
      $el?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          onSaveContact();
        }
      });
    });
  }

  async function onSaveContact() {
    const name  = ($name?.value || '').trim();
    const email = ($email?.value || '').trim();
    setMsg('Saving…');
    $saveBtn?.setAttribute('disabled', 'disabled');
    try {
      const out = await saveContact(name, email);
      if (out?.success) {
        // Actualiza vista y marca que ya hay contacto
        const parts = [];
        if (name) parts.push(name);
        if (email) parts.push(email);
        if ($view) $view.textContent = parts.join(' · ');

        if (typeof PRS_BOOK !== 'undefined') PRS_BOOK.has_contact = 1;

        showForm(false);
        setMsg('Saved');
      } else {
        setMsg(out?.message || 'Error');
      }
    } catch {
      setMsg('Error');
    } finally {
      $saveBtn?.removeAttribute('disabled');
    }
  }

  // Estado inicial
  toggleByStatus(false);
});
