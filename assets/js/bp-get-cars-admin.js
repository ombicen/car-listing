document.addEventListener("DOMContentLoaded", function () {
  let offset = 0;
  let running = false;
  let total = 0;
  let sessionId = null;
  const pageTitleAction = document.querySelector(".wrap .page-title-action");
  if (pageTitleAction) {
    const updateButton = document.createElement("a");
    updateButton.id = "bp-get-cars-update-btn";
    updateButton.className = "page-title-action";
    updateButton.textContent = "Uppdatera Lista";
    updateButton.addEventListener("click", handleClick);
    pageTitleAction.insertAdjacentElement("afterend", updateButton);
  }

  const btn = document.getElementById("bp-get-cars-update-btn") || updateButton;

  const status = document.getElementById("bp-get-cars-update-status");
  const batchSize =
    typeof BPGetCarsAjax !== "undefined" && BPGetCarsAjax.batch_size
      ? parseInt(BPGetCarsAjax.batch_size)
      : parseInt(document.getElementById("bp_get_cars_batch_size")?.value) || 5;

  function handleClick() {
    if (running) return;
    running = true;
    offset = 0;
    sessionId = null; // Reset session on new import
    status.innerHTML = "<p>Startar uppdatering...</p>";

    btn.textContent = "Uppdaterar...";
    btn.disabled = true;

    runBatch();
  }

  btn.addEventListener("click", handleClick);

  function appendStatus(html) {
    status.insertAdjacentHTML("beforeend", html);
  }

  function runBatch() {
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
          appendStatus(
            '<p style="color:red;">Fel vid uppdatering: Ogiltigt JSON-svar från servern.</p>'
          );
          btn.disabled = false;
          running = false;
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
            appendStatus("<p>Färdig!</p>");
            btn.textContent = "Uppdatera Lista";
            btn.disabled = false;
            running = false;
          }
        } else {
          let errorMsg = "Fel vid uppdatering.";
          if (response.data && response.data.error) {
            errorMsg += " " + response.data.error;
          }
          appendStatus(`<p style="color:red;">${errorMsg}</p>`);
          btn.textContent = "Uppdatera Lista";
          btn.disabled = false;
          running = false;
        }
      })
      .catch((error) => {
        appendStatus(`<p style="color:red;">Fel vid uppdatering: ${error}</p>`);
        btn.textContent = "Uppdatera Lista";
        btn.disabled = false;
        running = false;
      });
  }
});
