{include file="tabs.tpl"}

<h2>{$_lang.settings|default:'Settings'}</h2>

{if $settingsError}
    <div class="alert alert-danger">{$settingsError|escape}</div>
{/if}
{if $settingsSaved}
    <div class="alert alert-success">{$_lang.settings_saved|default:'Settings saved.'}</div>
{/if}

<form method="post" action="{$modulelink}&tab=info" class="form-horizontal btp-settings-form">
    <input type="hidden" name="token" value="{$csrfToken}" />

    <div class="form-group">
        <label class="col-sm-3 control-label">Enable payment proof upload</label>
        <div class="col-sm-9">
            <label class="checkbox-inline">
                <input type="checkbox" name="enable_proof_upload" value="on" {if $settings.enable_proof_upload == 'on'}checked{/if} />
                Show upload panel on unpaid Bank Transfer Pro invoices
            </label>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="btp-ticket-dept">Ticket department</label>
        <div class="col-sm-9">
            <select class="form-control" name="ticket_department_id" id="btp-ticket-dept">
                {foreach from=$departments item=dept}
                    <option value="{$dept.id}" {if $settings.ticket_department_id == $dept.id}selected{/if}>{$dept.name}</option>
                {/foreach}
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="btp-ticket-priority">Ticket priority</label>
        <div class="col-sm-9">
            <select class="form-control" name="ticket_priority" id="btp-ticket-priority">
                <option value="Low" {if $settings.ticket_priority == 'Low'}selected{/if}>Low</option>
                <option value="Medium" {if $settings.ticket_priority == 'Medium'}selected{/if}>Medium</option>
                <option value="High" {if $settings.ticket_priority == 'High'}selected{/if}>High</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="btp-subject-template">Ticket subject template</label>
        <div class="col-sm-9">
            <input type="text" class="form-control" name="ticket_subject_template" id="btp-subject-template" value="{$settings.ticket_subject_template|escape}" />
            <p class="help-block">Available placeholders: {literal}{invoiceid}{/literal}, {literal}{invoicenum}{/literal}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="btp-max-upload">Max upload size (MB)</label>
        <div class="col-sm-9">
            <input type="number" min="1" class="form-control" name="max_upload_size_mb" id="btp-max-upload" value="{$settings.max_upload_size_mb|escape}" />
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="btp-allowed-mime">Allowed MIME types</label>
        <div class="col-sm-9">
            <input type="text" class="form-control" name="allowed_mime_types" id="btp-allowed-mime" value="{$settings.allowed_mime_types|escape}" />
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="btp-cooldown">Upload cooldown (hours)</label>
        <div class="col-sm-9">
            <input type="number" min="0" class="form-control" name="upload_cooldown_hours" id="btp-cooldown" value="{$settings.upload_cooldown_hours|escape}" />
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label">Auto-select gateway on invoice creation</label>
        <div class="col-sm-9">
            <label class="checkbox-inline">
                <input type="checkbox" name="auto_select_gateway" value="on" {if $settings.auto_select_gateway == 'on'}checked{/if} />
                Replace generic banktransfer with the matching Bank Transfer Pro gateway (or the static banktransferpro gateway in immutable deployments)
            </label>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </div>
</form>

<hr />

<h3>Invoice emails</h3>
<p class="text-muted">
    Add the recommended payment instructions and the payment reference to the stock invoice emails
    (Invoice Created, Payment Reminder and the three Overdue Notices) for clients paying by Bank Transfer Pro.
    Only the one recommended pack is included; every other way to pay stays on the invoice page.
</p>

{if $emailResult == 'installed'}
    <div class="alert alert-success">Invoice email templates updated ({$emailCount|escape}).</div>
{elseif $emailResult == 'removed'}
    <div class="alert alert-success">Bank Transfer Pro block removed from {$emailCount|escape} invoice email template(s).</div>
{/if}

<p>
    Status: <strong>{$emailTemplates.injected|escape}</strong> of {$emailTemplates.templates|escape} invoice email templates include the payment instructions.
</p>

<form method="post" action="{$modulelink}&tab=info" class="btp-email-form">
    <input type="hidden" name="token" value="{$csrfToken}" />
    <button type="submit" name="btp_email_action" value="install" class="btn btn-default">Add to invoice emails</button>
    <button type="submit" name="btp_email_action" value="remove" class="btn btn-default">Remove from invoice emails</button>
</form>

<p class="help-block">
    Prefer to edit the templates yourself? Place this where the instructions should appear (Configuration &rarr; System Settings &rarr; Email Templates):
</p>
<pre class="btp-email-snippet">{$emailSnippet|escape}</pre>
<p class="help-block">
    Also available: <code>{literal}{$btp_payment_reference}{/literal}</code>, <code>{literal}{$btp_payment_pack}{/literal}</code>
    and the plain-text <code>{literal}{$btp_payment_instructions_text}{/literal}</code>.
</p>
