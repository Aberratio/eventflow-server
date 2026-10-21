<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use ZmierzymyCzas\Support\HtmlSanitizer;
use ZmierzymyCzas\Support\InputRules;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * PP.59: the single place where a client-sent event child row (distance, payment method,
 * merchandise, discount code, gallery album, document, pricing variant) is turned into column
 * values. No SQL identifier is ever derived from input: every column name below is a constant,
 * input keys are only looked up in the specs, and anything else is reported back as an unknown key
 * (dropped by the caller, logged by name only).
 *
 * Pure: no database access. normalize() validates every allowlisted value (NOT NULL, ENUM, length,
 * date, range, JSON shape) and returns DB-ready values, so the caller can validate a whole request
 * before the first write.
 */
final class EventChildSchema
{
    /** Processing order matters: distances first, because merchandise/discount codes reference their ids. */
    public const COLLECTIONS = [
        'distances' => 'event_distances',
        'paymentMethods' => 'event_payment_methods',
        'merchandise' => 'event_merchandise',
        'discountCodes' => 'event_discount_codes',
        'galleryAlbums' => 'event_gallery_albums',
        'documents' => 'event_documents',
    ];

    public const PRICING_VARIANTS = 'pricingVariants';
    public const PRICING_VARIANTS_TABLE = 'event_distance_pricing_variants';

    /** Polish label of the whole collection (used when it is not a list / an item is not an object). */
    public const COLLECTION_LABELS = [
        'distances' => 'Dystanse',
        'paymentMethods' => 'Metody płatności',
        'merchandise' => 'Gadżety',
        'discountCodes' => 'Kody rabatowe',
        'galleryAlbums' => 'Albumy galerii',
        'documents' => 'Dokumenty',
        'pricingVariants' => 'Warianty opłat',
    ];

    private const INT_MAX = 2147483647;
    private const BYTES_TEXT = 65535;
    private const BYTES_MEDIUMTEXT = 16777215;

