<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk\Datasets;

use Generator;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use IntrAgile\CompanyLookup\Bulk\Csv;
use IntrAgile\CompanyLookup\Bulk\DownloadsSource;
use IntrAgile\CompanyLookup\Bulk\Record;
use IntrAgile\CompanyLookup\Bulk\SelectsArchiveMembers;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Belgian Crossroads Bank for Enterprises (KBO/BCE) Open Data. Free, but the files sit behind a login
 * that you register for at kbopub.economie.fgov.be/kbo-open-data.
 *
 * The daily "full" zip (KboOpenData_<extract>_<year>_<month>[_<day>]_Full.zip) holds comma-separated,
 * double-quoted CSV files with dd-mm-yyyy dates (KBO Open Data cookbook R018). This dataset reads
 * enterprise.csv and joins the name from denomination.csv, the registered office from address.csv and the
 * code descriptions from code.csv. The files only list active entities.
 *
 * To keep memory bounded the names and addresses are first staged in a temporary SQLite file, then
 * enterprise.csv is streamed and joined row by row.
 */
final class BelgiumKbo extends Dataset implements DownloadsSource, SelectsArchiveMembers
{
    public const string KEY = 'be-kbo';

    public const string BASE_URL = 'https://kbopub.economie.fgov.be/kbo-open-data';

    /** Denomination types in order of preference: name, commercial name, abbreviation. */
    private const DENOMINATION_RANK = ['001' => 1, '003' => 2, '002' => 3];

    /** Code description languages in order of preference. */
    private const LANGUAGES = ['EN', 'FR', 'NL', 'DE'];

    public function key(): string
    {
        return self::KEY;
    }

    public function country(): string
    {
        return 'BE';
    }

    /**
     * The page that lists the downloadable files after login.
     */
    public function sourceUrl(): string
    {
        return ($this->setting('url') ?? self::BASE_URL).'/affiliation/xml/?files';
    }

    public function credentials(): array
    {
        return ['username' => 'COMPANY_LOOKUP_KBO_USERNAME', 'password' => 'COMPANY_LOOKUP_KBO_PASSWORD'];
    }

    public function label(): string
    {
        return 'Belgium Crossroads Bank for Enterprises (KBO Open Data)';
    }

    public function registryUrl(): string
    {
        return 'https://kbopub.economie.fgov.be/kbopub/zoeknummerform.html';
    }

    public function sample(): string
    {
        return '0202239951';
    }

    public function identifierTypes(): array
    {
        return [IdentifierType::Registration, IdentifierType::Vat];
    }

    /**
     * Enterprise numbers have ten digits; old nine-digit numbers get the leading zero back.
     */
    public function normalizeIdentifier(string $value): string
    {
        $digits = Normalize::digits($value);

        return strlen($digits) === 9 ? '0'.$digits : $digits;
    }

    public function archiveMembers(): array
    {
        return ['enterprise.csv', 'denomination.csv', 'address.csv', 'code.csv'];
    }

    public function download(PendingRequest $request, string $target): void
    {
        $base = $this->setting('url') ?? self::BASE_URL;
        $request = $request->withOptions(['cookies' => new CookieJar]);

        // Opens the session, then signs in through the portal's login form (with its CSRF token, if it has one).
        $form = [
            'j_username' => $this->setting('username') ?? '',
            'j_password' => $this->setting('password') ?? '',
        ];
        $token = $this->csrfToken($request->get($base.'/login')->body());
        if ($token !== null) {
            $form['_csrf'] = $token;
        }

        $request->asForm()->post($base.'/static/j_spring_security_check', $form);

        $listing = $request->get($this->sourceUrl());
        if (! $listing->successful() || str_contains($listing->body(), 'j_username')) {
            throw new RuntimeException('KBO Open Data login failed: check COMPANY_LOOKUP_KBO_USERNAME and COMPANY_LOOKUP_KBO_PASSWORD.');
        }

        $file = $this->latestFullFile($listing->body());
        if ($file === null) {
            throw new RuntimeException('No KboOpenData_*_Full.zip link found on the KBO Open Data file page.');
        }

        $response = $request->sink($target)->get($this->resolve($file, $this->sourceUrl()));
        if (! $response->successful()) {
            throw new RuntimeException("KBO Open Data download failed with HTTP {$response->status()}.");
        }
    }

