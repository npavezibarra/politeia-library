(function () {
  if (typeof PRS_BOOK === 'undefined') return;

  /* -------------------- helpers -------------------- */
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

  const show = (el, on) => {
    if (!el) return;
    el.style.display = on ? 'inline-block' : 'none';
  };

  const setText = (el, txt) => {
    if (el) el.textContent = txt;
  };

  const needsContact = (v) =>
    v === 'borrowed' || v === 'borrowing' || v === 'sold';

  /* -------------------- Pages inline -------------------- */
  (function () {
    const view = document.getElementById('pages-view');
    const edit = document.getElementById('pages-edit');
    const form = document.getElementById('pages-form');
    const input = document.getElementById('pages-input');
    const save = document.getElementById('pages-save');
    const cancel = document.getElementById('pages-cancel');
    const status = document.getElementById('pages-status');
    if (!view || !edit || !form) return;

    const toggle = (f) => {
      show(form, f);
      edit.style.display = f ? 'none' : 'inline';
      if (f && input) input.focus();
    };

    edit.addEventListener('click', (e) => {
      e.preventDefault();
      toggle(true);
    });

    cancel.addEventListener('click', () => {
      toggle(false);
      setText(status, '');
    });

    save.addEventListener('click', async () => {
      const pages = parseInt(input.value, 10);
      if (!pages || pages < 1) {
        setText(status, 'Enter a positive number');
        return;
      }
      setText(status, 'Saving...');
      const res = await ajax({ pages });
      if (res && res.success) {
        setText(view, String(res.data.pages || '—'));
        setText(status, 'Saved ✓');
        setTimeout(() => {
          setText(status, '');
          toggle(false);
        }, 600);
      } else {
        setText(status, 'Error');
      }
    });

    // Enter/ESC accesibilidad
    input &&
      input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          save.click();
        } else if (ev.key === 'Escape') {
          ev.preventDefault();
          cancel.click();
        }
      });
  })();

  /* -------------------- Purchase Date -------------------- */
  (function () {
    const v = document.getElementById('purchase-date-view');
    const e = document.getElementById('purchase-date-edit');
    const f = document.getElementById('purchase-date-form');
    const i = document.getElementById('purchase-date-input');
    const s = document.getElementById('purchase-date-save');
    const c = document.getElementById('purchase-date-cancel');
    const st = document.getElementById('purchase-date-status');
    if (!e || !f) return;

    const toggle = (on) => {
      show(f, on);
      e.style.display = on ? 'none' : 'inline';
      if (on && i) i.focus();
    };

    e.addEventListener('click', (ev) => {
      ev.preventDefault();
      toggle(true);
    });

    c.addEventListener('click', () => {
      toggle(false);
      setText(st, '');
    });

    s.addEventListener('click', async () => {
      const d = i.value; // YYYY-MM-DD
      setText(st, 'Saving...');
      const res = await ajax({ purchase_date: d });
      if (res && res.success) {
        setText(v, res.data.purchase_date || '—');
        setText(st, 'Saved ✓');
        setTimeout(() => {
          setText(st, '');
          toggle(false);
        }, 600);
      } else {
        setText(st, 'Error');
      }
    });

    i &&
      i.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          s.click();
        } else if (ev.key === 'Escape') {
          ev.preventDefault();
          c.click();
        }
      });
  })();

  /* ------------- Purchase Channel + Place (Which?) ------------- */
  (function () {
    const v = document.getElementById('purchase-channel-view');
    const e = document.getElementById('purchase-channel-edit');
    const f = document.getElementById('purchase-channel-form');
    const sel = document.getElementById('purchase-channel-select');
    const plc = document.getElementById('purchase-place-input');
    const s = document.getElementById('purchase-channel-save');
    const c = document.getElementById('purchase-channel-cancel');
    const st = document.getElementById('purchase-channel-status');
    if (!e || !f) return;

    const toggle = (on) => {
      show(f, on);
      e.style.display = on ? 'none' : 'inline';
      if (on) (sel && sel.value ? plc : sel).focus();
      togglePlaceField();
    };

    const togglePlaceField = () => {
      // “Which?” aparece solo si hay canal seleccionado
      if (!plc) return;
      plc.style.display = sel && sel.value ? 'inline-block' : 'none';
    };

    e.addEventListener('click', (ev) => {
      ev.preventDefault();
      toggle(true);
    });

    c.addEventListener('click', () => {
      toggle(false);
      setText(st, '');
    });

    sel && sel.addEventListener('change', togglePlaceField);

    s.addEventListener('click', async () => {
      const ch = sel ? sel.value || '' : '';
      const wh = plc ? plc.value || '' : '';
      setText(st, 'Saving...');
      const res = await ajax({ purchase_channel: ch, purchase_place: wh });
      if (res && res.success) {
        let label = res.data.purchase_channel
          ? res.data.purchase_channel.charAt(0).toUpperCase() +
            res.data.purchase_channel.slice(1)
          : '—';
        if (res.data.purchase_channel && (res.data.purchase_place || '').length) {
          label += ' — ' + res.data.purchase_place;
        }
        setText(v, label);
        setText(st, 'Saved ✓');
        setTimeout(() => {
          setText(st, '');
          toggle(false);
        }, 600);
      } else {
        setText(st, 'Error');
      }
    });
  })();

  /* ------------- Reading / Owning + Contact (Name/Email) ------------- */
  (function () {
    const rs = document.getElementById('reading-status-select');
    const os = document.getElementById('owning-status-select');
    const rss = document.getElementById('reading-status-status');
    const oss = document.getElementById('owning-status-status');

    const contactWrap = document.getElementById('owning-contact-form');
    const contactName = document.getElementById('owning-contact-name');
    const contactEmail = document.getElementById('owning-contact-email');
    const contactSave = document.getElementById('owning-contact-save');
    const contactStatus = document.getElementById('owning-contact-status');
    const contactView = document.getElementById('owning-contact-view');

    const toggleContact = (v) => {
      if (!contactWrap) return;
      contactWrap.style.display = needsContact(v) ? 'block' : 'none';
    };

    if (rs) {
      rs.addEventListener('change', async () => {
        setText(rss, 'Saving...');
        const res = await ajax({ reading_status: rs.value });
        setText(rss, res && res.success ? 'Saved ✓' : 'Error');
        setTimeout(() => setText(rss, ''), 700);
      });
    }

    if (os) {
      // inicial
      toggleContact(os.value);

      os.addEventListener('change', async () => {
        const value = os.value;
        setText(oss, 'Saving...');
        const res = await ajax({ owning_status: value });
        setText(oss, res && res.success ? 'Saved ✓' : 'Error');
        setTimeout(() => setText(oss, ''), 700);

        toggleContact(value);
        // Si cambia a un estado que no requiere contacto, limpiar la vista
        if (!needsContact(value) && contactView) {
          setText(contactView, '');
        }
      });
    }

    if (contactSave) {
      contactSave.addEventListener('click', async () => {
        setText(contactStatus, 'Saving...');
        const payload = {
          counterparty_name: contactName ? contactName.value || '' : '',
          counterparty_email: contactEmail ? contactEmail.value || '' : '',
        };
        const res = await ajax(payload);
        if (res && res.success) {
          let txt = '';
          if (payload.counterparty_name) txt += payload.counterparty_name;
          if (payload.counterparty_email)
            txt += (txt ? ' · ' : '') + payload.counterparty_email;
          if (contactView) setText(contactView, txt);
          setText(contactStatus, 'Saved ✓');
          setTimeout(() => setText(contactStatus, ''), 700);
        } else {
          setText(contactStatus, 'Error');
        }
      });
    }
  })();
})();
