<ul class="nav nav-tabs btp-nav-tabs">
    <li class="{if $activeTab == 'dashboard'}active{/if}">
        <a href="{$modulelink}&tab=dashboard">{$_lang.dashboard|default:'Dashboard'}</a>
    </li>
    <li class="{if $activeTab == 'info'}active{/if}">
        <a href="{$modulelink}&tab=info">{$_lang.info|default:'Info'}</a>
    </li>
    <li class="{if $activeTab == 'documentation'}active{/if}">
        <a href="{$modulelink}&tab=documentation">{$_lang.documentation|default:'Documentation'}</a>
    </li>
</ul>
