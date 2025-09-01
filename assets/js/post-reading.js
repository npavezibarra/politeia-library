/**
 * post-reading.js
 * Lógica frontend para el botón Start/Finish Reading en posts regulares
 */

document.addEventListener("DOMContentLoaded", () => {
    const btn = document.querySelector(".politeia-post-reading-btn");
  
    if (!btn || typeof politeiaPostReading === "undefined") {
      return;
    }
  
    btn.addEventListener("click", async () => {
      const postId = politeiaPostReading.postId;
      const restUrl = politeiaPostReading.restUrl;
      const nonce = politeiaPostReading.nonce;
  
      btn.disabled = true;
  
      try {
        const response = await fetch(restUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": nonce,
          },
          body: JSON.stringify({
            post_id: postId,
            action: btn.classList.contains("is-finished") ? "finish" : "start",
          }),
        });
  
        if (!response.ok) {
          throw new Error("Request failed");
        }
  
        const data = await response.json();
  
        // Cambiar estado del botón según respuesta
        // Dentro del listener de click, donde procesas la respuesta:
        if (data.status === "started") {
          btn.textContent = "Finish Reading";
          btn.classList.add("is-finished");
        } else if (data.status === "finished") {
          btn.textContent = "Start Reading Again";   // ⬅️ antes decía "Start Reading"
          btn.classList.remove("is-finished");
          // marca en memoria local que ya completó al menos una vez
          if (typeof politeiaPostReading !== "undefined") {
            politeiaPostReading.hasCompleted = true;
          }
        }
      } catch (err) {
        console.error("Error toggling reading state:", err);
        alert("Hubo un problema al registrar la lectura.");
      } finally {
        btn.disabled = false;
      }
    });
  });
  