    // field => [column, type, label, options]
    // types: text! text? enum! enum!d enum? num! num!d num? int? int!d bool!d date? json? ids? html?
    // options: max (chars; bytes for html?/text with 'bytes'), values (enum), default, scale/limit (num), system (bool)
    private const SPECS = [
        'distances' => [
            'name' => ['name', 'text!', 'Nazwa dystansu', ['max' => 180]],
            'length' => ['length', 'num?', 'Długość dystansu', ['limit' => 9999999.999, 'scale' => 3]],
            'unit' => ['unit', 'enum!', 'Jednostka długości', ['values' => ['km', 'm']]],
            'startType' => ['start_type', 'enum!d', 'Rodzaj startu', ['values' => ['individual', 'relay'], 'default' => 'individual']],
            'relayMinTeamSize' => ['relay_min_team_size', 'int?', 'Minimalny skład drużyny', []],
            'relayMaxTeamSize' => ['relay_max_team_size', 'int?', 'Maksymalny skład drużyny', []],
            'relayTeamCompletionDeadline' => ['relay_team_completion_deadline', 'date?', 'Termin kompletowania drużyny', []],
            'relaySwapDeadline' => ['relay_swap_deadline', 'date?', 'Termin wymiany zawodnika w drużynie', []],
            'teamPricingMode' =>['team_pricing_mode', 'enum!d', 'Sposób naliczania opłaty drużynowej', ['values' => ['per_person', 'per_team'], 'default' => 'per_person']],
            'teamAggregationRule' => ['team_aggregation_rule', 'enum?', 'Reguła wyniku drużynowego', ['values' => ['sum', 'best', 'average']]],
            'categories' => ['categories', 'json?', 'Kategorie', []],
            'ageCategories' => ['age_categories', 'json?', 'Kategorie wiekowe', []],
            'specialCategories' => ['special_categories', 'json?', 'Kategorie specjalne', []],
            'limit' => ['participant_limit', 'int?', 'Limit uczestników', []],
            'bibRangeFrom' => ['bib_range_from', 'int?', 'Zakres numerów startowych (od)', []],
            'bibRangeTo' => ['bib_range_to', 'int?', 'Zakres numerów startowych (do)', []],
            'price' => ['price', 'num!d', 'Cena dystansu', ['limit' => 99999999.99, 'scale' => 2, 'default' => 0]],
            'infoHtml' => ['info_html', 'html?', 'Informacje o dystansie', ['bytes' => self::BYTES_TEXT]],
            'priceTiers' => ['price_tiers', 'json?', 'Progi cenowe', []],
            'vipPrices' => ['vip_prices', 'json?', 'Ceny VIP', []],
            'routeMapUrl' => ['route_map_url', 'text?', 'Mapa trasy', ['max' => 512]],
            'routeElevationProfile' => ['route_elevation_profile', 'text?', 'Profil wysokości', ['max' => 512]],
            'routeGpxUrl' => ['route_gpx_url', 'text?', 'Plik GPX', ['max' => 512]],
            'routeFiles' => ['route_files', 'json?', 'Pliki trasy', []],
            // system column: the E1.3 capacity counter, only the trusted seeder may set it
            'registeredCount' => ['registered_count', 'int!d', 'Liczba zapisów', ['default' => 0, 'system' => true]],
        ],
        'pricingVariants' => [
            'name' => ['name', 'text!', 'Nazwa wariantu opłaty', ['max' => 180]],
            'price' => ['price', 'num!', 'Cena wariantu opłaty', ['limit' => 99999999.99, 'scale' => 2]],
            'priceTiers' => ['price_tiers', 'json?', 'Progi wariantu opłaty', []],
            'officeOnly' => ['office_only', 'bool!d', 'Tylko w biurze zawodów', ['default' => 0]],
            'visibleInPublicForm' => ['visible_in_public_form', 'bool!d', 'Widoczny w formularzu', ['default' => 1]],
        ],
        'paymentMethods' => [
            'name' => ['name', 'text!', 'Nazwa metody płatności', ['max' => 180]],
            'enabled' => ['enabled', 'bool!d', 'Aktywna', ['default' => 1]],
        ],
        'merchandise' => [
            'name' => ['name', 'text!', 'Nazwa gadżetu', ['max' => 180]],
            'description' => ['description', 'text?', 'Opis gadżetu', ['bytes' => self::BYTES_TEXT]],
            'descriptionHtml' => ['description_html', 'html?', 'Opis gadżetu (HTML)', ['bytes' => self::BYTES_MEDIUMTEXT]],
            'brand' => ['brand', 'text?', 'Marka', ['max' => 180]],
            'documents' => ['documents', 'json?', 'Dokumenty gadżetu', []],
            'price' => ['price', 'num!d', 'Cena gadżetu', ['limit' => 99999999.99, 'scale' => 2, 'default' => 0]],
            'originalPrice' => ['original_price', 'num?', 'Cena przed obniżką', ['limit' => 99999999.99, 'scale' => 2]],
            'sizes' => ['sizes', 'json?', 'Rozmiary', []],
            'colors' => ['colors', 'json?', 'Kolory', []],
            'imageUrl' => ['image_url', 'text?', 'Zdjęcie gadżetu', ['max' => 512]],
            'images' => ['images', 'json?', 'Galeria gadżetu', []],
            'available' => ['available', 'bool!d', 'Dostępny', ['default' => 1]],
            'stockCount' => ['stock_count', 'int?', 'Stan magazynowy', []],
            'quantityMode' => ['quantity_mode', 'enum!d', 'Tryb ilości', ['values' => ['any', 'max', 'one'], 'default' => 'any']],
            'quantityMax' => ['quantity_max', 'int?', 'Maksymalna ilość', []],
            'distanceIds' => ['distance_ids', 'ids?', 'Dystanse gadżetu', []],
            'saleFrom' => ['sale_from', 'text?', 'Sprzedaż od', ['max' => 64]],
            'saleTo' => ['sale_to', 'text?', 'Sprzedaż do', ['max' => 64]],
        ],
        'discountCodes' => [
            'code' => ['code', 'text!', 'Kod rabatowy', ['max' => 80]],
            'discountType' => ['discount_type', 'enum!', 'Rodzaj rabatu', ['values' => ['percent', 'fixed']]],
            'discountValue' => ['discount_value', 'num!', 'Wartość rabatu', ['limit' => 99999999.99, 'scale' => 2]],
            'validFrom' => ['valid_from', 'text?', 'Ważny od', ['max' => 64]],
            'validTo' => ['valid_to', 'text?', 'Ważny do', ['max' => 64]],
            'maxUses' => ['max_uses', 'int?', 'Limit użyć', []],
            'distanceIds' => ['distance_ids', 'ids?', 'Dystanse kodu', []],
            'active' => ['active', 'bool!d', 'Aktywny', ['default' => 1]],
            // system columns: usage counters checked against max_uses at registration time
            'usedCount' => ['used_count', 'int!d', 'Liczba użyć', ['default' => 0, 'system' => true]],
            'usedBy' => ['used_by', 'json?', 'Lista użyć', ['system' => true]],
        ],
        'galleryAlbums' => [
            'name' => ['name', 'text!', 'Nazwa albumu', ['max' => 180]],
            'url' => ['url', 'text!', 'Adres albumu', ['max' => 512]],
            'platform' => ['platform', 'text!', 'Serwis albumu', ['max' => 64]],
            'photographer' => ['photographer', 'text?', 'Fotograf', ['max' => 180]],
        ],
        'documents' => [
            'name' => ['name', 'text!', 'Nazwa dokumentu', ['max' => 255]],
            'type' => ['type', 'enum!d', 'Rodzaj dokumentu', ['values' => ['regulamin', 'harmonogram', 'mapa', 'informacje', 'wyniki', 'inne'], 'default' => 'inne']],
            'fileUrl' => ['file_url', 'text!', 'Plik dokumentu', ['max' => 512]],
            // blank/absent falls back to the basename of fileUrl, see normalize()
            'fileName' => ['file_name', 'text!', 'Nazwa pliku dokumentu', ['max' => 255]],
            'mimeType' => ['mime_type', 'text!d', 'Typ pliku', ['max' => 128, 'default' => 'application/octet-stream']],
        ],
    ];

