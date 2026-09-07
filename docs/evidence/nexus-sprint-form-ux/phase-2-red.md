# Gate 2 — Red PHPUnit contracts

Recorded after adding `tests/CsrfUxContractTest.php` and fixing `HooksTest` to follow `BTP_ADDON_ASSET_VERSION`.

## Command

```
vendor/bin/phpunit --testdox
```

## Result (pre-implementation)

- **Tests: 39, Failures: 14** (all new `CsrfUxContract` cases)
- Existing suite remains runnable and green, including:
  - `AdminDashboardTemplate` modal-alert and asset-path guards
  - `Hooks` asset version now matches `BTP_ADDON_ASSET_VERSION` (`1.1.1`)

## Red contracts (expected)

1. Settings CSRF namespace is `WHMCS.admin.default`
2. Settings PRG (`saved=1`) + success alert in Info template
3. Admin mutations are form-encoded (`$_POST['token']`), not JSON
4. `list` / `get` do not CSRF before action dispatch
5. API `assertCsrf` catches `ProgramExit`/`Throwable` and returns JSON `CSRF_FAILED`
6. Lang file is flat `$_ADDONLANG['key'] = 'string'`
7. Admin CSS gated to `addonmodules` + `module=banktransferpro`
8. Client CSS gated to `viewinvoice`
9. Proof HTML is not concatenated onto `paymentmethod`
10. Delete AJAX has `.catch`
11. Bank modal has `method="post"` and hidden `token`
12. Proof upload invoice id is POST-only
13. Proof-form JS parses `response.text()` then JSON
14. Upload `assertCsrf` catches token-exit into JSON

These failures are missing product behavior, not test typos.
