<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/**
 * Sanitizes organizer/admin rich text (TipTap output) on write.
 * Allowlist must match client src/lib/sanitizeHtml.ts (RICH_TEXT_ALLOWED_TAGS / RICH_TEXT_ALLOWED_ATTR),
 * which mirrors the TipTap schema in src/components/ui/rich-text-editor.tsx.
 */
final class HtmlSanitizer
{
    public const ALLOWED = 'p,br,strong,b,em,i,u,s,a[href|target|rel],h2,h3,ul,ol[start],li,blockquote,code,pre,hr';

    private static ?\HTMLPurifier $purifier = null;

    public static function clean(string $html): string
    {
        if ($html === '') {
            return '';
        }
        return self::purifier()->purify($html);
    }

    /** Cleans strings, passes null/non-strings through untouched (callers store whatever the column accepts). */
    public static function cleanNullable(mixed $value): mixed
    {
        return is_string($value) ? self::clean($value) : $value;
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // u, s, ol[start], target need Transitional; outputs <br>
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $cacheDir = self::cacheDirectory();
        if ($cacheDir !== null) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }
        return self::$purifier = new \HTMLPurifier($config);
    }

    /** var/cache/htmlpurifier under the project root (gitignored via /var/); null when not writable. */
    private static function cacheDirectory(): ?string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/var/cache/htmlpurifier';
        if (!is_dir($dir) && is_writable($root)) {
            mkdir($dir, 0775, true); // best effort; fall back to no cache below
        }
        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }
}