    /** Keys a client may legitimately send that are never written and not worth logging. */
    private const IGNORED = [
        'distances' => ['id', 'eventId', 'sortOrder', 'paidCount', 'slotsRemaining', 'participantLimit', 'pricingVariants'],
        'pricingVariants' => ['id', 'distanceId', 'sortOrder'],
        'paymentMethods' => ['id', 'eventId', 'sortOrder'],
        'merchandise' => ['id', 'eventId', 'sortOrder', 'shopProductId'],
        'discountCodes' => ['id', 'eventId', 'sortOrder', 'batchId'],
        'galleryAlbums' => ['id', 'eventId', 'sortOrder'],
        'documents' => ['id', 'eventId', 'sortOrder'],
    ];

    /**
     * @param string $collection one of COLLECTIONS keys or 'pricingVariants'
     * @param array<mixed> $item
     * @return array{columns: array<string, mixed>, unknownKeys: list<string>}
     * @throws InputValidationException field = "$path.$inputKey"
     */
    public static function normalize(string $collection, array $item, string $path, bool $trusted): array
    {
        $spec = self::SPECS[$collection] ?? throw new \InvalidArgumentException('Unknown event child collection.');
        $ignored = self::IGNORED[$collection];
        $columns = [];

        foreach ($spec as $key => [$column, $type, $label, $options]) {
            if (($options['system'] ?? false) && !$trusted) {
                continue;
            }
            $present = array_key_exists($key, $item);
            $value = $present ? $item[$key] : null;
            $field = $path . '.' . $key;

            if ($collection === 'documents' && $key === 'fileName' && self::isBlank($value)) {
                $value = self::basenameOf($item['fileUrl'] ?? null);
            }

            $columns[$column] = self::convert($type, $value, $present, $field, $label, $options);
        }

        $known = array_keys($spec);
        $unknown = [];
        foreach (array_keys($item) as $inputKey) {
            $inputKey = (string) $inputKey;
            if (in_array($inputKey, $known, true) || in_array($inputKey, $ignored, true)) {
                continue;
            }
            $unknown[] = $inputKey;
        }

        return ['columns' => $columns, 'unknownKeys' => $unknown];
    }

    /** @return list<string> columns a client request may SET (untrusted UPDATE/INSERT) */
    public static function clientColumns(string $collection): array
    {
        return self::columnsOf($collection, false);
    }

    /** @return list<string> client columns plus the system columns (trusted seeder only) */
    public static function allColumns(string $collection): array
    {
        return self::columnsOf($collection, true);
    }

