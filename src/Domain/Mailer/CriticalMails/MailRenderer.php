<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

/**
 * Closed-list variable substitution (17-katalog-maili §5): a template body is
 * plain HTML with `{{variableName}}` placeholders, and each mail type has its
 * own fixed set of variables it may reference (MailCatalog::variablesFor()).
 *
 * Two different safety calls at two different times, deliberately:
 *  - assertOnlyKnownVariables() runs at *save* time (MailTemplateRepository) —
 *    an admin/organizer typing `{{totalyWrongName}}` should be told immediately,
 *    not discover it as a silently blank spot in a live email.
 *  - render() runs at *send* time and is intentionally lenient: a placeholder
 *    that isn't in $variables is left untouched rather than throwing, so a
 *    template saved under an older catalog version (fewer variables) never
 *    breaks a live send — see CriticalMailer's determinism note on §6.
 */
final class MailRenderer
{
    /**
     * @param array<int, string> $allowedVariables
     * @throws \InvalidArgumentException listing every unknown placeholder found
     */
    public function assertOnlyKnownVariables(string $subject, string $bodyHtml, array $allowedVariables): void
    {
        $used = array_unique(array_merge(
            self::extractVariables($subject),
            self::extractVariables($bodyHtml)
        ));
        $unknown = array_values(array_diff($used, $allowedVariables));

        if ($unknown !== []) {
            throw new \InvalidArgumentException('unknown_template_variables: ' . implode(', ', $unknown));
        }
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    public function render(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $match) use ($variables): string {
                $name = $match[1];
                if (!array_key_exists($name, $variables) || $variables[$name] === null) {
                    // Unknown/unset at render time: left as-is rather than blanked out,
                    // so a missing variable is visibly wrong (easy to spot in a preview)
                    // instead of silently disappearing.
                    return $match[0];
                }

                return htmlspecialchars((string) $variables[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $template
        );
    }

    /** @return array<int, string> */
    public static function extractVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
