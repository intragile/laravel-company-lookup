# Laravel Company Lookup

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

Cégkeresés **ingyenes, hivatalos cégnyilvántartókban** egyetlen Laravel-felületen át. Minden
forrás ugyanolyan `Company` objektumot ad vissza.

[English documentation → README.md](README.md)

- **32 forrás, 39 ország, egy felület.** A VIES mind a 27 uniós tagállam ÁFA-számát ellenőrzi.
  A GLEIF világszerte keres LEI-kód alapján. 27 ország nemzeti nyilvántartójához saját driver
  van, többek között Csehországhoz, Szlovákiához, Lengyelországhoz, Romániához,
  Franciaországhoz, Norvégiához, Finnországhoz, Svájchoz, az Egyesült Királysághoz,
  Magyarországhoz (NAV), Dániához, Svédországhoz, Japánhoz, Ausztráliához és az USA-hoz.
- **Csak ingyenes források.** Nagyjából a felükhöz kulcs sem kell. A többihez ingyenes
  regisztrációval lehet kulcsot kérni, és azt a saját `.env`-be kell beírni. Amíg ez nincs meg,
  a driver hiba helyett jelzi, hogy nincs beállítva.
- **Válasz miatt nincs kivétel.** Minden forrás négyféle választ adhat: *talált*, *nincs
  találat*, *nem válaszolt* (okkal és újrapróbálhatósággal), vagy *nincs beállítva*. Ha egy
  nyilvántartó leáll, a többi akkor is válaszol.
- **Éles üzemre kész.**
  - Csak a végleges válaszok kerülnek a gyorsítótárba.
  - A szigorú forrásokhoz (szlovák RPO, lengyel fehérlista, SEC) helyi rate limit van.
  - Átmeneti hibánál egyszer újrapróbálja a hívást.
  - A SOAP-forrásokhoz sem kell `ext-soap`.
  - A `company-lookup:doctor` megmutatja, melyik driver működik.
- **Az éles szolgáltatásokkal tesztelve.** A kulcs nélküli forrásoktól minden fixture élő
  válaszból készült. A `composer test:e2e` minden ilyen forrásban megkeres egy ismert céget.

## Telepítés

```bash
composer require intragile/laravel-company-lookup
php artisan vendor:publish --tag=company-lookup-config   # nem kötelező
```

PHP 8.3+ és Laravel 12 vagy 13 kell hozzá.

## Használat

```php
use IntrAgile\CompanyLookup\Laravel\Facades\CompanyLookup;
use IntrAgile\CompanyLookup\Enums\IdentifierType;

CompanyLookup::vat('CZ45274649')->first()?->name;          // "ČEZ, a. s."
CompanyLookup::lookup('NO', '923609016')->first();          // típus nélkül: kitalálja
CompanyLookup::lookup('HU', '10773381', IdentifierType::Tax); // NAV, saját technikai felhasználóval
CompanyLookup::search('FI', 'nokia', limit: 5)->companies();
CompanyLookup::all()->lookup('CZ', '45274649')->merged();   // minden forrás, összefésülve
```

Tesztben: `CompanyLookup::fake(['HU:10773381' => new Company(...)])`, majd
`$fake->assertLookedUp('HU', '10773381')`.

**ÁFA-számnál a „talált” azt jelenti, hogy a szám érvényes.** Ha ugyanazt a céget
cégjegyzékszámmal keresed, akkor is megjön, csak `vatValid: false` értékkel.

## Kulcsok

| Driver | Ország | Kulcs |
|---|---|---|
| `nav` | HU | A saját NAV Online Számla technikai felhasználód, az [`intragile/laravel-nav-online-szamla`](https://github.com/intragile/laravel-nav-online-szamla) csomagon át |
| `companies-house`, `hmrc` | GB | Companies House és HMRC fejlesztői portál |
| `gus-regon` | PL | GUS-kulcs; teszt-környezetben a nyilvános teszt-kulcs |
| `cro`, `cvr`, `bolagsverket`, `sudreg`, `gemi` | IE, DK, SE, HR, GR | A nyilvántartók ingyenes API-regisztrációja |
| `abn`, `nzbn`, `houjin-bangou`, `ised` | AU, NZ, JP, CA | Ugyanígy |

A pontos környezeti változók a `config/company-lookup.php`-ben vannak. A
`php artisan company-lookup:doctor` driverenként kiírja, mi hiányzik.

## Jó tudni

- **A kulcsos drivereket valódi kulccsal még nem próbáltuk ki.** A hivatalos dokumentáció
  alapján készültek és ellenőriztük őket, ezt a README.md táblázata jelöli. Kivétel a GUS: azt a
  nyilvános teszt-kulccsal élőben teszteltük.
- **A magyar cégjegyzéknek nincs ingyenes API-ja.** Magyar céget a NAV-on (adószám) és a
  VIES-en (közösségi adószám) át lehet lekérdezni, a `registryUrl` pedig az e-cegjegyzek.hu-ra
  mutat.
- **Németország és Spanyolország a VIES-ben nem adja ki a nevet és a címet.** Ilyenkor csak az
  derül ki, hogy a szám érvényes-e.
- **Lettország, Észtország és Belgium** csak letölthető fájlt ad. Ezekhez bekapcsolható a helyi
  import: `COMPANY_LOOKUP_BULK_ENABLED=true`, utána `migrate`, majd naponta
  `company-lookup:import`.
- **A dán CVR-végpont sima HTTP-n megy**, ezért a CVR-jelszó titkosítatlanul utazik. Szükség
  esetén saját TLS-proxyn át is mehet.
- **Több nyilvántartó megköveteli a forrás feltüntetését** (CC BY, NLOD, Licence Ouverte, OGL).
  A pontos szöveg minden drivernél a `capabilities()->attribution` mezőben van.

## Licenc

MIT. Az adatok az egyes nyilvántartók tulajdonát képezik, és azok licence alá tartoznak.

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu).
