# Integracja z Kalendarzem Google (umowa §5.9 / §4.4.13)

Po zatwierdzeniu Wydarzenia platforma dodaje je do jednego, wskazanego kalendarza Google (tytuł, data,
miejsce, link do strony wydarzenia). Integracja jest **domyślnie wyłączona** i nic nie wysyła, dopóki
nie są spełnione trzy warunki: (1) serwer ma plik klucza konta usługi, (2) administrator włączył
integrację w panelu, (3) podano ID kalendarza.

## Co robi klient (jednorazowo)

1. W [Google Cloud Console](https://console.cloud.google.com/) utwórz projekt (lub wybierz istniejący)
   i włącz **Google Calendar API** (APIs & Services -> Library).
2. IAM & Admin -> Service Accounts -> **Create service account** (np. `zmierzymyczas-kalendarz`).
   Nie trzeba nadawać roli w projekcie.
3. Na koncie usługi: Keys -> Add key -> **JSON**. Pobrany plik to klucz (sekret - nie wysyłać mailem
   ani nie wkładać do repozytorium; przekazać administratorowi serwera bezpiecznym kanałem).
4. W Kalendarzu Google (calendar.google.com), na kalendarzu docelowym: Ustawienia i udostępnianie ->
   **Udostępnij określonym osobom** -> dodaj adres e-mail konta usługi (`...@...iam.gserviceaccount.com`,
   pole `client_email` z pliku JSON) z uprawnieniem **Wprowadzanie zmian w wydarzeniach**.
5. Z tego samego ekranu skopiuj **Identyfikator kalendarza** (sekcja „Integruj kalendarz”; dla
   kalendarza głównego to adres Gmail).

Alternatywa zgodna z umową: dedykowane konto Google dla platformy, na którym utworzony jest kalendarz.
Konto usługi jest zalecane, bo nie wymaga hasła ani logowania interaktywnego.

## Co robi administrator serwera

1. Umieść plik JSON poza katalogiem publicznym, np. `/home/USER/secrets/google-calendar.json`
   (uprawnienia 600).
2. W `.env` ustaw `GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE=/ścieżka/do/pliku.json`.
3. Upewnij się, że działa scheduler (`bin/scheduler.php`, zob. `docs/scheduler.md`) - zadanie
   `calendar.outbox_retry` faktycznie wysyła wpisy.

Klucz nie trafia do bazy ani do odpowiedzi API; panel pokazuje tylko, czy klucz jest skonfigurowany.

## Co robi administrator platformy

Panel admina -> Ustawienia platformy -> Integracja Google Calendar: wpisz ID kalendarza, ewentualnie
strefę czasową (domyślnie `Europe/Warsaw`) i włącz integrację.

## Zachowanie

- Zatwierdzenie Wydarzenia (`POST /events/{id}/approve`) tylko dopisuje wpis do kolejki
  `google_calendar_outbox`; błąd Google nigdy nie blokuje zatwierdzenia.
- Zadanie `calendar.outbox_retry` wysyła wpisy; przy błędzie ponawia z narastającym opóźnieniem
  (5 min, 10 min, ... do 8 prób, potem `abandoned`).
- Id wpisu Google jest zapamiętane na Wydarzeniu (`events.google_calendar_event_id`). Ręczne
  „Wyślij do kalendarza” (`POST /calendar/events`, body `{ "id": "<id wydarzenia>" }`) aktualizuje
  istniejący wpis zamiast tworzyć duplikat.
- Nie zaimplementowano: automatycznej aktualizacji wpisu po zmianie daty Wydarzenia ani usuwania
  wpisu po odwołaniu - do tej pory służy do tego ręczne „Wyślij do kalendarza” (aktualizacja).
