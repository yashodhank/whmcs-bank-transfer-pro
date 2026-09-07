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
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="btp-bank-form" method="post" action="{$modulelink}">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="btp-bank-modal-label">{$_lang.add_bank|default:'Add Bank Details'}</h4>
                </div>
                <div class="modal-body">
                    <div id="btp-bank-modal-alert" class="alert" style="display:none;"></div>
                    <input type="hidden" name="token" value="{$csrfToken}" />
                    <input type="hidden" name="id" id="btp-bank-id" value="" />
                    <div class="form-group">
                        <label for="btp-bank-name">{$_lang.bank_name|default:'Bank Name'} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="btp-bank-name" name="bank_name" required />
                    </div>
                    <div class="form-group">
                        <label for="btp-branch-name">{$_lang.branch_name|default:'Branch Name'}</label>
                        <input type="text" class="form-control" id="btp-branch-name" name="branch_name" />
                    </div>
                    <div class="form-group">
                        <label for="btp-currency-code">{$_lang.currency|default:'Currency'} <span class="text-danger">*</span></label>
                        <select class="form-control" id="btp-currency-code" name="currency_code" required>
                            <option value="">{$_lang.select_currency|default:'Select currency'}</option>
                            {foreach from=$currencies item=currency}
                                <option value="{$currency.code}">{$currency.code}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="btp-account-details">{$_lang.bank_account_details|default:'Bank Account Details'} <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="btp-account-details" name="account_details" rows="6" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{$_lang.cancel|default:'Cancel'}</button>
                    <button type="submit" class="btn btn-primary" id="btp-save-bank-btn">{$_lang.save|default:'Save'}</button>
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
    assetVersion: {$assetVersion|@json_encode nofilter}
};
</script>
<script src="{$adminAssetBaseUrl|escape:'html'}/js/admin.js?v={$assetVersion|escape:'url'}"></script>
