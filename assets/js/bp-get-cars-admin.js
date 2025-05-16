/**
 * Improved admin car list update JS
 * - Modularizes logic for clarity and maintainability
 * - Improves event handling
 * - Enhances user feedback and error handling
 * - Reduces global scope pollution
 */

(function () {
  const { __ } = window.wp.i18n || { __: (s) => s };

  document.addEventListener("DOMContentLoaded", function () {
    let offset = 0;
    let running = false;
    let total = 0;
    let sessionId = null;

    /**
     * Utility function to get the batch size from the config or default to 5
     */
    function getBatchSize() {
      let size = 5;
      if (typeof BPGetCarsAjax !== "undefined" && BPGetCarsAjax.batch_size) {
        size = parseInt(BPGetCarsAjax.batch_size, 10);
      } else {
        const el = document.getElementById("bp_get_cars_batch_size");
        if (el && el.value) {
          size = parseInt(el.value, 10);
        }
      }
      // Clamp to a reasonable range (e.g., 1 to 100)
      if (isNaN(size) || size < 1) size = 1;
      if (size > 100) size = 100;
      return size;
    }

    /**
     * Utility function to display status updates
     */
    function appendStatus(html) {
      const status = document.getElementById("bp-get-cars-update-status");
      if (status) {
        status.insertAdjacentHTML("beforeend", html);
        status.scrollTo({ top: status.scrollHeight, behavior: "smooth" });
      }
    }

    /**
     * Utility function to reset status content
     */
    function resetStatus(html) {
      const status = document.getElementById("bp-get-cars-update-status");
      if (status) {
        status.innerHTML = html;
        status.scrollTo({ top: status.scrollHeight, behavior: "smooth" });
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
        btn.textContent = __("Update List", "bp-get-cars");
        pageTitleAction.insertAdjacentElement("afterend", btn);
      }
      return btn;
    }

    /**
     * Handles clicks on the update button
     */
    async function handleClick(event) {
      if (running) return;

      running = true;
      offset = 0;
      sessionId = null; // Reset session on new import

      resetStatus(`<p>${__("Starting update...", "bp-get-cars")}</p>`);

      btn.textContent = __("Updating...", "bp-get-cars");
      btn.setAttribute("disabled", "disabled");

      await runBatch();
    }

    /**
     * Main batch update logic
     */
    async function runBatch() {
      const batchSize = getBatchSize();

      if (typeof BPGetCarsAjax === "undefined") {
        appendStatus(
          `<p style='color:red;'>${__(
            "The BPGetCarsAjax config object is missing.",
            "bp-get-cars"
          )}</p>`
        );
        btn.textContent = __("Update List", "bp-get-cars");
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

      try {
        const response = await fetch(BPGetCarsAjax.ajax_url, {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
          },
          body: new URLSearchParams(params).toString(),
        });
        if (!response.ok) {
          throw new Error(
            __("HTTP error: ", "bp-get-cars") +
              response.status +
              " " +
              response.statusText
          );
        }
        const data = await response.json();
        if (!data || typeof data !== "object") {
          handleError(__("Invalid JSON response from server.", "bp-get-cars"));
          return;
        }
        if (data.success && data.data) {
          const d = data.data;
          if (!sessionId && d.session_id) {
            sessionId = d.session_id;
          }
          total = d.total;
          let done = offset + batchSize;
          if (done > total) done = total;
          appendStatus(`<p>Processed ${done} of ${total} cars...</p>`);
          offset = d.next_offset;
          if (d.has_more) {
            await runBatch();
          } else {
            appendStatus(
              `<p style='color:green;'>${__("Done!", "bp-get-cars")}</p>`
            );
            btn.textContent = __("Update List", "bp-get-cars");
            btn.removeAttribute("disabled");
            running = false;
          }
        } else if (data.data && data.data.error) {
          // Handle backend-reported batch item error
          handleError(data.data.error);
        } else {
          let errorMsg = __("Error during update.", "bp-get-cars");
          if (data.data && data.data.error) {
            errorMsg += " " + data.data.error;
          }
          handleError(errorMsg);
        }
      } catch (error) {
        handleError(error);
      }
    }

    /**
     * Handles errors properly, updating the UI and re-enabling the button
     */
    function handleError(error) {
      const msg = error && error.message ? error.message : String(error);
      appendStatus(
        `<p style='color:red;'>${__(
          "Error during update:",
          "bp-get-cars"
        )} ${msg}</p>`
      );
      btn.textContent = __("Update List", "bp-get-cars");
      btn.removeAttribute("disabled");
      running = false;
    }

    // ===== INIT =====
    let btn = getOrCreateUpdateButton();
    if (!btn) {
      appendStatus(
        `<p style='color:red;'>${__(
          "Could not find or create the car update button.",
          "bp-get-cars"
        )}</p>`
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