    public function rows(string $localFile): Generator
    {
        if (! is_dir($localFile)) {
            throw new RuntimeException('The KBO Open Data import expects the extracted zip directory.');
        }

        foreach (['enterprise.csv', 'denomination.csv', 'address.csv'] as $member) {
            if (! is_file($localFile.'/'.$member)) {
                throw new RuntimeException("The KBO Open Data archive has no {$member}.");
            }
        }

        $codes = is_file($localFile.'/code.csv') ? $this->codes($localFile.'/code.csv') : [];
        $stagingFile = $localFile.'/staging.sqlite';
        $staging = $this->staging($stagingFile);

        try {
            $this->stageDenominations($staging, $localFile.'/denomination.csv');
            $this->stageAddresses($staging, $localFile.'/address.csv');

            $name = $staging->prepare('SELECT value FROM names WHERE entity = ?');
            $address = $staging->prepare('SELECT street, postal_code, city, country FROM addresses WHERE entity = ?');

            foreach (Csv::read($localFile.'/enterprise.csv') as $row) {
                $number = trim($row['EnterpriseNumber'] ?? '');
                $denomination = $number === '' ? null : $this->fetchOne($name, $number);
                if ($denomination === null || ! is_string($denomination['value'])) {
                    continue;
                }

                $office = $this->fetchOne($address, $number) ?? [];
                $street = Data::string($office, 'street');
                $postalCode = Data::string($office, 'postal_code');
                $city = Data::string($office, 'city');
                $situation = Data::clean($row['JuridicalSituation'] ?? null);
                $formCode = Data::clean($row['JuridicalFormCAC'] ?? null) ?? Data::clean($row['JuridicalForm'] ?? null);

                yield Record::make(
                    identifier: Normalize::digits($number),
                    name: $denomination['value'],
                    address: Record::join($street, trim($postalCode.' '.$city), Data::string($office, 'country')),
                    // Every listed entity is active; any juridical situation other than "000" (normal)
                    // means liquidation, bankruptcy or a similar procedure.
                    status: $situation === null || $situation === '000' ? CompanyStatus::Active : CompanyStatus::Inactive,
                    legalForm: $formCode === null ? null : ($codes['JuridicalForm'][$formCode] ?? null),
                    foundedAt: Data::date(Data::clean($row['StartDate'] ?? null), ['d-m-Y']),
                    payload: [
                        'enterprise_number' => $number,
                        'vat' => 'BE'.Normalize::digits($number),
                        'legal_form_code' => $formCode,
                        'juridical_situation' => $situation,
                        'juridical_situation_text' => $situation === null ? null : ($codes['JuridicalSituation'][$situation] ?? null),
                        'type_of_enterprise' => Data::clean($row['TypeOfEnterprise'] ?? null),
                        'street' => $street,
                        'postal_code' => $postalCode,
                        'city' => $city,
                    ],
                );
            }
        } finally {
            unset($name, $address, $staging);
            if (is_file($stagingFile)) {
                unlink($stagingFile);
            }
        }
    }

    /**
     * The value of a hidden "_csrf" input on the login page, if present.
     */
    private function csrfToken(string $html): ?string
    {
        if (preg_match('/<input\b[^>]*\bname=["\']_csrf["\'][^>]*>/i', $html, $input) !== 1) {
            return null;
        }

        return preg_match('/\bvalue=["\']([^"\']*)["\']/i', $input[0], $value) === 1 ? html_entity_decode($value[1]) : null;
    }

    /**
     * The link of the full file with the highest extract number on the listing page.
     */
    private function latestFullFile(string $html): ?string
    {
        preg_match_all('/href="([^"]*KboOpenData_(\d+)_\d{4}_\d{2}(?:_\d{2})?_Full\.zip)"/i', $html, $matches, PREG_SET_ORDER);

        $best = null;
        $bestExtract = -1;
        foreach ($matches as $match) {
            if ((int) $match[2] > $bestExtract) {
                $bestExtract = (int) $match[2];
                $best = html_entity_decode($match[1]);
            }
        }

        return $best;
    }

    private function resolve(string $link, string $page): string
    {
        if (preg_match('#^https?://#i', $link) === 1) {
            return $link;
        }

        $parts = parse_url($page);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'kbopub.economie.fgov.be');

        if (str_starts_with($link, '/')) {
            return $origin.$link;
        }

