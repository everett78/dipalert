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
*   Kolejne etapy (FlareSolverr, tempo odczytów, dzienny e-mail, kolekcje,
    listy ofert z Ceneo) opisuje dokument projektowy autora.

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
