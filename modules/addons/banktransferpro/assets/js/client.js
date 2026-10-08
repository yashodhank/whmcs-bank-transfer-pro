(function () {
    'use strict';

    if (window.__btpClientLoaded) {
        return;
    }
    window.__btpClientLoaded = true;

    function paymentLabel() {
        var pack = document.querySelector('.btp-packs[data-btp-label]');
        if (pack) {
            return pack.getAttribute('data-btp-label') || '';
        }

        return typeof window.BTP_PAYMENT_LABEL === 'string' ? window.BTP_PAYMENT_LABEL : '';
    }

    function dedupeProofPanels() {
        var panels = document.querySelectorAll('#btp-payment-proof');
        for (var i = 1; i < panels.length; i++) {
            panels[i].parentNode.removeChild(panels[i]);
        }
    }

    function moveProofPanel() {
        var panel = document.getElementById('btp-payment-proof');
        if (!panel || panel.dataset.btpPlaced === '1') {
            return;
        }

        // Stock viewinvoice themes render the gateway block in a narrow header column;
        // give the upload form the full invoice width instead.
        var container = document.querySelector('.invoice-container');
        var bankDetails = document.querySelector('.btp-bank-details');
        if (container) {
            container.appendChild(panel);
        } else if (bankDetails) {
            bankDetails.insertAdjacentElement('afterend', panel);
        }
        panel.dataset.btpPlaced = '1';
    }

    function relabelPaymentMethod() {
        var label = paymentLabel();
        if (!label) {
            return;
        }

        var select = document.querySelector('select[name="paymentmethod"]');
        if (!select || !select.value || select.value.indexOf('banktransferpro') !== 0) {
            return;
        }

        if (select.options.length === 1) {
            if (select.dataset.btpRelabelled === '1') { return; }
            select.dataset.btpRelabelled = '1';
            var summary = document.createElement('div');
            summary.className = 'btp-payment-method-summary';
            summary.innerHTML = '<span class="btp-payment-method-summary__label">Pay via</span> <strong></strong>';
            summary.querySelector('strong').textContent = label;
            select.style.display = 'none';
            select.insertAdjacentElement('afterend', summary);
            return;
        }

        var selectedOption = select.options[select.selectedIndex];
        if (selectedOption) {
            selectedOption.text = label;
        }
    }

    function initCopyButtons() {
        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('.btp-copy') : null;
            if (!button) { return; }
            var value = button.getAttribute('data-btp-copy') || '';
            var done = function () {
                var original = button.getAttribute('data-btp-label') || button.textContent;
                button.setAttribute('data-btp-label', original);
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = original; }, 1500);
            };
            var fallback = function () {
                var area = document.createElement('textarea');
                area.value = value;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); done(); } catch (error) { /* values stay selectable */ }
                document.body.removeChild(area);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done, fallback);
            } else {
                fallback();
            }
        });
    }

    function initPaymentProofForm() {
        var form = document.querySelector('#btp-payment-proof form');
        if (!form || form.dataset.btpBound === '1') { return; }
        form.dataset.btpBound = '1';

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var result = form.querySelector('.btp-payment-proof__result');
            var data = new FormData(form);
            fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (response) {
                    return response.text().then(function (bodyText) {
                        var payload = null;
                        if (bodyText) {
                            try {
                                payload = JSON.parse(bodyText);
                            } catch (error) {
                                payload = null;
                            }
                        }
                        if (payload && typeof payload.success === 'boolean') {
                            return payload;
                        }
                        var message = 'Upload failed.';
                        if (response && !response.ok) {
                            message = 'Unexpected server response (HTTP ' + response.status + ').';
                        } else if (bodyText) {
                            message = 'Unexpected non-JSON response: ' + bodyText.substring(0, 160);
                        }
                        throw new Error(message);
                    });
                })
                .then(function (payload) {
                    if (payload.success) {
                        result.className = 'btp-payment-proof__result alert alert-success';
                        result.textContent = payload.message || 'Upload successful.';
                        form.reset();
                    } else {
                        result.className = 'btp-payment-proof__result alert alert-danger';
                        result.textContent = (payload.error && payload.error.message) ? payload.error.message : 'Upload failed.';
                    }
                })
                .catch(function (error) {
                    result.className = 'btp-payment-proof__result alert alert-danger';
                    result.textContent = (error && error.message) ? error.message : 'Upload failed. Please try again.';
                });
        });
    }

    function init() {
        dedupeProofPanels();
        relabelPaymentMethod();
        moveProofPanel();
        initPaymentProofForm();
    }

    initCopyButtons();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
