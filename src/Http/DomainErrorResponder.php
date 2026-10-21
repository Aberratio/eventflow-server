<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http;

use Psr\Http\Message\ResponseInterface as Response;
use RuntimeException;
use ZmierzymyCzas\Domain\DomainError;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * The one place where a caught domain failure becomes an error response.
 *
 * A route lists the codes it answers. For each listed code the status comes from the thrown
 * {@see DomainError} category and the message from {@see self::CATALOG}; a route states an override
 * only where its message, status or response code differs from that default, so every deviation
 * stays visible at the call site. A route may also give a fallback that answers every other
 * domain code (for example a generic 422). Without a fallback, a code the route does not list is
 * rethrown, so JsonErrorMiddleware answers 500 and logs it exactly as before.
 *
 * Anything that is not a domain code (a PDOException, a message that is not a snake_case code) is
 * rethrown unchanged by {@see DomainErrorCode::of()}, listed or not.
 */
final class DomainErrorResponder
{
    /** Default Polish/English message per error code (the wording most routes use). */
    public const CATALOG = [
        // Uploads (image wording; documents and logos override a few).
        'file_too_large' => 'Obraz jest za duży. Maksymalny rozmiar pliku to 5 MB.',
        'unsupported_file_type' => 'Nieobsługiwany format obrazu. Wgraj plik JPG, PNG albo WebP.',
        'invalid_image_dimensions' => 'Nie udało się odczytać wymiarów obrazu. Wybierz inny plik.',
        'image_dimensions_too_large' => 'Obraz ma zbyt dużą rozdzielczość. Zmniejsz go i spróbuj ponownie.',
        'upload_dir_unavailable' => 'Nie można teraz zapisać pliku na serwerze.',
        'upload_failed' => 'Nie udało się wgrać pliku. Spróbuj ponownie.',
        'validation_error' => 'Sprawdz poprawność danych i spróbuj ponownie.',
        // Virtual results.
        'virtual_event_required' => 'Wynik można zgłosić tylko dla wydarzenia wirtualnego.',
        'virtual_result_window_closed' => 'Wynik można zgłosić tylko w aktywnym oknie startowym.',
        'virtual_result_registration_required' => 'Nie znaleziono aktywnej rejestracji na ten dystans.',
        'virtual_result_duplicate' => 'Wynik dla tego dystansu został już zgłoszony.',
        // Events.
        'event_limit_exceeded' => 'Organizer has no available event slots',
        'event_submit_no_organizer_credit' => 'No available organizer package credit to submit this event for moderation',
        'event_limit_below_usage' => 'Event limit cannot be lower than organizer current event count',
        // Teams.
        'team_not_found' => 'Team was not found.',
        'team_not_captain' => 'Tylko kapitan drużyny lub organizator wydarzenia może wymienić zawodnika.',
        'team_special_category_locked' => 'This team\'s special category can no longer be changed once the event has started.',
        'team_special_category_invalid' => 'Selected special category is not configured for this distance.',
        'team_swap_deadline_passed' => 'Termin wymiany zawodnika w drużynie minął — skontaktuj się z organizatorem.',
        'team_swap_registration_inactive' => 'Nie można wymienić zawodnika na anulowanym zapisie.',
        'team_swap_competitor_already_registered' => 'Ta osoba jest już zapisana na to wydarzenie.',
        'team_swap_same_person' => 'To ten sam zawodnik, który jest już na tym zapisie.',
        'team_swap_person_required' => 'Podaj istniejącego zawodnika (competitorId) albo dane nowej osoby (newPerson: imię i nazwisko).',
        'team_swap_competitor_not_found' => 'Nie znaleziono zawodnika.',
        'not_authorized_to_register' => 'Nie masz upoważnienia (Opieki) do zapisania tego zawodnika.',
        'registration_team_invite_required' => 'Do tej drużyny można dołączyć tylko przez link zaproszenia od kapitana.',
        'registration_team_full' => 'This team already has its maximum number of members.',
        'registration_team_not_found' => 'Selected team was not found.',
        'registration_team_distance_mismatch' => 'Selected team does not belong to this event/distance.',
        'registration_team_requires_relay_distance' => 'Teams can only be created for a relay distance.',
        'registration_team_name_required' => 'Team name is required to create a team.',
        // Registrations.
        'registration_not_found' => 'Nie znaleziono zapisu.',
        'registration_capacity_exceeded' => 'This distance has reached its participant limit.',
        'registration_distance_invalid' => 'Selected distance is invalid.',
        'registration_not_manual_route' => 'Ten Zapis nie jest na trasie przelewu tradycyjnego.',
        'registration_not_pending' => 'Tylko oczekujący Zapis można oznaczyć jako opłacony.',
        'registration_form_snapshot_not_finished' => 'Korekta snapshotu formularza jest możliwa tylko dla zakończonego Wydarzenia.',
        'transfer_registration_inactive' => 'Ten zapis nie jest już aktywny - nie można go przenieść.',
        'transfer_team_registration' => 'Zapisu w drużynie (sztafecie) nie przenosi się tym działaniem - zmień zawodnika w składzie drużyny (wymiana zawodnika).',
        'transfer_insurance_birth_date_missing' => 'Zapis ma ubezpieczenie NNW - nowa osoba musi mieć pełną datę urodzenia (birthDate), by przeliczyć składkę.',
        'transfer_insurance_invalid' => 'Nie można przeliczyć ubezpieczenia NNW dla nowej osoby.',
        'discount_code_invalid' => 'Discount code is invalid.',
        'discount_code_inactive' => 'Discount code is inactive.',
        'discount_code_not_started' => 'Discount code is not active yet.',
        'discount_code_expired' => 'Discount code has expired.',
        'discount_code_used_up' => 'Discount code usage limit has been reached.',
        'discount_code_distance_mismatch' => 'Discount code does not apply to the selected distance.',
        // Refunds and payments.
        'refund_requires_paid_registration' => 'Only a paid registration can request a refund.',
        'refund_request_not_found' => 'No refund request pending for this registration.',
        'refund_not_approved' => 'The refund must be approved before it can be marked as completed.',
        'retry_requires_expired_registration' => 'Only an expired registration can be retried.',
        'retry_not_available_for_bank_transfer' => 'Payment by bank transfer has no gateway link to retry.',
        'gateway_transaction_unknown' => 'Unknown transaction.',
        // Result imports and exports.
        'empty_import_file' => 'Plik nie zawiera danych do importu.',
        'xlsx_not_supported' => 'Serwer PHP nie obsluguje zapisu XLSX (brak rozszerzenia ZipArchive). Uzyj eksportu CSV.',
        'pdf_text_not_detected' => 'Nie udalo sie odczytac tekstu z PDF. To moze byc skan wymagajacy OCR.',
        'pdf_table_not_detected' => 'Odczytano tekst z PDF, ale nie wykryto wierszy wynikow. Wgraj CSV/XLSX albo popraw dane recznie po konwersji.',
        'result_import_unresolved_rows' => 'Rozwiaz wszystkie problematyczne wiersze przed zatwierdzeniem importu.',
        'result_import_row_missing_name' => 'Wiersz nie zawiera imienia i nazwiska - nie mozna utworzyc Zapisu.',
        'result_import_office_variant_missing' => 'Dystans nie ma Wariantu opłaty oznaczonego jako "dostępny wyłącznie przy zapisie w biurze zawodów". Dodaj go w ustawieniach Dystansu przed dopasowaniem.',
        'result_import_registration_creation_unsupported' => 'Ta operacja nie jest tu dostępna.',
        // Shop.
        'order_invalid_product' => 'One or more products in this order are invalid or no longer available.',
        'order_invalid_delivery_method' => 'Selected delivery method is not recognized.',
        'order_invalid_payment_method' => 'Selected payment method is not recognized.',
        'order_invalid_address' => 'A complete shipping address is required for this delivery method.',
        'order_invalid_invoice_data' => 'Company name and a complete billing address are required to request an invoice.',
        'order_invalid_nip' => 'The provided NIP (tax ID) is not a valid Polish NIP.',
        'order_invalid_quantity' => 'Nieprawidłowa liczba pakietów organizatora (dozwolone 1–10).',
        'order_out_of_stock' => 'One or more products in this order are out of stock.',
        'order_insufficient_stock' => 'Requested quantity exceeds available stock for one or more products.',
        'order_invalid_status' => 'Nieprawidłowy status zamówienia.',
        'order_not_found' => 'Nie znaleziono zamówienia.',
        'order_invalid_transition' => 'Nie można zmienić statusu zamówienia na wybrany.',
        // Challenges.
        'challenge_not_found' => 'Wyzwanie nie istnieje.',
        'challenge_not_joined' => 'Nie dołączono do tego Wyzwania.',
        'challenge_invalid_reward_product' => 'Wskazany produkt nie jest nagrodą tego Wyzwania.',
        'challenge_not_completed' => 'Wyzwanie nie zostało jeszcze ukończone.',
        'challenge_reward_already_claimed' => 'Nagroda dla tego Wyzwania została już odebrana.',
        // Competitors.
        'competitor_already_merged' => 'Jeden z profili jest już scalony.',
        'competitor_not_found' => 'Jeden z profili Zawodnika nie istnieje.',
        // Event team invitations.
        'invitation_not_found' => 'To zaproszenie nie istnieje albo zostało odwołane.',
        'invitation_expired' => 'To zaproszenie wygasło. Poproś organizatora o nowe.',
        'invitation_email_mismatch' => 'To zaproszenie wysłano na inny adres e-mail. Zaloguj się na właściwe konto.',
        'team_member_owner' => 'Jesteś właścicielem tego wydarzenia.',
        'team_member_active' => 'Jesteś już współzarządzającym tego wydarzenia.',
    ];

