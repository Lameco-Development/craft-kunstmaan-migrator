/**
 * Swap a container's contents from a controller action that returns
 * {html, js?}. Owns the loading state, the error path, and the buffered-JS
 * replay that keeps swapped-in selectize fields alive.
 *
 * opts.busy   — element to mark .loading instead of dimming the container
 * opts.onDone — called with the response after a successful swap
 */
window.kumaSwap = function (container, action, data, opts) {
  opts = opts || {};

  if (opts.busy) {
    opts.busy.classList.add('loading');
  } else {
    container.style.opacity = '0.5';
  }

  return Craft.sendActionRequest('POST', action, { data: data || {} })
    .then(function (response) {
      container.innerHTML = response.data.html;
      if (response.data.js) {
        Craft.appendBodyHtml(response.data.js);
      }
      if (opts.onDone) {
        opts.onDone(response);
      }
    })
    .catch(function (error) {
      Craft.cp.displayError(
        (error && error.response && error.response.data && error.response.data.message) || null
      );
      throw error;
    })
    .finally(function () {
      if (opts.busy) {
        opts.busy.classList.remove('loading');
      } else {
        container.style.opacity = '';
      }
    });
};
