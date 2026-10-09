{include file="tabs.tpl"}

<div class="btp-dashboard-header">
    <h2>{$_lang.bank_accounts|default:'Bank Accounts'}</h2>
    <button type="button" class="btn btn-primary" id="btp-add-bank-btn">
        <i class="fas fa-plus"></i> {$_lang.add_bank|default:'Add Bank Details'}
    </button>
</div>

<div id="btp-alert" class="alert" style="display:none;"></div>

<div class="table-responsive">
    <table class="table table-striped" id="btp-banks-table">
        <thead>
            <tr>
                <th>{$_lang.id|default:'ID'}</th>
                <th>{$_lang.gateway_name|default:'Gateway Name'}</th>
                <th>{$_lang.display_name|default:'Display Name'}</th>
                <th>{$_lang.account_details|default:'Account Details'}</th>
                <th>{$_lang.currency|default:'Currency'}</th>
                <th class="text-right">{$_lang.actions|default:'Actions'}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<div class="modal fade" id="btp-bank-modal" tabindex="-1" role="dialog" aria-labelledby="btp-bank-modal-label">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="btp-bank-form" method="post" action="{$modulelink}" novalidate>
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="btp-bank-modal-label">{$_lang.add_bank|default:'Add Bank Details'}</h4>
                    <ol class="btp-wizard-steps" id="btp-wizard-steps">
                        <li data-btp-goto="1" class="active">1. Where is this bank?</li>
                        <li data-btp-goto="2">2. Account identity</li>
                        <li data-btp-goto="3">3. How can clients pay?</li>
                        <li data-btp-goto="4">4. Policies</li>
                        <li data-btp-goto="5">5. Preview</li>
                    </ol>
                </div>
                <div class="modal-body">
                    <div id="btp-bank-modal-alert" class="alert" style="display:none;"></div>
                    <input type="hidden" name="token" value="{$csrfToken}" />
                    <input type="hidden" name="id" id="btp-bank-id" value="" />

                    <div class="btp-wizard-step" data-btp-step="1">
                        <p class="text-muted">One receive profile per bank account. Local transfer, instant payment and international wire all live on the same profile.</p>
                        <div class="form-group">
                            <label for="btp-country-code">Country of the bank account <span class="text-danger">*</span></label>
                            <select class="form-control" id="btp-country-code" name="country_code">
                                <option value="">Select country</option>
                                {foreach from=$countries key=countryCode item=countryName}
                                    <option value="{$countryCode|escape:'html'}">{$countryName|escape:'html'} ({$countryCode|escape:'html'})</option>
                                {/foreach}
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="btp-currency-code">{$_lang.currency|default:'Currency'} <span class="text-danger">*</span></label>
                            <select class="form-control" id="btp-currency-code" name="currency_code" required>
                                <option value="">{$_lang.select_currency|default:'Select currency'}</option>
                                {foreach from=$currencies item=currency}
                                    <option value="{$currency.code}">{$currency.code}</option>
                                {/foreach}
                            </select>
                            <p class="help-block">Only currencies configured in WHMCS are listed. Clients are matched to this bank by their invoice currency.</p>
                        </div>
                    </div>

                    <div class="btp-wizard-step" data-btp-step="2" style="display:none;">
                        <div class="form-group">
                            <label for="btp-bank-name">{$_lang.bank_name|default:'Bank Name'} <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="btp-bank-name" name="bank_name" required />
                        </div>
                        <div class="form-group">
                            <label for="btp-branch-name">{$_lang.branch_name|default:'Branch Name'}</label>
                            <input type="text" class="form-control" id="btp-branch-name" name="branch_name" />
                        </div>
                        <div class="form-group">
                            <label for="btp-account-name">Legal beneficiary / account name</label>
                            <input type="text" class="form-control" id="btp-account-name" name="account_name" placeholder="{$_lang.account_name_placeholder|default:'SECURIACE TECHNOLOGIES'}" />
                            <p class="help-block">Exactly as the bank holds it. Clients see it as the account / payee name and it is carried in the UPI payment link. Older profiles saved without it can still be edited, but add it as soon as you can.</p>
                        </div>
                        <div class="form-group">
                            <label for="btp-account-number">Account number</label>
                            <input type="text" class="form-control" id="btp-account-number" name="account_number" autocomplete="off" />
                            <p class="help-block">For IBAN countries you can leave this empty and enter the IBAN on the next step.</p>
                        </div>
                        <div class="form-group">
                            <label for="btp-invoice-label">{$_lang.invoice_label|default:'Invoice Label'}</label>
                            <input type="text" class="form-control" id="btp-invoice-label" name="invoice_label" placeholder="{$_lang.invoice_label_placeholder|default:'IDBI Bank - Nanded'}" />
                            <p class="help-block">Shown to clients as &ldquo;Pay via &hellip;&rdquo;. Defaults to bank name and branch.</p>
                        </div>
                    </div>

                    <div class="btp-wizard-step" data-btp-step="3" style="display:none;">
                        <p class="text-muted">Tick every way this account can receive money. Only the fields each option needs will appear.</p>

                        <div class="btp-cap-block">
                            <label class="btp-cap-toggle"><input type="checkbox" class="btp-cap-checkbox" data-btp-cap="local_transfer" id="btp-cap-local" /> <strong>Local bank transfer</strong> <span class="text-muted">&mdash; clients in the bank&rsquo;s country</span></label>
                            <div class="btp-cap-fields" id="btp-cap-fields-local_transfer" style="display:none;"></div>
                        </div>

                        <div class="btp-cap-block">
                            <label class="btp-cap-toggle"><input type="checkbox" class="btp-cap-checkbox" data-btp-cap="instant_alias" id="btp-cap-instant" /> <strong>Pay in seconds</strong> <span class="text-muted">&mdash; UPI, PayNow, Pix, PayID&hellip; (in-country only)</span></label>
                            <div class="btp-cap-fields" id="btp-cap-fields-instant_alias" style="display:none;"></div>
                        </div>

                        <div class="btp-cap-block">
                            <label class="btp-cap-toggle"><input type="checkbox" class="btp-cap-checkbox" data-btp-cap="international_wire" id="btp-cap-wire" /> <strong>International wire</strong> <span class="text-muted">&mdash; clients abroad (SWIFT)</span></label>
                            <div class="btp-cap-fields" id="btp-cap-fields-international_wire" style="display:none;">
                                <div class="btp-scheme-slot" id="btp-scheme-slot-international_wire"></div>
                                <div class="form-group">
                                    <label for="btp-beneficiary-address">Beneficiary address <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="btp-beneficiary-address" name="beneficiary_address" rows="2"></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="btp-bank-address">Bank address</label>
                                    <textarea class="form-control" id="btp-bank-address" name="bank_address" rows="2"></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="btp-intermediary-bic">Intermediary BIC <span class="text-muted">optional</span></label>
                                    <input type="text" class="form-control" id="btp-intermediary-bic" name="intermediary_bic" maxlength="11" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="btp-wizard-step" data-btp-step="4" style="display:none;">
                        <div class="form-group btp-policy btp-policy--wire">
                            <label for="btp-prefer-charge-code">Wire charge code shown to clients</label>
                            <select class="form-control" id="btp-prefer-charge-code" name="prefer_charge_code">
                                <option value="OUR">OUR &mdash; sender pays all fees (recommended)</option>
                                <option value="SHA">SHA &mdash; shared fees</option>
                                <option value="BEN">BEN &mdash; beneficiary pays fees</option>
                            </select>
                        </div>
                        <div class="form-group btp-policy btp-policy--wire">
                            <label for="btp-wire-purpose-hint">Wire purpose hint <span class="text-muted">optional</span></label>
                            <input type="text" class="form-control" id="btp-wire-purpose-hint" name="wire_purpose_hint" maxlength="255" placeholder="e.g. P0802 - Software consultancy / services export" />
                            <p class="help-block">Shown as &ldquo;If your bank asks for the purpose of remittance, use &hellip;&rdquo;. FIRC/FIRA is handled by your bank, not clients.</p>
                        </div>
                        <div class="checkbox btp-policy btp-policy--wire">
                            <label><input type="checkbox" id="btp-accept-fx" name="accept_fx_receive" value="1" /> Accept a different send currency (we convert on receipt)</label>
                        </div>
                        <div class="checkbox btp-policy btp-policy--instant">
                            <label><input type="checkbox" id="btp-prefer-instant" name="prefer_instant" value="1" /> Recommend instant payment first for in-country clients</label>
                        </div>
                        <div class="form-group">
                            <label for="btp-account-details">Additional instructions <span class="text-muted">optional</span></label>
                            <textarea class="form-control" id="btp-account-details" name="account_details" rows="3"></textarea>
                            <p class="help-block">Free text shown with the local transfer pack only. Lines that repeat structured values are hidden automatically.</p>
                        </div>
                    </div>

                    <div class="btp-wizard-step" data-btp-step="5" style="display:none;">
                        <p class="text-muted">This is exactly what clients see on an invoice, for three payer situations.</p>
                        <div id="btp-preview"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{$_lang.cancel|default:'Cancel'}</button>
                    <button type="button" class="btn btn-default" id="btp-wizard-back" style="display:none;">Back</button>
                    <button type="button" class="btn btn-primary" id="btp-wizard-next">Next</button>
                    <button type="submit" class="btn btn-success" id="btp-save-bank-btn" style="display:none;">{$_lang.save|default:'Save'}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.BTP_ADMIN = {
    moduleLink: {$modulelink|@json_encode nofilter},
    apiUrl: {$modulelink|cat:"&action=api"|@json_encode nofilter},
    csrfToken: {$csrfToken|@json_encode nofilter},
    assetBaseUrl: {$adminAssetBaseUrl|@json_encode nofilter},
    assetVersion: {$assetVersion|@json_encode nofilter},
    registry: {$registry|@json_encode nofilter}
};
</script>
<script src="{$adminAssetBaseUrl|escape:'html'}/js/admin.js?v={$assetVersion|escape:'url'}"></script>
