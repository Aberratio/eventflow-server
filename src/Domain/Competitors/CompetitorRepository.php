<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\QrCode;

/**
 * Zawodnik: profil danych startowych, byt samodzielny — nie własność Konta.
 * Może istnieć bez żadnego powiązanego Konta (np. dziecko zarejestrowane przez rodzica).
 */
final class CompetitorRepository extends Repository
{
    private const COLUMNS = <<<'SQL'
id, first_name AS firstName, last_name AS lastName, birth_date AS birthDate, gender,
email, phone, club, identity_code AS identityCode, status, merged_into_competitor_id AS mergedIntoCompetitorId,
is_anonymized AS isAnonymized, user_id AS userId, created_at AS createdAt, updated_at AS updatedAt
SQL;

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE user_id = :userId');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Zwraca istniejącego Zawodnika powiązanego z Kontem, tworząc go w locie,
     * jeśli konto powstało przed wdrożeniem tego modelu (np. w testach) i jeszcze
     * nie ma swojego profilu Zawodnika.
     *
     * @param array<string, mixed> $user
     */
    public function ensureForUser(array $user): array
    {
        $existing = $this->findByUserId((string) $user['id']);
        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) ($user['name'] ?? ''));
        $spacePos = strrpos($name, ' ');
        $firstName = $spacePos === false ? $name : substr($name, 0, $spacePos);
        $lastName = $spacePos === false ? '' : substr($name, $spacePos + 1);

        return $this->create([
            'firstName' => $firstName !== '' ? $firstName : $name,
            'lastName' => $lastName,
            'birthDate' => $user['birthDate'] ?? null,
            'gender' => $user['gender'] ?? null,
            'email' => $user['email'] ?? null,
            'phone' => $user['phone'] ?? null,
            'club' => $user['club'] ?? null,
            'userId' => $user['id'],
        ], skipDuplicateCheck: true);
    }

    /**
     * Zawodnicy, którzy pasują imieniem, nazwiskiem i datą urodzenia — obrona przed
     * duplikatami jest ostrzeżeniem, nie blokadą (patrz E0.1 AC3).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDuplicates(string $firstName, string $lastName, ?string $birthDate, ?string $excludeId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
            FROM competitors
            WHERE LOWER(first_name) = LOWER(:firstName)
              AND LOWER(last_name) = LOWER(:lastName)
              AND birth_date <=> :birthDate';
        $params = ['firstName' => $firstName, 'lastName' => $lastName, 'birthDate' => $birthDate];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :excludeId';
            $params['excludeId'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> Zawodnik wraz z listą potencjalnych duplikatów w kluczu "duplicateWarnings".
     */
    public function create(array $data, bool $skipDuplicateCheck = false): array
    {
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $birthDate = $data['birthDate'] ?? null;

        $duplicates = $skipDuplicateCheck ? [] : $this->findDuplicates($firstName, $lastName, $birthDate);

        $id = $this->id();
        $this->insert('competitors', [
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'club' => $data['club'] ?? null,
            // E1.12: kod tożsamości — generowany zawsze, tu, przy tworzeniu Zawodnika
            // (nigdy przez BZ, patrz generateIdentityCode()'s docblock), więc nigdy
            // nie ma Zawodnika bez kodu i nie ma stanu "jeszcze nie wygenerowano".
            'identity_code' => $this->generateIdentityCode(),
            'user_id' => $data['userId'] ?? null,
        ]);

        $created = $this->find($id) ?? [];
        $created['duplicateWarnings'] = $duplicates;
        return $created;
    }

    /**
     * E8.1: Moderator/Admin szuka dwóch profili do porównania przed scaleniem.
     * Celowo prosty (imię/nazwisko/kod tożsamości, LIKE) — to nie jest publiczne
     * wyszukiwanie uczestników, tylko narzędzie obsługi do znalezienia podejrzanego
     * duplikatu zgłoszonego przez opiekuna/organizatora (spec Answer §2 krok 1: nie
     * ma formalnego mechanizmu "zgłoszenia", moderator po prostu wyszukuje). Nie
     * zwraca już scalonych profili — te są martwym archiwum, nie kandydatami do
     * kolejnego scalenia.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchForMerge(string $q, int $limit = 20): array
    {
        $needle = trim($q);
        if ($needle === '') {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM competitors
             WHERE status = "active"
               AND (
                 first_name LIKE :needle
                 OR last_name LIKE :needle
                 OR CONCAT(first_name, " ", last_name) LIKE :needle
                 OR identity_code = :exact
               )
             ORDER BY last_name, first_name
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['needle' => '%' . $needle . '%', 'exact' => $needle]);
        $results = $stmt->fetchAll();

        // E8.1 fix-loop: this search box is the one real, existing place in
        // this codebase where a moderator pastes a raw kod tożsamości
        // (`zqr_...`, see the UI's own placeholder text) to find a Zawodnik —
        // there is no separate BZ-facing "resolve a scanned code" HTTP route
        // in this repo (BZ's own scanner/resolution logic lives in the
        // separate biuro_zawodow repo; what this repo serves outbound,
        // bzParticipantList(), already reflects the post-merge survivor
        // correctly because it joins live via registrations.competitor_id,
        // which repointCompetitor() already updates — see
        // RegistrationRepository::bzParticipantList()). The plain query above
        // only matches a still-active competitor's OWN current code, so
        // pasting the OLD code of an already-merged (status = "scalony")
        // profile — exactly the scan-an-old-printed-card scenario
        // resolveIdentityCode() exists for — silently returned nothing.
        // Fall back to the alias-chain-aware lookup so that case resolves to
        // the current survivor instead.
        if (str_starts_with($needle, QrCode::IDENTITY_PREFIX)) {
            $resolved = $this->resolveIdentityCode($needle);
            if ($resolved !== null && !in_array($resolved['id'], array_column($results, 'id'), true)) {
                array_unshift($results, $resolved);
            }
        }

        return $results;
    }

    /**
     * E8.1 (spec Answer §3): odczyt wsteczny — zwraca Zawodnika, do którego
     * dziś faktycznie trafia dany kod tożsamości (`zqr_`), niezależnie od tego,
     * czy ten dokładny kod jest jego WŁASNYM, aktywnym kodem, czy starym aliasem
     * po scaleniu (ewentualnie łańcuchu kilku scaleń — profil A scalony w B,
     * B później scalony w C: kod A ma nadal trafiać w C). Alias nigdy nie jest
     * promowany w UI (spec: "wyłącznie ścieżka odczytu wstecznego") — to jedyne
     * miejsce, które go czyta.
     */
    public function resolveIdentityCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE identity_code = :code');
        $stmt->execute(['code' => $code]);
        $competitor = $stmt->fetch() ?: null;

        if ($competitor === null) {
            $aliasStmt = $this->pdo->prepare('SELECT competitor_id FROM identity_code_aliases WHERE old_identity_code = :code');
            $aliasStmt->execute(['code' => $code]);
            $competitorId = $aliasStmt->fetchColumn();
            if ($competitorId === false) {
                return null;
            }
            $competitor = $this->find((string) $competitorId);
            if ($competitor === null) {
                return null;
            }
        }

        return $this->resolveToActive($competitor);
    }

    /** @param array<string, mixed> $competitor */
    private function resolveToActive(array $competitor, int $depth = 0): ?array
    {
        if ($depth > 20) {
            // Cycle guard — should be structurally impossible (a chain only ever
            // grows by pointing to a *newer* survivor), but a live query loop
            // must never spin forever on corrupted data.
            return null;
        }
        if (($competitor['status'] ?? 'active') !== 'scalony' || empty($competitor['mergedIntoCompetitorId'])) {
            return $competitor;
        }

        $next = $this->find((string) $competitor['mergedIntoCompetitorId']);
        return $next === null ? $competitor : $this->resolveToActive($next, $depth + 1);
    }

    /**
     * E8.1: przełącza wchłonięty profil w stan archiwalny `scalony` z odsyłaczem
     * do przeżywającego (spec Answer §2 krok 7) — nigdy nie jest to usuwane.
     *
     * @return array<string, mixed>|null
     */
    public function markMerged(string $absorbedId, string $survivorId): ?array
    {
        if ($this->find($absorbedId) === null) {
            return null;
        }

        $this->updateById('competitors', $absorbedId, [
            'status' => 'scalony',
            'merged_into_competitor_id' => $survivorId,
        ]);
        return $this->find($absorbedId);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        foreach ([
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'email' => 'email',
            'phone' => 'phone',
            'club' => 'club',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        $this->updateById('competitors', $id, $mapped);
        return $this->find($id);
    }

    /**
     * E6.6 (issue 15 §6): self-service anonymization toggle — a Zawodnik (or
     * whoever has Opieka over them, same authorization circle routes.php
     * already uses for rotateIdentityCode()) can flip this on their own
     * profile. Mirrors regenerateIdentityCode()'s shape deliberately: a
     * single-purpose, narrowly-named method rather than routing this through
     * the generic update() above, so the one field this ticket actually needs
     * writable from a public-ish self-service action isn't bundled with
     * name/birth-date/contact-detail edits that need their own scrutiny.
     */
    public function setAnonymization(string $id, bool $isAnonymized): ?array
    {
        if ($this->find($id) === null) {
            return null;
        }

        $this->updateById('competitors', $id, ['is_anonymized' => $isAnonymized ? 1 : 0]);
        return $this->find($id);
    }

    /**
     * E1.12 (spec 11, "Kod tożsamości"): rotacja na żądanie — Zawodnik sam, albo
     * Konto sprawujące nad nim Opiekę, może w każdej chwili unieważnić stary kod
     * i dostać nowy. Świadomie: bez historii starych kodów (spec §4, "żaden kod
     * nie ma statusu") — po prostu nadpisujemy `identity_code`, stary token po
     * prostu przestaje istnieć gdziekolwiek w bazie, nic więcej nie trzeba robić.
     *
     * Autoryzacja (kto wolno wywołać) to sprawa wywołującego (routes.php, przez
     * `CareRepository::canRegister()` — ta sama relacja Opieki, która pozwala
     * zapisywać Zawodnika na zawody, pozwala też rotować jego kod), nie tego
     * repozytorium — mirror tego, jak `RegistrationRepository` nie zna się na
     * uprawnieniach wywołującego.
     */
    public function regenerateIdentityCode(string $id): ?array
    {
        if ($this->find($id) === null) {
            return null;
        }

        $this->updateById('competitors', $id, ['identity_code' => $this->generateIdentityCode()]);
        return $this->find($id);
    }

    /**
     * E8.1: zapisuje stary kod tożsamości wchłoniętego profilu jako alias
     * wskazujący na przeżywającego — patrz `resolveIdentityCode()` i migracja
     * 059 dla pełnego uzasadnienia (spec Answer §3: nie natychmiastowe
     * unieważnienie, bo scalanie inicjuje obsługa bez wiedzy Zawodnika).
     */
    public function addIdentityCodeAlias(string $oldIdentityCode, string $survivorId): void
    {
        $this->insert('identity_code_aliases', [
            'id' => $this->id(),
            'old_identity_code' => $oldIdentityCode,
            'competitor_id' => $survivorId,
        ]);
    }

    /**
     * E1.12 (spec 11, "Kod tożsamości"): identyczna konstrukcja co E1.11's
     * `generateStartCode()` (`random_bytes(24)`, 192 bity entropii, 48 znaków hex) —
     * różni się wyłącznie prefiksem (`zqr_` zamiast `pqr_`), zgodnie ze spec §3:
     * "Oba kody mają identyczną budowę składniową (...) i różnią się wyłącznie
     * prefiksem", tak żeby Biuro Zawodów mogło rozróżnić kod tożsamości od kodu
     * startowego samym formatem (regex), bez zmiany długości/alfabetu.
     *
     * Ten kod należy do Zawodnika (byt samodzielny, może istnieć bez Konta — patrz
     * `ensureForUser()`), nie do Zapisu ani Konta — dziecko dopisane przez rodzica
     * bez własnego Konta i tak dostaje własny kod tożsamości, bo powstaje razem
     * z wierszem competitors, nie z uwierzytelnieniem.
     */
    private function generateIdentityCode(): string
    {
        return QrCode::identityCode();
    }
}
