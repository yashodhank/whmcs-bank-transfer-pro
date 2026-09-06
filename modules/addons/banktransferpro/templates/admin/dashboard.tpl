<ul class="nav nav-tabs btp-nav-tabs">
    <li class="{if $activeTab == 'dashboard'}active{/if}">
        <a href="{$modulelink}&tab=dashboard">Dashboard</a>
    </li>
    <li class="{if $activeTab == 'info'}active{/if}">
        <a href="{$modulelink}&tab=info">Info</a>
    </li>
    <li class="{if $activeTab == 'documentation'}active{/if}">
        <a href="{$modulelink}&tab=documentation">Documentation</a>
    </li>
</ul>

<div class="btp-dashboard-header">
    <h2>Bank Accounts</h2>
    <button type="button" class="btn btn-primary" id="btp-add-bank-btn">+ Add Bank Details</button>
</div>

<div id="btp-alert" class="alert" style="display:none;"></div>

<div class="table-responsive">
    <table class="table table-striped" id="btp-banks-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Gateway Name</th>
                <th>Display Name</th>
                <th>Account Details</th>
                <th>Currency</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<div class="modal fade" id="btp-bank-modal" tabindex="-1" role="dialog" aria-labelledby="btp-bank-modal-label">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="btp-bank-form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="btp-bank-modal-label">Add Bank Details</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="btp-bank-id" value="" />
                    <div class="form-group">
                        <label for="btp-bank-name">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="btp-bank-name" name="bank_name" required />
                    </div>
                    <div class="form-group">
                        <label for="btp-branch-name">Branch Name</label>
                        <input type="text" class="form-control" id="btp-branch-name" name="branch_name" />
                    </div>
                    <div class="form-group">
                        <label for="btp-currency-code">Currency <span class="text-danger">*</span></label>
                        <select class="form-control" id="btp-currency-code" name="currency_code" required>
                            <option value="">Select currency</option>
                            {foreach from=$currencies item=currency}
                                <option value="{$currency.code}">{$currency.code}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="btp-account-details">Bank Account Details <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="btp-account-details" name="account_details" rows="6" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btp-save-bank-btn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.BTP_ADMIN = {
    moduleLink: {$modulelink|@json_encode nofilter},
    apiUrl: {$modulelink|cat:"&action=api"|@json_encode nofilter},
    adminToken: {$adminToken|@json_encode nofilter},
    assetBaseUrl: {$adminAssetBaseUrl|@json_encode nofilter},
    assetVersion: {$assetVersion|@json_encode nofilter}
};
</script>
<script src="{$adminAssetBaseUrl|escape:'html'}/js/admin.js?v={$assetVersion|escape:'url'}"></script>
