(function () {
    'use strict';

    function marketplaceRequest(form) {
        var root = form.closest('[data-marketplace="1"]');
        var requestError = root ? root.getAttribute('data-request-error') : '';
        var button = form.querySelector('button[type="submit"]');
        var originalText = button ? button.textContent : '';

        if (button) {
            button.disabled = true;
            button.classList.add('is-loading');
        }

        fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data || !data.success) {
                throw new Error(data && data.message ? data.message : requestError);
            }

            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            var counters = document.querySelectorAll('[data-cart-count]');
            counters.forEach(function (counter) {
                counter.textContent = String(data.cart_count || 0);
            });

            if (button) {
                button.textContent = data.message || originalText;
                window.setTimeout(function () {
                    button.textContent = originalText;
                    button.disabled = false;
                    button.classList.remove('is-loading');
                }, 900);
            }
        }).catch(function (error) {
            window.alert(error.message || requestError);
            if (button) {
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('.dle-marketplace-ajax-form')) {
            return;
        }

        event.preventDefault();
        marketplaceRequest(form);
    });
}());
