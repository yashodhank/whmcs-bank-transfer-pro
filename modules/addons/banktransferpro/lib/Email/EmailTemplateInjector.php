<?php

declare(strict_types=1);

namespace BankTransferPro\Email;

use WHMCS\Database\Capsule;

/**
 * Opt-in, reversible edit of the stock WHMCS invoice email templates so the recommended
 * pack and payment reference appear without hand-editing Smarty.
 *
 * WHMCS's EmailPreSend hook can only supply merge fields, not rewrite a message body, so
 * the templates themselves must reference the fields. The inserted block is wrapped in
 * markers: install is idempotent and remove restores the original text exactly.
 */
final class EmailTemplateInjector
{
    public const MARKER_START = '<!--btp:payment-instructions-->';
    public const MARKER_END = '<!--/btp:payment-instructions-->';

    /** Stock WHMCS invoice emails that ask the client to pay. */
    public const TEMPLATES = [
        'Invoice Created',
        'Invoice Payment Reminder',
        'First Invoice Overdue Notice',
        'Second Invoice Overdue Notice',
        'Third Invoice Overdue Notice',
    ];

    public static function snippet(bool $plaintext = false): string
    {
        $field = $plaintext ? InvoiceEmailRenderer::FIELD_TEXT : InvoiceEmailRenderer::FIELD_HTML;

        return self::MARKER_START . '{if isset($' . $field . ') && $' . $field . '}{$' . $field . '}{/if}' . self::MARKER_END;
    }

    public static function isInjected(string $message): bool
    {
        return str_contains($message, self::MARKER_START);
    }

    /**
     * Insert the block before the signature when there is one, otherwise append it.
     * The block is always surrounded by exactly one newline on each side, which remove()
     * strips again, so install followed by uninstall restores the template byte for byte.
     */
    public static function inject(string $message, bool $plaintext = false): string
    {
        if (self::isInjected($message)) {
            return $message;
        }

        $block = "\n" . self::snippet($plaintext) . "\n";
        $position = strpos($message, '{$signature}');

        return $position === false
            ? $message . $block
            : substr($message, 0, $position) . $block . substr($message, $position);
    }

    public static function remove(string $message): string
    {
        $core = preg_quote(self::MARKER_START, '/') . '.*?' . preg_quote(self::MARKER_END, '/');
        // Exact inverse of inject(); fall back to the bare block if someone edited the whitespace.
        foreach (['/\n' . $core . '\n/s', '/' . $core . '/s'] as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return (string) preg_replace($pattern, '', $message, 1);
            }
        }

        return $message;
    }

    /**
     * @return array{templates: int, injected: int}
     */
    public function status(): array
    {
        $rows = $this->rows();
        $injected = 0;
        foreach ($rows as $row) {
            if (self::isInjected((string) $row->message)) {
                $injected++;
            }
        }

        return ['templates' => count($rows), 'injected' => $injected];
    }

    /**
     * @return int number of template rows changed
     */
    public function install(): int
    {
        return $this->apply(static fn (string $message, bool $plain): string => self::inject($message, $plain));
    }

    /**
     * @return int number of template rows changed
     */
    public function uninstall(): int
    {
        return $this->apply(static fn (string $message, bool $plain): string => self::remove($message));
    }

    /**
     * @param callable(string, bool): string $transform
     */
    private function apply(callable $transform): int
    {
        $changed = 0;
        foreach ($this->rows() as $row) {
            $original = (string) $row->message;
            $updated = $transform($original, (bool) ($row->plaintext ?? false));
            if ($updated === $original) {
                continue;
            }
            Capsule::table('tblemailtemplates')->where('id', $row->id)->update(['message' => $updated]);
            $changed++;
        }

        return $changed;
    }

    /**
     * @return list<object>
     */
    private function rows(): array
    {
        return Capsule::table('tblemailtemplates')
            ->where('type', 'invoice')
            ->whereIn('name', self::TEMPLATES)
            ->get(['id', 'name', 'message', 'plaintext'])
            ->all();
    }
}