    /** @return list<string> */
    private static function columnsOf(string $collection, bool $withSystem): array
    {
        $out = [];
        foreach (self::SPECS[$collection] ?? [] as [$column, , , $options]) {
            if ($withSystem || !($options['system'] ?? false)) {
                $out[] = $column;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    private static function convert(string $type, mixed $value, bool $present, string $field, string $label, array $options): mixed
    {
        $nullable = str_ends_with($type, '?');
        $hasDefault = str_ends_with($type, 'd');
        $required = str_ends_with($type, '!');
        $base = (string) preg_replace('/[!?].*$/', '', $type);

        // absent / null handling shared by all types
        if ($value === null) {
            if ($nullable) {
                return null;
            }
            if ($hasDefault) {
                return $options['default'];
            }
            throw InputRules::required($field, $label);
        }

        return match ($base) {
            'text' => self::text($value, $field, $label, $options, $required, $hasDefault),
            'enum' => self::enum($value, $field, $label, $options, $nullable),
            'num' => self::number($value, $field, $label, $options),
            'int' => self::integer($value, $field, $label),
            'bool' => self::boolean($value, $field, $label),
            'date' => self::date($value, $field, $label),
            'json' => self::json($value, $field, $label),
            'ids' => self::ids($value, $field, $label),
            'html' => self::html($value, $field, $label, $options),
            default => throw new \LogicException('Unknown type ' . $type),
        };
    }

    /** @param array<string, mixed> $options */
    private static function text(mixed $value, string $field, string $label, array $options, bool $required, bool $hasDefault): string
    {
        if (!(is_string($value) || is_int($value) || is_float($value))) {
            throw InputRules::invalid($field, $label);
        }
        $string = (string) $value;
        if (trim($string) === '') {
            if ($required) {
                throw InputRules::required($field, $label);
            }
            if ($hasDefault) {
                return (string) $options['default'];
            }
        }
        self::assertLength($string, $field, $label, $options);

        return $string;
    }

    /** @param array<string, mixed> $options */
    private static function enum(mixed $value, string $field, string $label, array $options, bool $nullable): ?string
    {
        if ($nullable && $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $options['values'], true)) {
            throw InputRules::invalid($field, $label);
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function number(mixed $value, string $field, string $label, array $options): int|float|string
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                throw InputRules::invalid($field, $label);
            }
            $float = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $float = (float) $value;
        } else {
            throw InputRules::invalid($field, $label);
        }
        if (!is_finite($float) || $float < 0) {
            throw InputRules::invalid($field, $label);
        }
        if (isset($options['limit']) && round($float, (int) ($options['scale'] ?? 0)) > $options['limit']) {
            throw InputRules::invalid($field, $label);
        }

        return $value;
    }

    private static function integer(mixed $value, string $field, string $label): int
    {
        $float = null;
        if (is_int($value)) {
            $float = (float) $value;
        } elseif (is_string($value) && $value !== '' && ctype_digit($value)) {
            $float = (float) $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $float = $value;
        }
        if ($float === null || $float < 0 || $float > self::INT_MAX) {
            throw InputRules::invalid($field, $label);
        }

        return (int) $float;
    }

    private static function boolean(mixed $value, string $field, string $label): int
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 0;
        }
        throw InputRules::invalid($field, $label);
    }

    private static function date(mixed $value, string $field, string $label): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw InputRules::invalid($field, $label);
        }

        return $value;
    }

    private static function json(mixed $value, string $field, string $label): ?string
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return null;
            }
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw InputRules::invalid($field, $label);
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw InputRules::invalid($field, $label);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw InputRules::invalid($field, $label);
        }

        return $encoded;
    }

    private static function ids(mixed $value, string $field, string $label): string
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw InputRules::invalid($field, $label);
        }
        $ids = [];
        foreach ($value as $id) {
            if (!(is_string($id) || is_int($id)) || trim((string) $id) === '') {
                throw InputRules::invalid($field, $label);
            }
            $ids[] = (string) $id;
        }
        $encoded = json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw InputRules::invalid($field, $label);
        }

        return $encoded;
    }

    /** @param array<string, mixed> $options */
    private static function html(mixed $value, string $field, string $label, array $options): string
    {
        if (!is_string($value)) {
            throw InputRules::invalid($field, $label);
        }
        $clean = (string) HtmlSanitizer::cleanNullable($value);
        self::assertLength($clean, $field, $label, $options);

        return $clean;
    }

    /** @param array<string, mixed> $options */
    private static function assertLength(string $value, string $field, string $label, array $options): void
    {
        if (isset($options['max']) && mb_strlen($value) > $options['max']) {
            throw self::tooLong($field, $label, (int) $options['max']);
        }
        if (isset($options['bytes']) && strlen($value) > $options['bytes']) {
            throw self::tooLong($field, $label, (int) $options['bytes']);
        }
    }

    private static function tooLong(string $field, string $label, int $max): InputValidationException
    {
        return new InputValidationException('Pole „' . $label . '” może mieć najwyżej ' . $max . ' znaków.', $field);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function basenameOf(mixed $url): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $name = basename($path);

        return $name === '' || $name === '.' || $name === '/' ? null : urldecode($name);
    }
}
