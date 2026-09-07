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
            row.innerHTML =
                '<td>' + escapeHtml(bank.id) + '</td>' +
                '<td><code>' + escapeHtml(bank.gateway_slug) + '</code></td>' +
                '<td>' + escapeHtml(bank.display_name) + '</td>' +
                '<td class="btp-account-details" title="' + escapeHtml(detailsRaw) + '">' +
                    escapeHtml(truncate(detailsRaw, 80)) +
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

    function resetForm() {
        var form = document.getElementById('btp-bank-form');
        if (form) {
            form.reset();
        }
        document.getElementById('btp-bank-id').value = '';
        hideAlert('modal');
    }

    function fillForm(bank) {
        document.getElementById('btp-bank-id').value = bank.id;
        document.getElementById('btp-bank-name').value = bank.bank_name;
        document.getElementById('btp-branch-name').value = bank.branch_name;
        document.getElementById('btp-currency-code').value = bank.currency_code;
        document.getElementById('btp-account-details').value = bank.account_details;
        document.getElementById('btp-invoice-label').value = bank.invoice_label || '';
        document.getElementById('btp-upi-id').value = bank.upi_id || '';
        document.getElementById('btp-account-name').value = bank.account_name || '';
        document.getElementById('btp-account-number').value = bank.account_number || '';
        document.getElementById('btp-ifsc-code').value = bank.ifsc_code || '';
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
                hideAlert('modal');
                var id = document.getElementById('btp-bank-id').value;
                var payload = {
                    bank_name: document.getElementById('btp-bank-name').value,
                    branch_name: document.getElementById('btp-branch-name').value,
                    currency_code: document.getElementById('btp-currency-code').value,
                    account_details: document.getElementById('btp-account-details').value,
                    invoice_label: document.getElementById('btp-invoice-label').value,
                    upi_id: document.getElementById('btp-upi-id').value,
                    account_name: document.getElementById('btp-account-name').value,
                    account_number: document.getElementById('btp-account-number').value,
                    ifsc_code: document.getElementById('btp-ifsc-code').value
                };
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