    /**
     * Answer a caught domain failure, or rethrow it.
     *
     * `$handles` is the set of codes this route answers: a list entry (`'code'`) takes the catalog
     * message and the category status; `'code' => 'message'` overrides the message; `'code' => [...]`
     * overrides any of `message`, `status`, `code` (the response code) and `details`.
     *
     * `$fallback` answers every other domain code with a fixed status and message (the response
     * code stays the thrown one unless the fallback gives `code`). Null means: rethrow.
     *
     * @param array<int|string, string|array{message?: string, status?: int, code?: string, details?: array<string, mixed>}> $handles
     * @param array{message: string, status: int, code?: string}|null $fallback
     *
     * @throws RuntimeException the original exception when it is not a domain code, the route does
     *                          not handle it and has no fallback, or no status can be determined
     */
    public static function respond(Response $response, RuntimeException $e, array $handles = [], ?array $fallback = null): Response
    {
        $code = DomainErrorCode::of($e);

        $override = null;
        $handled = false;
        foreach ($handles as $key => $value) {
            if (is_int($key) && $value === $code) {
                $handled = true;
                break;
            }
            if ($key === $code) {
                $handled = true;
                $override = is_string($value) ? ['message' => $value] : $value;
                break;
            }
        }

        if (!$handled) {
            if ($fallback === null) {
                throw $e;
            }

            return JsonResponder::error($response, $fallback['code'] ?? $code, $fallback['message'], $fallback['status']);
        }

        $status = $override['status'] ?? ($e instanceof DomainError ? $e->httpStatus() : null);
        if ($status === null) {
            // A plain RuntimeException carries no category, so there is no status to answer with.
            throw $e;
        }
        $message = $override['message'] ?? self::CATALOG[$code] ?? throw new \LogicException(sprintf('No message for domain error code "%s": add it to DomainErrorResponder::CATALOG or pass one.', $code));
        $details = $override['details'] ?? ($e instanceof DomainError ? $e->details : []);

        return JsonResponder::error($response, $override['code'] ?? $code, $message, $status, $details);
    }
}
