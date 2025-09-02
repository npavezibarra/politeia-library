/**
 * post-reading.js
 * Toggle Start/Finish + botón flotante alineado al contenido
 */
document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".politeia-post-reading-wrap");
  const btn  = document.querySelector(".politeia-post-reading-btn");
  if (!wrap || typeof politeiaPostReading === "undefined") return;

  /* ----------------------- Floating / Fixed behavior (alineado al contenido) ----------------------- */

  const FIXED_TOP = 107; // px bajo tu navbar
  let initialTop = wrap.getBoundingClientRect().top + window.scrollY;

  function getContentContainer() {
    const selectors = [
      ".entry-content",
      "article .entry-content",
      ".single-post .entry-content",
      ".post-content",
      ".site-main .entry-content",
      ".site-main",
      "main"
    ];
    for (const sel of selectors) {
      const el = document.querySelector(sel);
      if (el) return el;
    }
    return wrap.parentElement || document.body;
  }

  function computeAlignment() {
    const ref  = getContentContainer();
    const rect = ref.getBoundingClientRect();
    const cs   = getComputedStyle(ref);
    const padL = parseFloat(cs.paddingLeft)  || 0;
    const padR = parseFloat(cs.paddingRight) || 0;

    // left = borde izquierdo del contenedor + padding-left (alineado al texto)
    const left  = rect.left + window.scrollX + padL;
    // width = ancho interior útil (sin paddings)
    const width = rect.width - padL - padR;

    wrap.style.setProperty("--politeia-fixed-left", left + "px");
    wrap.style.setProperty("--politeia-fixed-width", width + "px");

    // punto de enganche original del wrapper
    initialTop = wrap.getBoundingClientRect().top + window.scrollY;
  }

  function onScroll() {
    if (window.scrollY + FIXED_TOP >= initialTop) {
      wrap.classList.add("is-fixed");
    } else {
      wrap.classList.remove("is-fixed");
    }
  }

  // init
  computeAlignment();
  onScroll();
  window.addEventListener("resize", () => { computeAlignment(); onScroll(); });
  window.addEventListener("orientationchange", () => { computeAlignment(); onScroll(); });
  window.addEventListener("scroll", onScroll, { passive: true });

  /* ----------------------- Estado inicial del botón ----------------------- */

  if (btn && politeiaPostReading.isLoggedIn) {
    const isStarted = politeiaPostReading.initial &&
                      politeiaPostReading.initial.status === "started";
    if (isStarted) {
      btn.textContent = "Finish Reading";
      btn.classList.add("is-finished");
    } else {
      btn.textContent = politeiaPostReading.hasCompleted
        ? "Start Reading Again"
        : "Start Reading";
      btn.classList.remove("is-finished");
    }
  }
  if (btn && !politeiaPostReading.isLoggedIn) btn.disabled = true;

  /* ----------------------- Toggle Start / Finish ----------------------- */

  if (btn) {
    btn.addEventListener("click", async () => {
      if (!politeiaPostReading.isLoggedIn) return;

      btn.disabled = true;
      const postId = politeiaPostReading.postId;
      const restUrl = politeiaPostReading.restUrl;
      const nonce = politeiaPostReading.nonce;

      try {
        const action = btn.classList.contains("is-finished") ? "finish" : "start";
        const res = await fetch(restUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": nonce
          },
          body: JSON.stringify({ post_id: postId, action })
        });
        if (!res.ok) throw new Error("Request failed");
        const data = await res.json();

        if (data.status === "started") {
          btn.textContent = "Finish Reading";
          btn.classList.add("is-finished");
        } else if (data.status === "finished") {
          btn.textContent = "Start Reading Again";
          btn.classList.remove("is-finished");
          politeiaPostReading.hasCompleted = true;
        }
      } catch (e) {
        console.error(e);
        alert("Hubo un problema al registrar la lectura.");
      } finally {
        btn.disabled = false;
      }
    });
  }
});
