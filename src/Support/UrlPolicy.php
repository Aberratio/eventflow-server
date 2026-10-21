<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/**
 * Write-side validation of user-supplied URL fields. Mirrors client src/lib/safeUrl.ts
 * (same LINK_SCHEMES, HOST_LIKE and shared test corpus). HtmlSanitizer takes its allowed
 * schemes from LINK_SCHEMES, so rich text and plain URL fields share one allowlist.
 *
 * Path grammar used by the rule sets: segments separated by ".";
 *   name    object key (matched spelling-insensitively: route_gpx_url == routeGpxUrl)
 *   name[]  iterate the list at that key (as the last segment: each element is a URL leaf)
 *   *       iterate every value of a map
 */
final class UrlPolicy
{
    public const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];
    public const FRAME_SCHEMES = ['http', 'https'];
    public const LINK = 'link';
    public const FRAME = 'frame';

    /** Bare host typed without a scheme, e.g. "www.example.pl/zapisy" (kept identical to client HOST_LIKE). */
    private const HOST_LIKE = '~^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?::\d{1,5})?(?:[/?#]\S*)?$~i';
    private const CONTROL_CHARS = '/[\x00-\x1F\x7F]/';
    private const SAME_ORIGIN_RELATIVE = '~^(?:/(?![/\\\\])|[#?])~';
    private const SCHEME = '~^([a-z][a-z0-9+.-]*):~i';

    /** @var array<string, array{0: string, 1: string}> path => [kind, Polish field label] */
    public const EVENT = [
        'registrationEmbed.externalUrl' => [self::FRAME, 'Adres zewnętrznego formularza zapisów'],
        'locationMapsUrl' => [self::LINK, 'Link do mapy lokalizacji'],
        'imageUrl' => [self::LINK, 'Zdjęcie wydarzenia'],
        'originalImageUrl' => [self::LINK, 'Zdjęcie wydarzenia'],
        'liveResultsUrl' => [self::LINK, 'Link do wyników na żywo'],
        'routeMapUrl' => [self::LINK, 'Mapa trasy'],
        'routeGpxUrl' => [self::LINK, 'Plik GPX trasy'],
        'routeElevationProfile' => [self::LINK, 'Profil przewyższeń'],
        'bannerUrl' => [self::LINK, 'Baner wydarzenia'],
        'originalBannerUrl' => [self::LINK, 'Baner wydarzenia'],
        'organizerLogoUrl' => [self::LINK, 'Logo organizatora'],
        'originalOrganizerLogoUrl' => [self::LINK, 'Logo organizatora'],
        'organizerContact.website' => [self::LINK, 'Strona www organizatora'],
        'organizerInfo.sponsors[].url' => [self::LINK, 'Link sponsora'],
        'organizerInfo.sponsors[].logoUrl' => [self::LINK, 'Logo sponsora'],
        'organizerInfo.sponsors[].logoOriginalUrl' => [self::LINK, 'Logo sponsora'],
        'organizerInfo.coOrganizers[].logoUrl' => [self::LINK, 'Logo współorganizatora'],
        'organizerInfo.coOrganizers[].logoOriginalUrl' => [self::LINK, 'Logo współorganizatora'],
        'logisticsInfo.*.locations[].mapsUrl' => [self::LINK, 'Link do mapy (informacje organizacyjne)'],
        'distances[].routeMapUrl' => [self::LINK, 'Pliki trasy dystansu'],
        'distances[].routeGpxUrl' => [self::LINK, 'Pliki trasy dystansu'],
        'distances[].routeElevationProfile' => [self::LINK, 'Pliki trasy dystansu'],
        'distances[].routeFiles[].url' => [self::LINK, 'Pliki trasy dystansu'],
        'distances[].documents[].fileUrl' => [self::LINK, 'Pliki trasy dystansu'],
        'merchandise[].imageUrl' => [self::LINK, 'Zdjęcie gadżetu'],
        'merchandise[].images[].url' => [self::LINK, 'Zdjęcie gadżetu'],
        'merchandise[].documents[].fileUrl' => [self::LINK, 'Dokument gadżetu'],
        'galleryAlbums[].url' => [self::LINK, 'Link do galerii'],
        'documents[].fileUrl' => [self::LINK, 'Dokument wydarzenia'],
    ];

    public const USER = [
        'website' => [self::LINK, 'Strona www'],
        'avatar' => [self::LINK, 'Zdjęcie profilowe'],
    ];

    public const PARTNER = [
        'logoUrl' => [self::LINK, 'Logo partnera'],
        'logoDarkUrl' => [self::LINK, 'Logo partnera (wersja ciemna)'],
        'websiteUrl' => [self::LINK, 'Strona www partnera'],
    ];

    public const EVENT_BACKGROUND = [
        'imageUrl' => [self::LINK, 'Obraz tła'],
        'thumbnailUrl' => [self::LINK, 'Miniatura tła'],
    ];

    public const SHOP_PRODUCT = [
        'imageUrl' => [self::LINK, 'Zdjęcie produktu'],
        'images[]' => [self::LINK, 'Zdjęcie produktu'],
    ];

    public const CHALLENGE = [
        'imageUrl' => [self::LINK, 'Zdjęcie wyzwania'],
    ];

    public const PROOF = [
        'proofUrl' => [self::LINK, 'Link do dowodu'],
    ];

    public const RESULT_DOCUMENT = [
        'fileUrl' => [self::LINK, 'Plik z wynikami'],
    ];

    public const INFO_PAGE = [
        'content[].items[].link' => [self::LINK, 'Link w sekcji strony'],
        'content[].items[].image' => [self::LINK, 'Obraz w sekcji strony'],
    ];

    /**
     * Validates a single URL value.
     *
     * null and '' are ok and kept as they are (clearing a field is legitimate). Non-strings are not ok.
     * Otherwise the value is trimmed; a bare host gets an https:// prefix.
     *
     * @return array{ok: bool, value: mixed} normalized value or ok=false
     */
    public static function normalize(mixed $value, string $kind = self::LINK): array
    {
        if ($value === null) {
            return ['ok' => true, 'value' => null];
        }
        if (!is_string($value)) {
            return ['ok' => false, 'value' => $value];
        }
        // JS String.trim() equivalent for ASCII whitespace; NUL is deliberately not trimmed.
        $v = trim($value, " \t\n\r\x0B\x0C");
        if ($v === '') {
            return ['ok' => true, 'value' => ''];
        }
        if (preg_match(self::CONTROL_CHARS, $v) === 1) {
            return ['ok' => false, 'value' => $value];
        }
        if ($kind === self::LINK && preg_match(self::SAME_ORIGIN_RELATIVE, $v) === 1) {
            return ['ok' => true, 'value' => $v];
        }
        if (preg_match(self::HOST_LIKE, $v) === 1) {
            return ['ok' => true, 'value' => 'https://' . $v];
        }
        if (preg_match(self::SCHEME, $v, $m) !== 1) {
            return ['ok' => false, 'value' => $value];
        }
        $scheme = strtolower($m[1]);
        $allowed = $kind === self::FRAME ? self::FRAME_SCHEMES : self::LINK_SCHEMES;
        if (!in_array($scheme, $allowed, true)) {
            return ['ok' => false, 'value' => $value];
        }
        if ($scheme === 'http' || $scheme === 'https') {
            if (preg_match('~^https?://~i', $v) !== 1) {
                return ['ok' => false, 'value' => $value];
            }
            $host = parse_url($v, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return ['ok' => false, 'value' => $value];
            }
        }
        return ['ok' => true, 'value' => $v];
    }

    /**
     * Walks every rule path, validates and normalizes in place (on a copy).
     *
     * @param array<string, mixed> $payload
     * @param array<string, array{0: string, 1: string}> $rules
     * @return array{payload: array<string, mixed>, error: ?array{field: string, message: string}}
     */
    public static function apply(array $payload, array $rules): array
    {
        foreach ($rules as $path => [$kind, $label]) {
            $violation = self::walk($payload, explode('.', $path), '', $kind);
            if ($violation !== null) {
                return [
                    'payload' => $payload,
                    'error' => ['field' => $violation, 'message' => self::message($kind, $label)],
                ];
            }
        }
        return ['payload' => $payload, 'error' => null];
    }

    private static function message(string $kind, string $label): string
    {
        if ($kind === self::FRAME) {
            return 'Pole „' . $label . '” musi zawierać adres strony zaczynający się od http:// lub https://.';
        }
        return 'Pole „' . $label . '” musi zawierać poprawny adres internetowy (zaczynający się od http:// lub https://).';
    }

    private static function canon(string|int $key): string
    {
        return strtolower(str_replace('_', '', (string) $key));
    }

    /**
     * A container that arrives as a JSON string is stored verbatim by the repositories, so it is decoded here
     * (and written back as the decoded array) instead of being skipped.
     */
    private static function decodeContainer(mixed $node): mixed
    {
        if (is_string($node)) {
            $t = ltrim($node);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($node, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return $node;
    }

    /**
     * @param list<string> $segments
     * @return ?string concrete path of the first violation, null when all values are fine
     */
    private static function walk(mixed &$node, array $segments, string $path, string $kind): ?string
    {
        if ($segments === []) {
            if ($node === null || is_string($node)) {
                $r = self::normalize($node, $kind);
                if (!$r['ok']) {
                    return $path;
                }
                $node = $r['value'];
                return null;
            }
            return $path;
        }
        $node = self::decodeContainer($node);
        if (!is_array($node)) {
            return null;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            foreach ($node as $key => &$value) {
                $found = self::walk($value, $segments, $path === '' ? (string) $key : $path . '.' . $key, $kind);
                if ($found !== null) {
                    return $found;
                }
            }
            unset($value);
            return null;
        }
        $iterate = str_ends_with($segment, '[]');
        $name = $iterate ? substr($segment, 0, -2) : $segment;
        $wanted = self::canon($name);
        foreach ($node as $key => &$value) {
            if (self::canon($key) !== $wanted) {
                continue;
            }
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            if (!$iterate) {
                $found = self::walk($value, $segments, $childPath, $kind);
                if ($found !== null) {
                    return $found;
                }
                continue;
            }
            $value = self::decodeContainer($value);
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $index => &$item) {
                $found = self::walk($item, $segments, $childPath . '[' . $index . ']', $kind);
                if ($found !== null) {
                    return $found;
                }
            }
            unset($item);
        }
        unset($value);
        return null;
    }
}
