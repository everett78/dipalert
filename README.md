# DipAlert

Osobisty tracker cen dla jednej osoby, uruchamiany na domowym serwerze
TrueNAS. DipAlert jest forkiem projektu
[PriceBuddy](https://github.com/jez500/pricebuddy) autorstwa jez500
(wersja bazowa v1.0.57, licencja GPL-3.0). Zmiany względem oryginału
wymienia plik [NOTICE](NOTICE).

## Czym różni się od PriceBuddy

*   Ustawienia domyślne dla Polski: język polski, strefa
    `Europe/Warsaw`, waluta PLN, linki partnerskie wyłączone.
*   Obraz Dockera publikowany w GitHub Container Registry
    (`ghcr.io/everett78/dipalert`).
*   Kopia bazy co noc i przed każdą migracją (polecenie `dipalert:backup`).
*   Usunięte: wyszukiwarka produktów SearXNG oraz powiadomienia inne niż
    e-mail (ntfy, Gotify, Discord, Apprise, Telegram, Pushover). Historia
    powiadomień w panelu zostaje.
*   Pobieranie stron przez FlareSolverr (metoda sklepu `flaresolverr`),
    blokada adresów sieci domowej (`UrlGuard`), odstęp między zapytaniami
    do jednego sklepu i przerwa po blokadzie (rosnąca, liczona
    w przebiegach), naprawa przez AI tylko przy braku ceny.
*   Powiadomienie o zmianie ceny względem poprzedniego odczytu (procent
    albo kwota, kierunek), zapis każdej próby odczytu, strona „Problemy”
    i dzienny e-mail o stanie („DipAlert: OK”, „uwagi (N)” albo „AWARIA”).
*   Wykres cen z wyborem sklepów i okresu (zapamiętany w produkcie)
    oraz logotypy sklepów w panelu i w e-mailach.
*   Kolekcje produktów (dwa poziomy) z szablonem progów i pól atrybutów;
    produkty tylko w wyłączonych kolekcjach nie są sprawdzane; pulpit
    grupuje produkty według kolekcji (grupowanie po tagach w ustawieniach).
*   Link do produktu na Ceneo daje osobną linię ceny dla każdego sklepu
    z listy ofert. Link bezpośredni ma pierwszeństwo przed ofertą z Ceneo;
    gdy przestaje działać, widoczna staje się oferta z Ceneo („zapas”).
*   Dostawcy AI do naprawy selektorów: oprócz OpenAI, Anthropic, Gemini
    i Ollamy także Groq, OpenRouter i Mistral (klucz API w panelu).
*   Lista ofert Skąpca (etap 6b) powstanie, jeśli Ceneo nie wystarczy.

## AI

Dostawców ustawia się w **Ustawienia → AI**. Proponowana konfiguracja:
Gemini (darmowy plan) jako domyślny i Ollama na TrueNAS jako zapasowy.
Adres Ollamy (np. `192.168.0.30:11434`) musi być w
`DIPALERT_ALLOWED_INTERNAL_HOSTS`, inaczej formularz go odrzuci.

## Ceneo

Dodaj link do produktu na Ceneo jak każdy inny link. Sklep `ceneo.pl` musi
mieć ustawione porównanie (`settings.comparison = ceneo`; migracja ustawia
je dla istniejącego sklepu Ceneo). Każdy odczyt strony Ceneo zapisuje cenę
każdego sklepu z listy ofert jako osobną linię, bez otwierania linków ofert.
Gdy Ceneo zmieni układ strony i lista ofert będzie pusta, odczyt trafi na
stronę „Problemy” jako „brak ceny – pusta lista ofert”. Selektory listy są
w `config/dipalert.php` (`comparison.ceneo`).

## Dzienny e-mail

Polecenie `php artisan dipalert:daily-report` wysyła codziennie (domyślnie
o 07:30, **Ustawienia → Scrape Settings → Dzienny e-mail o stanie**) e-mail
do administratorów:

*   **DipAlert: AWARIA** — któryś przebieg z harmonogramu sprzed co najmniej
    2 h się nie zaczął albo nie zakończył, albo FlareSolverr nie odpowiada;
*   **DipAlert: uwagi (N)** — N linków, których ostatni odczyt się nie udał
    albo został pominięty, plus wstrzymane sklepy;
*   **DipAlert: OK** — w pozostałych przypadkach.

E-mail wymaga włączonej poczty w panelu. Nieudane odczyty z zapisaną stroną
są na stronie **Problemy** (dane i pliki są usuwane po 30 dniach).

## Metoda pobierania sklepu

Każdy sklep ma metodę pobierania w ustawieniach sklepu (**Scraper service**):

*   `http` — zwykłe zapytanie, domyślne dla nowych sklepów;
*   `flaresolverr` — przeglądarka przez FlareSolverr, dla sklepów, które
    blokują zwykłe zapytania. Wymaga `FLARESOLVERR_URL` i hosta FlareSolverr
    w `DIPALERT_ALLOWED_INTERNAL_HOSTS`.

Gdy test sklepu w panelu pokaże „blokada”, wybierz `flaresolverr`
i powtórz test. Aplikacja nie zmienia metody sama.

Sklepy, które wymagają `flaresolverr` (sprawdzone w teście odczytu,
listopad 2026):

*   **Allegro** (`allegro.pl`) — zwykłe zapytanie dostaje stronę DataDome.
*   **Agata Meble** (`agatameble.pl`) — zwykłe zapytanie z aplikacji dostaje
    odpowiedź 403.

Komputronik działa zwykłym zapytaniem (`http`); wcześniejszy brak ceny
w teście był chwilowy.

## Wdrożenie na TrueNAS

1.  Utwórz zbiory danych `dipalert/storage`, `dipalert/db`,
    `dipalert/backups`.
2.  Dodaj aplikację niestandardową z pliku
    [`deploy/truenas/compose.yaml`](deploy/truenas/compose.yaml). Uzupełnij:
    *   `APP_KEY` — wygeneruj raz, np. `echo "base64:$(openssl rand -base64 32)"`,
        i **nie zmieniaj** (szyfruje klucze AI zapisane w ustawieniach);
    *   `APP_USER_EMAIL` i `APP_USER_PASSWORD` — pierwszy administrator,
        tworzony przy pierwszym starcie;
    *   hasła bazy (`DB_PASSWORD` = `MARIADB_PASSWORD`,
        `MARIADB_ROOT_PASSWORD`);
    *   `FLARESOLVERR_URL` i `DIPALERT_ALLOWED_INTERNAL_HOSTS`;
    *   ścieżki zbiorów danych w sekcji `volumes`;
    *   wersję obrazu (`ghcr.io/everett78/dipalert:vX.Y.Z`, nigdy `latest`).
3.  Skieruj trasę tunelu Cloudflare na port aplikacji (domyślnie 8080)
    i ustaw przed nią Cloudflare Access.
4.  Po pierwszym logowaniu ustaw pocztę w panelu: **Ustawienia →
    Powiadomienia → E-mail** (serwer SMTP, login, hasło) i włącz e-mail
    w swoim profilu użytkownika.
5.  Ustaw w TrueNAS zadanie kopiujące zbiór `dipalert/backups` poza serwer
    (Cloud Sync albo replikacja), raz na dobę po 03:00.

## Kopie zapasowe

*   `php artisan dipalert:backup` zapisuje skompresowany zrzut bazy do
    `/backups` jako `dipalert-RRRRMMDD-GGMM-<powód>.sql.gz` i trzyma 14
    ostatnich plików.
*   Harmonogram robi kopię co noc o 03:00, a skrypt startowy przed każdą
    migracją (gdy nowa wersja zmienia bazę). Błąd kopii zatrzymuje start,
    żeby migracja nie przeszła bez kopii.

### Odtworzenie zrzutu

```bash
gunzip -c /backups/dipalert-RRRRMMDD-GGMM-pre-migration.sql.gz | \
  docker exec -i <kontener-bazy> mariadb -u root -p"$MARIADB_ROOT_PASSWORD" dipalert
```

## Cofnięcie wersji

1.  Zatrzymaj aplikację.
2.  Jeśli nowsza wersja zmieniła bazę, odtwórz zrzut `…-pre-migration…`
    zrobiony przy jej starcie (migracje działają tylko w przód).
3.  W compose wpisz poprzednią wersję obrazu i uruchom aplikację.

## Rozwój

Testy wymagają PHP 8.4 i MariaDB. Bez lokalnego PHP można je uruchomić
w kontenerach (obraz testowy PriceBuddy):

```bash
docker network create dipalert-test
docker run -d --rm --name tests_db --network dipalert-test \
  -e MARIADB_ROOT_PASSWORD=password -e MARIADB_DATABASE=tests_db mariadb:11
docker run --rm -it --network dipalert-test -v "$PWD":/app -w /app \
  jez500/pricebuddy-tests-8.4:latest bash -c \
  "composer install --no-scripts && cp .env.ci .env && sed -i 's/DB_HOST=mysql/DB_HOST=tests_db/' .env && php artisan key:generate && php -d memory_limit=512M artisan test -p"
```

## Licencja

GPL-3.0, jak oryginał. Zob. [LICENSE](LICENSE) i [NOTICE](NOTICE).
