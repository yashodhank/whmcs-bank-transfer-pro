(function () {
    'use strict';

    var config = window.BTP_ADMIN || {};
    var apiUrl = config.apiUrl;
    var csrfToken = config.csrfToken || config.adminToken || '';

    function getAlertElement(target) {
        var id = target === 'modal' ? 'btp-bank-modal-alert' : 'btp-alert';
        return document.getElementById(id);
    }

    function buildRequestError(response, bodyText) {
        var message = 'Request failed.';
        var payload = null;

        if (bodyText) {
            try {
                payload = JSON.parse(bodyText);
            } catch (error) {
                payload = null;
            }
        }

        if (payload && payload.error && payload.error.message) {
            message = payload.error.message;
        } else if (response && !response.ok) {
            message = 'Unexpected server response (HTTP ' + response.status + ').';
        } else if (bodyText) {
            message = 'Unexpected non-JSON response: ' + bodyText.substring(0, 160);
        }

        var requestError = new Error(message);
        requestError.payload = payload;
        requestError.status = response ? response.status : 0;

        return requestError;
    }

    function showAlert(type, message, target) {
        var alert = getAlertElement(target);
        if (!alert) {
            return;
        }
        alert.className = 'alert alert-' + type;
        alert.textContent = message;
        alert.style.display = 'block';
    }

    function hideAlert(target) {
        var alert = getAlertElement(target);
        if (!alert) {
            return;
        }
        alert.style.display = 'none';
        alert.textContent = '';
        alert.className = 'alert';
    }

    function truncate(text, max) {
        if (!text || text.length <= max) {
            return text || '';
        }
        return text.substring(0, max) + '…';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function apiRequest(action, method, payload) {
        var url = apiUrl + '&btp_action=' + encodeURIComponent(action);
        var options = {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        };

        if (payload) {
            var body = new URLSearchParams();
            Object.keys(payload).forEach(function (key) {
                if (payload[key] === undefined || payload[key] === null) {
                    return;
                }
                body.append(key, String(payload[key]));
            });
            if (csrfToken) {
                body.append('token', csrfToken);
            }
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            options.body = body.toString();
        }

        return fetch(url, options).then(function (response) {
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

                throw buildRequestError(response, bodyText);
            });
        });
    }

    function renderBanks(banks) {
        var tbody = document.querySelector('#btp-banks-table tbody');
        if (!tbody) {
            return;
        }

        tbody.innerHTML = '';

        if (!banks.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No bank accounts configured yet.</td></tr>';
            return;
        }

        banks.forEach(function (bank) {
            var row = document.createElement('tr');
            var detailsRaw = String(bank.account_details || bank.account_number || bank.upi_id || '');
            var badges = (bank.capabilities || []).map(function (cap) {
                return '<span class="btp-cap-badge">' + escapeHtml(capabilityLabel(cap)) + '</span>';
            }).join('');
            var country = bank.country_code ? '<span class="btp-cap-badge">' + escapeHtml(bank.country_code) + '</span>' : '';
            var payeeWarning = bank.account_name ? '' : '<span class="btp-cap-badge btp-cap-badge--warn" title="Clients will not see the account holder name until you add it (Edit &rarr; Account identity).">No payee name</span>';
            row.innerHTML =
                '<td>' + escapeHtml(bank.id) + '</td>' +
                '<td><code>' + escapeHtml(bank.gateway_slug) + '</code></td>' +
                '<td>' + escapeHtml(bank.display_name) + '</td>' +
                '<td class="btp-account-details" title="' + escapeHtml(detailsRaw) + '">' +
                    country + badges + payeeWarning + ' ' + escapeHtml(truncate(detailsRaw, 60)) +
                '</td>' +
                '<td>' + escapeHtml(bank.currency_code) + '</td>' +
                '<td class="text-right btp-actions">' +
                    '<button type="button" class="btn btn-default btn-sm btp-edit-btn" data-id="' + escapeHtml(bank.id) + '"><i class="fas fa-pencil-alt"></i> Edit</button>' +
                    '<button type="button" class="btn btn-danger btn-sm btp-delete-btn" data-id="' + escapeHtml(bank.id) + '"><i class="fas fa-trash-alt"></i> Delete</button>' +
                '</td>';
            tbody.appendChild(row);
        });
    }

    function loadBanks() {
        apiRequest('list').then(function (payload) {
            if (!payload.success) {
                showAlert('danger', payload.error ? payload.error.message : 'Failed to load banks.');
                return;
            }
            renderBanks((payload.data && payload.data.banks) || []);
        }).catch(function () {
            showAlert('danger', 'Failed to load banks.');
        });
    }

    function openModal(title) {
        var modal = document.getElementById('btp-bank-modal');
        var label = document.getElementById('btp-bank-modal-label');
        if (label) {
            label.textContent = title;
        }
        if (window.jQuery && modal) {
            window.jQuery(modal).modal('show');
        }
    }

    function closeModal() {
        var modal = document.getElementById('btp-bank-modal');
        if (window.jQuery && modal) {
            window.jQuery(modal).modal('hide');
        }
    }

    var CAPABILITIES = ['local_transfer', 'instant_alias', 'international_wire'];
    var CAPABILITY_LABELS = { local_transfer: 'Local', instant_alias: 'Instant', international_wire: 'Wire' };
    var registry = config.registry || { schemes: [], ibanCountries: [], capabilities: CAPABILITIES };
    var wizard = { step: 1, identifiers: {}, packNotes: {} };

    function capabilityLabel(cap) {
        return CAPABILITY_LABELS[cap] || cap;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function enabledCapabilities() {
        return CAPABILITIES.filter(function (cap) {
            var box = document.querySelector('.btp-cap-checkbox[data-btp-cap="' + cap + '"]');
            return box && box.checked;
        });
    }

    function schemesFor(cap, country) {
        return (registry.schemes || []).filter(function (scheme) {
            if (scheme.capabilities.indexOf(cap) === -1) {
                return false;
            }
            return !scheme.countries.length || scheme.countries.indexOf(country) !== -1;
        });
    }

    function schemeContainer(cap) {
        return cap === 'international_wire' ? byId('btp-scheme-slot-international_wire') : byId('btp-cap-fields-' + cap);
    }

    function readSchemeInputs() {
        document.querySelectorAll('.btp-scheme-input').forEach(function (input) {
            wizard.identifiers[input.getAttribute('data-scheme')] = input.value;
        });
    }

    function renderSchemeFields() {
        readSchemeInputs();
        var country = byId('btp-country-code').value;
        var rendered = {};
        var enabled = enabledCapabilities();

        CAPABILITIES.forEach(function (cap) {
            var wrapper = byId('btp-cap-fields-' + cap);
            var holder = schemeContainer(cap);
            var on = enabled.indexOf(cap) !== -1;
            wrapper.style.display = on ? 'block' : 'none';
            holder.innerHTML = '';
            if (!on) {
                return;
            }
            var schemes = schemesFor(cap, country);
            if (!schemes.length && cap === 'instant_alias') {
                holder.innerHTML = '<p class="text-muted">No instant-payment scheme is available for this country yet. Use local transfer or international wire.</p>';
            }
            schemes.forEach(function (scheme) {
                if (rendered[scheme.id]) {
                    return;
                }
                rendered[scheme.id] = true;
                var group = document.createElement('div');
                group.className = 'form-group';
                group.innerHTML =
                    '<label for="btp-scheme-' + escapeHtml(scheme.id) + '">' + escapeHtml(scheme.label) + '</label>' +
                    '<input type="text" class="form-control btp-scheme-input" autocomplete="off" data-scheme="' + escapeHtml(scheme.id) + '" id="btp-scheme-' + escapeHtml(scheme.id) + '" placeholder="' + escapeHtml(scheme.placeholder || '') + '" />' +
                    '<p class="help-block">' + escapeHtml(scheme.help || '') + '</p>';
                group.querySelector('input').value = wizard.identifiers[scheme.id] || '';
                holder.appendChild(group);
            });
        });

        document.querySelectorAll('.btp-policy--wire').forEach(function (el) {
            el.style.display = enabled.indexOf('international_wire') !== -1 ? '' : 'none';
        });
        document.querySelectorAll('.btp-policy--instant').forEach(function (el) {
            el.style.display = enabled.indexOf('instant_alias') !== -1 ? '' : 'none';
        });
    }

    function collectIdentifiers() {
        var ids = {};
        document.querySelectorAll('.btp-scheme-input').forEach(function (input) {
            var value = input.value.trim();
            if (value !== '') {
                ids[input.getAttribute('data-scheme')] = value;
            }
        });
        return ids;
    }

    function buildPayload() {
        var editingId = byId('btp-bank-id').value;
        var notes = Object.assign({}, wizard.packNotes, { prefer_instant: byId('btp-prefer-instant').checked });
        return {
            id: editingId || undefined,
            bank_name: byId('btp-bank-name').value,
            branch_name: byId('btp-branch-name').value,
            currency_code: byId('btp-currency-code').value,
            country_code: byId('btp-country-code').value,
            account_details: byId('btp-account-details').value,
            invoice_label: byId('btp-invoice-label').value,
            account_name: byId('btp-account-name').value,
            account_number: byId('btp-account-number').value,
            capabilities: enabledCapabilities().join(','),
            identifiers: JSON.stringify(collectIdentifiers()),
            beneficiary_address: byId('btp-beneficiary-address').value,
            bank_address: byId('btp-bank-address').value,
            intermediary_bic: byId('btp-intermediary-bic').value,
            prefer_charge_code: byId('btp-prefer-charge-code').value,
            accept_fx_receive: byId('btp-accept-fx').checked ? 1 : 0,
            wire_purpose_hint: byId('btp-wire-purpose-hint').value,
            prefer_instant: byId('btp-prefer-instant').checked ? 1 : 0,
            pack_notes: JSON.stringify(notes)
        };
    }

    function showStep(step) {
        wizard.step = step;
        document.querySelectorAll('.btp-wizard-step').forEach(function (el) {
            el.style.display = parseInt(el.getAttribute('data-btp-step'), 10) === step ? 'block' : 'none';
        });
        document.querySelectorAll('#btp-wizard-steps li').forEach(function (li) {
            li.classList.toggle('active', parseInt(li.getAttribute('data-btp-goto'), 10) === step);
        });
        byId('btp-wizard-back').style.display = step > 1 ? '' : 'none';
        byId('btp-wizard-next').style.display = step < 5 ? '' : 'none';
        byId('btp-save-bank-btn').style.display = step === 5 ? '' : 'none';
        if (step === 3 || step === 4) {
            renderSchemeFields();
        }
    }

    function validateStep(step) {
        if (step === 1) {
            if (!byId('btp-country-code').value) {
                return 'Select the country where this bank account is held.';
            }
            if (!byId('btp-currency-code').value) {
                return 'Select a currency.';
            }
        }
        if (step === 2 && !byId('btp-bank-name').value.trim()) {
            return 'Bank name is required.';
        }
        if (step === 3 && !enabledCapabilities().length && !byId('btp-account-details').value.trim()) {
            return 'Choose at least one way clients can pay.';
        }
        return '';
    }

    function loadPreview() {
        var target = byId('btp-preview');
        target.innerHTML = '<p class="text-muted">Building preview&hellip;</p>';
        return apiRequest('preview', 'POST', buildPayload()).then(function (response) {
            if (!response.success) {
                throw new Error(response.error ? response.error.message : 'Preview failed.');
            }
            target.innerHTML = '';
            ((response.data && response.data.warnings) || []).forEach(function (warning) {
                showAlert('warning', warning, 'modal');
            });
            (response.data.previews || []).forEach(function (preview) {
                var block = document.createElement('div');
                block.className = 'btp-preview';
                block.innerHTML = '<h5></h5><div class="btp-preview__body"></div>';
                block.querySelector('h5').textContent = preview.title;
                block.querySelector('.btp-preview__body').innerHTML = preview.html;
                target.appendChild(block);
            });
        });
    }

    function goToStep(step) {
        hideAlert('modal');
        if (step > wizard.step) {
            for (var s = wizard.step; s < step; s++) {
                var problem = validateStep(s);
                if (problem) {
                    showStep(s);
                    showAlert('danger', problem, 'modal');
                    return;
                }
            }
        }
        if (step === 5) {
            readSchemeInputs();
            loadPreview().then(function () {
                showStep(5);
            }).catch(function (error) {
                showStep(4);
                showAlert('danger', error && error.message ? error.message : 'Preview failed.', 'modal');
            });
            return;
        }
        showStep(step);
    }

    function applyDefaultCapabilities() {
        if (enabledCapabilities().length) {
            return;
        }
        var country = byId('btp-country-code').value;
        byId('btp-cap-local').checked = true;
        if (country === 'IN') {
            byId('btp-cap-instant').checked = true;
        }
    }

    function resetForm() {
        var form = byId('btp-bank-form');
        if (form) {
            form.reset();
        }
        byId('btp-bank-id').value = '';
        wizard.identifiers = {};
        wizard.packNotes = {};
        document.querySelectorAll('.btp-cap-checkbox').forEach(function (box) { box.checked = false; });
        byId('btp-preview').innerHTML = '';
        hideAlert('modal');
        showStep(1);
    }

    function fillForm(bank) {
        byId('btp-bank-id').value = bank.id;
        byId('btp-bank-name').value = bank.bank_name || '';
        byId('btp-branch-name').value = bank.branch_name || '';
        byId('btp-currency-code').value = bank.currency_code || '';
        byId('btp-country-code').value = bank.country_code || '';
        byId('btp-account-details').value = bank.account_details || '';
        byId('btp-invoice-label').value = bank.invoice_label || '';
        byId('btp-account-name').value = bank.account_name || '';
        byId('btp-account-number').value = bank.account_number || '';
        byId('btp-beneficiary-address').value = bank.beneficiary_address || '';
        byId('btp-bank-address').value = bank.bank_address || '';
        byId('btp-intermediary-bic').value = bank.intermediary_bic || '';
        byId('btp-prefer-charge-code').value = bank.prefer_charge_code || 'OUR';
        byId('btp-wire-purpose-hint').value = bank.wire_purpose_hint || '';
        byId('btp-accept-fx').checked = !!bank.accept_fx_receive;
        wizard.identifiers = Object.assign({}, bank.identifiers || {});
        wizard.packNotes = Object.assign({}, bank.pack_notes || {});
        byId('btp-prefer-instant').checked = !!wizard.packNotes.prefer_instant;
        document.querySelectorAll('.btp-cap-checkbox').forEach(function (box) {
            box.checked = (bank.capabilities || []).indexOf(box.getAttribute('data-btp-cap')) !== -1;
        });
        hideAlert('modal');
        showStep(1);
    }

    function initAdminPage() {
        if (initAdminPage.initialized) {
            return;
        }
        initAdminPage.initialized = true;

        loadBanks();

        var addBtn = document.getElementById('btp-add-bank-btn');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                resetForm();
                openModal('Add Bank Details');
            });
        }

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target) {
                return;
            }

            var editBtn = target.closest ? target.closest('.btp-edit-btn') : (target.classList.contains('btp-edit-btn') ? target : null);
            if (editBtn) {
                var editId = editBtn.getAttribute('data-id');
                fetch(apiUrl + '&btp_action=get&id=' + encodeURIComponent(editId), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).then(function (response) {
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

                        throw buildRequestError(response, bodyText);
                    });
                }).then(function (payload) {
                    if (!payload.success || !payload.data || !payload.data.bank) {
                        showAlert('danger', 'Unable to load bank details.');
                        return;
                    }
                    fillForm(payload.data.bank);
                    openModal('Edit Bank Details');
                }).catch(function (error) {
                    showAlert('danger', error && error.message ? error.message : 'Unable to load bank details.');
                });
            }

            var deleteBtn = target.closest ? target.closest('.btp-delete-btn') : (target.classList.contains('btp-delete-btn') ? target : null);
            if (deleteBtn) {
                var deleteId = deleteBtn.getAttribute('data-id');
                if (!window.confirm('Delete this bank account and deactivate its gateway?')) {
                    return;
                }
                apiRequest('delete', 'POST', {
                    id: parseInt(deleteId, 10),
                    confirm_delete: true
                }).then(function (payload) {
                    if (!payload.success) {
                        showAlert('danger', payload.error ? payload.error.message : 'Delete failed.');
                        return;
                    }
                    showAlert('success', payload.message || 'Bank deleted.');
                    loadBanks();
                }).catch(function (error) {
                    showAlert('danger', error && error.message ? error.message : 'Delete failed.');
                });
            }
        });

        var form = document.getElementById('btp-bank-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (wizard.step < 5) {
                    goToStep(wizard.step + 1);
                    return;
                }
                hideAlert('modal');
                var id = document.getElementById('btp-bank-id').value;
                readSchemeInputs();
                var payload = buildPayload();
                var action = id ? 'update' : 'create';
                if (id) {
                    payload.id = parseInt(id, 10);
                }

                apiRequest(action, 'POST', payload).then(function (response) {
                    if (!response.success) {
                        showAlert('danger', response.error ? response.error.message : 'Save failed.', 'modal');
                        return;
                    }
                    showAlert('success', response.message || 'Saved.');
                    closeModal();
                    loadBanks();
                }).catch(function (error) {
                    showAlert('danger', error && error.message ? error.message : 'Save failed.', 'modal');
                });
            });

            byId('btp-wizard-next').addEventListener('click', function () { goToStep(wizard.step + 1); });
            byId('btp-wizard-back').addEventListener('click', function () { goToStep(wizard.step - 1); });
            byId('btp-wizard-steps').addEventListener('click', function (event) {
                var li = event.target && event.target.closest ? event.target.closest('[data-btp-goto]') : null;
                if (li) { goToStep(parseInt(li.getAttribute('data-btp-goto'), 10)); }
            });
            byId('btp-country-code').addEventListener('change', function () {
                applyDefaultCapabilities();
                renderSchemeFields();
            });
            document.querySelectorAll('.btp-cap-checkbox').forEach(function (box) {
                box.addEventListener('change', renderSchemeFields);
            });
        }

        var modal = document.getElementById('btp-bank-modal');
        if (modal) {
            modal.addEventListener('click', function (event) {
                var target = event.target;
                if (!target) {
                    return;
                }

                if (target.matches('[data-dismiss="modal"]') || target.closest('[data-dismiss="modal"]')) {
                    event.preventDefault();
                    closeModal();
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminPage, { once: true });
    } else {
        initAdminPage();
    }
})();
