(function () {
    'use strict';

    var config = window.BTP_ADMIN || {};
    var apiUrl = config.apiUrl;
    var adminToken = config.adminToken;

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

    function showAlert(type, message) {
        var alert = document.getElementById('btp-alert');
        if (!alert) {
            return;
        }
        alert.className = 'alert alert-' + type;
        alert.textContent = message;
        alert.style.display = 'block';
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
                'Accept': 'application/json',
                'X-CSRF-Token': adminToken
            }
        };

        if (payload) {
            payload.token = adminToken;
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
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
            var detailsRaw = String(bank.account_details || '');
            row.innerHTML =
                '<td>' + escapeHtml(bank.id) + '</td>' +
                '<td><code>' + escapeHtml(bank.gateway_slug) + '</code></td>' +
                '<td>' + escapeHtml(bank.display_name) + '</td>' +
                '<td class="btp-account-details" title="' + escapeHtml(detailsRaw) + '">' +
                    escapeHtml(truncate(detailsRaw, 80)) +
                '</td>' +
                '<td>' + escapeHtml(bank.currency_code) + '</td>' +
                '<td class="text-right btp-actions">' +
                    '<button type="button" class="btn btn-default btn-sm btp-edit-btn" data-id="' + escapeHtml(bank.id) + '">Edit</button>' +
                    '<button type="button" class="btn btn-danger btn-sm btp-delete-btn" data-id="' + escapeHtml(bank.id) + '">Delete</button>' +
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

    function resetForm() {
        var form = document.getElementById('btp-bank-form');
        if (form) {
            form.reset();
        }
        document.getElementById('btp-bank-id').value = '';
    }

    function fillForm(bank) {
        document.getElementById('btp-bank-id').value = bank.id;
        document.getElementById('btp-bank-name').value = bank.bank_name;
        document.getElementById('btp-branch-name').value = bank.branch_name;
        document.getElementById('btp-currency-code').value = bank.currency_code;
        document.getElementById('btp-account-details').value = bank.account_details;
    }

    document.addEventListener('DOMContentLoaded', function () {
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

            if (target.classList.contains('btp-edit-btn')) {
                var editId = target.getAttribute('data-id');
                fetch(apiUrl + '&btp_action=get&id=' + encodeURIComponent(editId), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': adminToken }
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

            if (target.classList.contains('btp-delete-btn')) {
                var deleteId = target.getAttribute('data-id');
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
                });
            }
        });

        var form = document.getElementById('btp-bank-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var id = document.getElementById('btp-bank-id').value;
                var payload = {
                    bank_name: document.getElementById('btp-bank-name').value,
                    branch_name: document.getElementById('btp-branch-name').value,
                    currency_code: document.getElementById('btp-currency-code').value,
                    account_details: document.getElementById('btp-account-details').value
                };
                var action = id ? 'update' : 'create';
                if (id) {
                    payload.id = parseInt(id, 10);
                }

                apiRequest(action, 'POST', payload).then(function (response) {
                    if (!response.success) {
                        showAlert('danger', response.error ? response.error.message : 'Save failed.');
                        return;
                    }
                    showAlert('success', response.message || 'Saved.');
                    if (window.jQuery) {
                        window.jQuery('#btp-bank-modal').modal('hide');
                    }
                    loadBanks();
                }).catch(function (error) {
                    showAlert('danger', error && error.message ? error.message : 'Save failed.');
                });
            });
        }
    });
})();