        $directory = (string) preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin.$directory.$link;
    }

    /**
     * Code descriptions by category and code, in the first available preferred language.
     *
     * @return array<string, array<string, string>>
     */
    private function codes(string $file): array
    {
        $wanted = ['JuridicalForm', 'JuridicalSituation'];
        $codes = [];
        $ranks = [];

        foreach (Csv::read($file) as $row) {
            $category = $row['Category'] ?? '';
            $code = $row['Code'] ?? '';
            $rank = array_search($row['Language'] ?? '', self::LANGUAGES, true);
            $description = Data::clean($row['Description'] ?? null);

            if (! in_array($category, $wanted, true) || $rank === false || $description === null) {
                continue;
            }

            if (! isset($ranks[$category][$code]) || $rank < $ranks[$category][$code]) {
                $codes[$category][$code] = $description;
                $ranks[$category][$code] = $rank;
            }
        }

        return $codes;
    }

    private function staging(string $file): PDO
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The KBO Open Data import needs the pdo_sqlite extension for its temporary staging file.');
        }

        $pdo = new PDO('sqlite:'.$file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('CREATE TABLE names (entity TEXT PRIMARY KEY, rank INTEGER NOT NULL, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE addresses (entity TEXT PRIMARY KEY, street TEXT, postal_code TEXT, city TEXT, country TEXT)');

        return $pdo;
    }

    private function stageDenominations(PDO $staging, string $file): void
    {
        $insert = $staging->prepare(
            'INSERT INTO names (entity, rank, value) VALUES (?, ?, ?)
             ON CONFLICT (entity) DO UPDATE SET rank = excluded.rank, value = excluded.value WHERE excluded.rank < names.rank'
        );

        $this->batched($staging, Csv::read($file), function (array $row) use ($insert): void {
            $rank = self::DENOMINATION_RANK[$row['TypeOfDenomination'] ?? ''] ?? null;
            $value = Data::clean($row['Denomination'] ?? null);

            if ($rank !== null && $value !== null && $this->isEnterprise($row['EntityNumber'] ?? '')) {
                $insert->execute([trim($row['EntityNumber'] ?? ''), $rank, $value]);
            }
        });
    }

    private function stageAddresses(PDO $staging, string $file): void
    {
        $insert = $staging->prepare('INSERT OR IGNORE INTO addresses (entity, street, postal_code, city, country) VALUES (?, ?, ?, ?, ?)');

        $this->batched($staging, Csv::read($file), function (array $row) use ($insert): void {
            // Only the registered office ("REGO") of the entity, and only while it is not struck off.
            if (($row['TypeOfAddress'] ?? '') !== 'REGO' || Data::clean($row['DateStrikingOff'] ?? null) !== null || ! $this->isEnterprise($row['EntityNumber'] ?? '')) {
                return;
            }

            $street = Data::clean($row['StreetFR'] ?? null) ?? Data::clean($row['StreetNL'] ?? null);
            $number = trim(($row['HouseNumber'] ?? '').(Data::clean($row['Box'] ?? null) !== null ? ' bte '.trim($row['Box'] ?? '') : ''));

            $insert->execute([
                trim($row['EntityNumber'] ?? ''),
                Record::join(trim($street.' '.$number), Data::clean($row['ExtraAddressInfo'] ?? null)),
                Data::clean($row['Zipcode'] ?? null),
                Data::clean($row['MunicipalityFR'] ?? null) ?? Data::clean($row['MunicipalityNL'] ?? null),
                Data::clean($row['CountryFR'] ?? null) ?? Data::clean($row['CountryNL'] ?? null),
            ]);
        });
    }

    /**
     * Enterprise numbers look like 0200.065.765; establishment units (2.000.000.339) are not staged.
     */
    private function isEnterprise(string $number): bool
    {
        return preg_match('/^\d{4}\.\d{3}\.\d{3}$/', trim($number)) === 1;
    }

    /**
     * Runs $handle for every row, committing every 10 000 rows.
     *
     * @param  iterable<array<string, string>>  $rows
     * @param  callable(array<string, string>): void  $handle
     */
    private function batched(PDO $staging, iterable $rows, callable $handle): void
    {
        $count = 0;
        $staging->beginTransaction();

        foreach ($rows as $row) {
            $handle($row);

            if (++$count % 10000 === 0) {
                $staging->commit();
                $staging->beginTransaction();
            }
        }

        $staging->commit();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOne(PDOStatement $statement, string $entity): ?array
    {
        $statement->execute([$entity]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        if (! is_array($row)) {
            return null;
        }

        $clean = [];
        foreach ($row as $column => $value) {
            $clean[(string) $column] = $value;
        }

        return $clean;
    }
}
