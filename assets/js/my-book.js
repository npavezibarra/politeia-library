/* global PRS_BOOK, PRS_SESS */

/**
 * Utilidades
 */
(function () {
  "use strict";

  // ---------- Helpers ----------
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function setText(el, txt) { if (el) el.textContent = txt; }
  function show(el) { if (el) el.style.display = ""; }
  function hide(el) { if (el) el.style.display = "none"; }

  function setStatus(el, msg, ok = true, ttl = 2000) {
    if (!el) return;
    el.textContent = msg || "";
    el.style.color = ok ? "#2f6b2f" : "#b00020";
    if (ttl > 0) {
      setTimeout(() => { el.textContent = ""; }, ttl);
    }
  }

  function ajaxPost(url, data) {
    return fetch(url, {
      method: "POST",
      body: data,
      credentials: "same-origin",
    }).then(r => r.json());
  }

  function num(val, defVal = 0) {
    const n = parseInt(val, 10);
    return Number.isFinite(n) ? n : defVal;
  }

  // ---------- Edición: Pages ----------
  function setupPages() {
    const wrap = qs("#fld-pages");
    if (!wrap || !window.PRS_BOOK) return;

    const view = qs("#pages-view", wrap);
    const editBtn = qs("#pages-edit", wrap);
    const form = qs("#pages-form", wrap);
    const input = qs("#pages-input", wrap);
    const saveBtn = qs("#pages-save", wrap);
    const cancelBtn = qs("#pages-cancel", wrap);
    const status = qs("#pages-status", wrap);

    if (editBtn) editBtn.addEventListener("click", (e) => {
      e.preventDefault();
      hide(editBtn);
      show(form);
      input.focus();
      input.select();
    });

    if (cancelBtn) cancelBtn.addEventListener("click", () => {
      show(editBtn);
      hide(form);
      setStatus(status, "", true, 0);
    });

    if (saveBtn) saveBtn.addEventListener("click", () => {
      const pages = Math.max(0, num(input.value, 0));
      const fd = new FormData();
      fd.append("action", "prs_update_user_book_meta");
      fd.append("nonce", PRS_BOOK.nonce);
      fd.append("user_book_id", String(PRS_BOOK.user_book_id));
      fd.append("pages", String(pages));

      ajaxPost(PRS_BOOK.ajax_url, fd)
        .then(json => {
          if (!json || !json.success) throw json;
          setText(view, pages > 0 ? String(pages) : "—");
          setStatus(status, "Saved.", true);
          // cerrar editor
          show(editBtn);
          hide(form);
        })
        .catch(err => {
          const msg = (err && err.data && err.data.message) ? err.data.message : "Error saving.";
          setStatus(status, msg, false, 4000);
        });
    });
  }

  // ---------- Edición: Purchase Date ----------
  function setupPurchaseDate() {
    const wrap = qs("#fld-purchase-date");
    if (!wrap || !window.PRS_BOOK) return;

    const view = qs("#purchase-date-view", wrap);
    const editBtn = qs("#purchase-date-edit", wrap);
    const form = qs("#purchase-date-form", wrap);
    const input = qs("#purchase-date-input", wrap);
    const saveBtn = qs("#purchase-date-save", wrap);
    const cancelBtn = qs("#purchase-date-cancel", wrap);
    const status = qs("#purchase-date-status", wrap);

    if (editBtn) editBtn.addEventListener("click", (e) => {
      e.preventDefault();
      hide(editBtn);
      show(form);
      input.showPicker && input.showPicker();
    });

    if (cancelBtn) cancelBtn.addEventListener("click", () => {
      show(editBtn);
      hide(form);
      setStatus(status, "", true, 0);
    });

    if (saveBtn) saveBtn.addEventListener("click", () => {
      const dateVal = (input.value || "").trim(); // YYYY-MM-DD or empty
      const fd = new FormData();
      fd.append("action", "prs_update_user_book_meta");
      fd.append("nonce", PRS_BOOK.nonce);
      fd.append("user_book_id", String(PRS_BOOK.user_book_id));
      fd.append("purchase_date", dateVal);

      ajaxPost(PRS_BOOK.ajax_url, fd)
        .then(json => {
          if (!json || !json.success) throw json;
          setText(view, dateVal ? dateVal : "—");
          setStatus(status, "Saved.", true);
          show(editBtn);
          hide(form);
        })
        .catch(err => {
          const msg = (err && err.data && err.data.message) ? err.data.message : "Error saving date.";
          setStatus(status, msg, false, 4000);
        });
    });
  }

  // ---------- Edición: Purchase Channel + Place ----------
  function setupPurchaseChannel() {
    const wrap = qs("#fld-purchase-channel");
    if (!wrap || !window.PRS_BOOK) return;

    const view = qs("#purchase-channel-view", wrap);
    const editBtn = qs("#purchase-channel-edit", wrap);
    const form = qs("#purchase-channel-form", wrap);
    const select = qs("#purchase-channel-select", wrap);
    const place = qs("#purchase-place-input", wrap);
    const saveBtn = qs("#purchase-channel-save", wrap);
    const cancelBtn = qs("#purchase-channel-cancel", wrap);
    const status = qs("#purchase-channel-status", wrap);

    function adjustPlaceVisibility() {
      if (!place) return;
      const v = (select.value || "").trim();
      place.style.display = v ? "inline-block" : "none";
    }

    if (editBtn) editBtn.addEventListener("click", (e) => {
      e.preventDefault();
      hide(editBtn);
      show(form);
      adjustPlaceVisibility();
      select.focus();
    });

    if (cancelBtn) cancelBtn.addEventListener("click", () => {
      show(editBtn);
      hide(form);
      setStatus(status, "", true, 0);
    });

    if (select) select.addEventListener("change", adjustPlaceVisibility);

    if (saveBtn) saveBtn.addEventListener("click", () => {
      const channel = (select.value || "").trim(); // "online" | "store" | ""
      const placeVal = (place && place.value || "").trim();

      const fd = new FormData();
      fd.append("action", "prs_update_user_book_meta");
      fd.append("nonce", PRS_BOOK.nonce);
      fd.append("user_book_id", String(PRS_BOOK.user_book_id));
      fd.append("purchase_channel", channel);
      fd.append("purchase_place", placeVal);

      ajaxPost(PRS_BOOK.ajax_url, fd)
        .then(json => {
          if (!json || !json.success) throw json;
          let label = "—";
          if (channel) {
            label = channel.charAt(0).toUpperCase() + channel.slice(1);
            if (placeVal) label += " — " + placeVal;
          }
          setText(view, label);
          setStatus(status, "Saved.", true);
          show(editBtn);
          hide(form);
        })
        .catch(err => {
          const msg = (err && err.data && err.data.message) ? err.data.message : "Error saving channel.";
          setStatus(status, msg, false, 4000);
        });
    });
  }

  // ---------- Rating (stars) ----------
  function setupRating() {
    const wrap = qs("#fld-user-rating");
    if (!wrap || !window.PRS_BOOK) return;

    const stars = qsa("#prs-user-rating .prs-star", wrap);
    const status = qs("#rating-status", wrap);

    function paint(upTo) {
      stars.forEach((btn, i) => {
        const on = (i + 1) <= upTo;
        btn.classList.toggle("is-active", on);
        btn.setAttribute("aria-checked", on ? "true" : "false");
      });
    }

    stars.forEach((btn, idx) => {
      btn.addEventListener("click", () => {
        const val = idx + 1;
        const fd = new FormData();
        fd.append("action", "prs_update_user_book_meta");
        fd.append("nonce", PRS_BOOK.nonce);
        fd.append("user_book_id", String(PRS_BOOK.user_book_id));
        fd.append("rating", String(val));

        ajaxPost(PRS_BOOK.ajax_url, fd)
          .then(json => {
            if (!json || !json.success) throw json;
            paint(val);
            setStatus(status, "Saved.", true);
          })
          .catch(err => {
            const msg = (err && err.data && err.data.message) ? err.data.message : "Error saving rating.";
            setStatus(status, msg, false, 4000);
          });
      });
    });
  }

  // ---------- Reading Status ----------
  function setupReadingStatus() {
    const wrap = qs("#fld-reading-status");
    if (!wrap || !window.PRS_BOOK) return;

    const select = qs("#reading-status-select", wrap);
    const status = qs("#reading-status-status", wrap);

    if (!select) return;

    select.addEventListener("change", () => {
      const val = (select.value || "not_started").trim();
      const fd = new FormData();
      fd.append("action", "prs_update_user_book_meta");
      fd.append("nonce", PRS_BOOK.nonce);
      fd.append("user_book_id", String(PRS_BOOK.user_book_id));
      fd.append("reading_status", val);

      ajaxPost(PRS_BOOK.ajax_url, fd)
        .then(json => {
          if (!json || !json.success) throw json;
          setStatus(status, "Saved.", true);
        })
        .catch(err => {
          const msg = (err && err.data && err.data.message) ? err.data.message : "Error updating status.";
          setStatus(status, msg, false, 4000);
        });
    });
  }

  // ---------- Owning Status + Return to shelf + Contact ----------
  function setupOwningStatus() {
    const wrap = qs("#fld-owning-status");
    if (!wrap || !window.PRS_BOOK) return;

    const select = qs("#owning-status-select", wrap);
    const status = qs("#owning-status-status", wrap);
    const returnBtn = qs("#owning-return-shelf", wrap);
    const derivedText = qs("#derived-location-text", wrap);
    const contactForm = qs("#owning-contact-form", wrap);
    const contactName = qs("#owning-contact-name", wrap);
    const contactEmail = qs("#owning-contact-email", wrap);
    const contactSave = qs("#owning-contact-save", wrap);
    const contactStatus = qs("#owning-contact-status", wrap);
    const contactView = qs("#owning-contact-view", wrap);

    function updateDerived(val) {
      const inShelf = !val; // NULL/'' => In Shelf
      setText(derivedText, inShelf ? "In Shelf" : "Not In Shelf");
      // botón "Mark as returned" visible solo si borrowed/borrowing
      const showReturn = (val === "borrowed" || val === "borrowing");
      returnBtn && (returnBtn.style.display = showReturn ? "" : "none");

      // contacto requerido si borrowed/borrowing/sold y faltan datos => mostramos form
      const needsContact = (val === "borrowed" || val === "borrowing" || val === "sold");
      if (needsContact) {
        show(contactForm);
      } else {
        hide(contactForm);
      }
    }

    function postOwning(val) {
      const fd = new FormData();
      fd.append("action", "prs_update_user_book_meta");
      fd.append("nonce", PRS_BOOK.nonce);
      fd.append("user_book_id", String(PRS_BOOK.user_book_id));
      fd.append("owning_status", val); // "" => volver a In Shelf

      return ajaxPost(PRS_BOOK.ajax_url, fd)
        .then(json => {
          if (!json || !json.success) throw json;
          setStatus(status, "Saved.", true);
          updateDerived(val);
        })
        .catch(err => {
          const msg = (err && err.data && err.data.message) ? err.data.message : "Error updating owning status.";
          setStatus(status, msg, false, 4000);
        });
    }

    if (select) {
      updateDerived(select.value || "");
      select.addEventListener("change", () => {
        const val = (select.value || "").trim(); // "", borrowed, borrowing, sold, lost
        postOwning(val);
      });
    }

    if (returnBtn) {
      returnBtn.addEventListener("click", () => {
        // Volver a In Shelf
        select && (select.value = "");
        postOwning("");
      });
    }

    if (contactSave) {
      contactSave.addEventListener("click", () => {
        const name = (contactName && contactName.value || "").trim();
        const email = (contactEmail && contactEmail.value || "").trim();

        const fd = new FormData();
        fd.append("action", "prs_update_user_book_meta");
        fd.append("nonce", PRS_BOOK.nonce);
        fd.append("user_book_id", String(PRS_BOOK.user_book_id));
        fd.append("counterparty_name", name);
        fd.append("counterparty_email", email);

        // No cambiamos owning_status aquí para no alterar el flujo,
        // solo guardamos contacto (la clase actualiza el loan abierto si aplica).
        ajaxPost(PRS_BOOK.ajax_url, fd)
          .then(json => {
            if (!json || !json.success) throw json;
            setStatus(contactStatus, "Saved.", true);
            // Actualiza la vista compacta (no tenemos la fecha del loan, así que solo nombre/email)
            let v = "";
            if (name) v += name;
            if (email) v += (v ? " · " : "") + email;
            if (contactView) contactView.textContent = v;
          })
          .catch(err => {
            const msg = (err && err.data && err.data.message) ? err.data.message : "Error saving contact.";
            setStatus(contactStatus, msg, false, 4000);
          });
      });
    }
  }

  // ---------- Sesiones: render parcial + paginación ----------
  function setupSessionsAjax() {
    if (!window.PRS_SESS) return;
    const box = qs("#prs-sessions-table");
    if (!box) return;

    function loadSessions(page) {
      const p = num(page, 1);
      const fd = new FormData();
      fd.append("action", "prs_render_sessions");
      fd.append("nonce", PRS_SESS.nonce);
      fd.append("book_id", String(PRS_SESS.book_id));
      fd.append("paged", String(p));

      box.innerHTML = "<p>Loading…</p>";

      ajaxPost(PRS_SESS.ajax_url, fd)
        .then(json => {
          if (!json || !json.success) throw json;
          box.innerHTML = json.data && json.data.html ? json.data.html : "";

          // Actualiza la URL (sin recarga) con ?prs_sess=N
          try {
            const url = new URL(window.location.href);
            url.searchParams.set(PRS_SESS.param, String(json.data.paged || 1));
            window.history.replaceState({}, "", url.toString());
          } catch (e) { /* noop */ }
        })
        .catch(err => {
          box.innerHTML = "<p>Error loading sessions.</p>";
        });
    }

    // Primer render (usa data-initial-paged si viene en el HTML)
    const initial = num(box.getAttribute("data-initial-paged"), 1);
    loadSessions(initial);

    // Delegación de clicks para paginación
    document.addEventListener("click", function (e) {
      const link = e.target.closest("a.prs-sess-link");
      if (!link) return;
      if (!box.contains(link)) return; // solo enlaces dentro del bloque
      e.preventDefault();
      const page = num(link.getAttribute("data-page"), 1);
      loadSessions(page);
    });
  }

  // ---------- Boot ----------
  document.addEventListener("DOMContentLoaded", function () {
    setupPages();
    setupPurchaseDate();
    setupPurchaseChannel();
    setupRating();
    setupReadingStatus();
    setupOwningStatus();
    setupSessionsAjax();
  });
})();
