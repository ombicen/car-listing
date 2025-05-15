/**
 * Improved admin car list update JS
 * - Modularizes logic for clarity and maintainability
 * - Improves event handling
 * - Enhances user feedback and error handling
 * - Reduces global scope pollution
 */

(function () {
  document.addEventListener("DOMContentLoaded", function () {
    let offset = 0;
    let running = false;
    let total = 0;
    let sessionId = null;

    /**
     * Utility function to get the batch size from the config or default to 5
     */
    function getBatchSize() {
      if (typeof BPGetCarsAjax !== "undefined" && BPGetCarsAjax.batch_size) {
        return parseInt(BPGetCarsAjax.batch_size);
      }
      const el = document.getElementById("bp_get_cars_batch_size");
      if (el && el.value) {
        return parseInt(el.value);
      }
      return 5;
    }

    /**
     * Utility function to display status updates
     */
    function appendStatus(html) {
      const status = document.getElementById("bp-get-cars-update-status");
      if (status) {
        status.insertAdjacentHTML("beforeend", html);
        status.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }

    /**
     * Utility function to reset status content
     */
    function resetStatus(html) {
      const status = document.getElementById("bp-get-cars-update-status");
      if (status) {
        status.innerHTML = html;
        status.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }

    /**
     * Prepares the update button or inserts one if needed
     */
    function getOrCreateUpdateButton() {
      let btn = document.getElementById("bp-get-cars-update-btn");
      if (!btn) {
        const pageTitleAction = document.querySelector(
          ".wrap .page-title-action"
        );
        if (!pageTitleAction) {
          console.warn("Could not find parent for admin car update button.");
          return null;
        }
        btn = document.createElement("a");
        btn.id = "bp-get-cars-update-btn";
        btn.className = "page-title-action";
        btn.textContent = "Uppdatera Lista";
        pageTitleAction.insertAdjacentElement("afterend", btn);
      }
      return btn;
    }

    /**
     * Handles clicks on the update button
     */
    function handleClick(event) {
      if (running) return;

      running = true;
      offset = 0;
      sessionId = null; // Reset session on new import

      resetStatus("<p>Startar uppdatering...</p>");

      btn.textContent = "Uppdaterar...";
      btn.setAttribute("disabled", "disabled");

      runBatch();
    }

    /**
     * Main batch update logic
     */
    function runBatch() {
      const batchSize = getBatchSize();

      if (typeof BPGetCarsAjax === "undefined") {
        appendStatus(
          "<p style='color:red;'>Konfigurationsobjektet BPGetCarsAjax saknas.</p>"
        );
        btn.textContent = "Uppdatera Lista";
        btn.removeAttribute("disabled");
        running = false;
        return;
      }

      const params = {
        action: "bp_get_cars_update_batch",
        nonce: BPGetCarsAjax.nonce,
        offset: offset,
        batch_size: batchSize,
      };
      if (sessionId) {
        params.session_id = sessionId;
      }

      fetch(BPGetCarsAjax.ajax_url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams(params),
      })
        .then((response) => response.json())
        .then((response) => {
          // Defensive: check for valid JSON structure
          if (!response || typeof response !== "object") {
            handleError("Ogiltigt JSON-svar från servern.");
            return;
          }
          if (response.success && response.data) {
            const data = response.data;
            if (!sessionId && data.session_id) {
              sessionId = data.session_id;
            }
            total = data.total;
            let done = offset + batchSize;
            if (done > total) done = total;
            appendStatus(`<p>Bearbetade ${done} av ${total} bilar...</p>`);
            offset = data.next_offset;
            if (data.has_more) {
              runBatch();
            } else {
              appendStatus("<p style='color:green;'>Färdig!</p>");
              btn.textContent = "Uppdatera Lista";
              btn.removeAttribute("disabled");
              running = false;
            }
          } else {
            let errorMsg = "Fel vid uppdatering.";
            if (response.data && response.data.error) {
              errorMsg += " " + response.data.error;
            }
            handleError(errorMsg);
          }
        })
        .catch((error) => {
          handleError(error);
        });
    }

    /**
     * Handles errors properly, updating the UI and re-enabling the button
     */
    function handleError(error) {
      appendStatus(`<p style='color:red;'>Fel vid uppdatering: ${error}</p>`);
      btn.textContent = "Uppdatera Lista";
      btn.removeAttribute("disabled");
      running = false;
    }

    // ===== INIT =====
    let btn = getOrCreateUpdateButton();
    if (!btn) {
      appendStatus(
        "<p style='color:red;'>Kunde inte hitta eller skapa uppdateringsknappen för bilar.</p>"
      );
      return;
    }

    // Remove previous event listeners (if any) and add the click handler only once
    btn.replaceWith(btn.cloneNode(true));
    const freshBtn = document.getElementById("bp-get-cars-update-btn");
    freshBtn.addEventListener("click", handleClick);
    btn = freshBtn;
  });
})();
