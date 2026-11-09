<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * French companies through the "Recherche d'entreprises" API (api.gouv.fr). Free, no key.
 *
 * The API is a full-text search, so identifier lookups keep only the result whose SIREN matches exactly.
 * The source holds no VAT registration data, so VAT lookups are left to VIES. Active companies get the
 * VAT number derived from their SIREN as an identifier; it is format-derived, not verified (vatValid stays null).
 * The legal form is returned as the numeric INSEE "catégorie juridique" code only.
 */
final class FrEntreprisesDriver extends AbstractHttpDriver
{
    public const string KEY = 'fr-entreprises';

    /** The API refuses more than 25 results per page. */
    private const MAX_PER_PAGE = 25;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'France – Recherche d\'entreprises',
            countries: ['FR'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            attribution: 'Data: Recherche d\'entreprises (DINUM / INSEE), Licence Ouverte 2.0',
            registryUrl: 'https://annuaire-entreprises.data.gouv.fr/',
            sample: '542051180',
            sampleCountry: 'FR',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $query = $this->registrationQuery($identifier->value);

        if (strtoupper($country) !== 'FR' || $identifier->type === IdentifierType::Vat || $query === null) {
            return LookupResult::notFound(self::KEY);
        }

        $siren = substr($query, 0, 9);
        $siret = strlen($query) === 14 ? $query : null;

        return $this->guard(function () use ($query, $siren, $siret): LookupResult {
            $response = $this->call($query, 5);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            foreach (Data::list($this->json($response), 'results') as $result) {
                if (Data::string($result, 'siren') === $siren) {
                    return LookupResult::found(self::KEY, $this->company($result, $siret));
                }
            }

            return LookupResult::notFound(self::KEY);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'FR' || mb_strlen($name) < 3) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($name, $limit): LookupResult {
            $response = $this->call($name, max(1, min(self::MAX_PER_PAGE, $limit)));

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $companies = array_map(fn (array $result): Company => $this->company($result), Data::list($this->json($response), 'results'));

            return LookupResult::found(self::KEY, ...array_slice($companies, 0, max(1, $limit)));
        });
    }

    /**
     * The French VAT number of a SIREN: FR + two-digit key + SIREN.
     */
    public static function vatNumber(string $siren): string
    {
        $key = (12 + 3 * ((int) $siren % 97)) % 97;

        return sprintf('FR%02d%s', $key, $siren);
    }

    private function call(string $query, int $perPage): Response
    {
        return $this->request()
            ->acceptJson()
            ->get($this->baseUrl('https://recherche-entreprises.api.gouv.fr').'/search', [
                'q' => $query,
                'page' => 1,
                'per_page' => $perPage,
            ]);
    }

    /**
     * A SIREN (9 digits) or SIRET (14 digits), or null when the value is neither.
     */
    private function registrationQuery(string $value): ?string
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('FR', $value));

        return in_array(strlen($digits), [9, 14], true) ? $digits : null;
    }

    /**
     * @param  array<array-key, mixed>  $result
     * @param  string|null  $siret  The establishment that was looked up; the head office when null or not returned.
     */
    private function company(array $result, ?string $siret = null): Company
    {
        $siren = Data::string($result, 'siren') ?? '';
        $establishment = $this->establishment($result, $siret);
        $status = match (Data::string($result, 'etat_administratif')) {
            'A' => CompanyStatus::Active,
            'C' => CompanyStatus::Dissolved,
            default => CompanyStatus::Unknown,
        };

        $identifiers = [Identifier::registration($siren, 'FR-SIREN')];
        $establishmentSiret = Data::string($establishment, 'siret');
        if ($establishmentSiret !== null) {
            $identifiers[] = Identifier::registration($establishmentSiret, 'FR-SIRET');
        }
        if ($status === CompanyStatus::Active && preg_match('/^\d{9}$/', $siren) === 1) {
            $identifiers[] = Identifier::vat(self::vatNumber($siren));
        }

        $naf = Data::string($result, 'activite_principale');

        return new Company(
            name: Data::string($result, 'nom_complet') ?? Data::string($result, 'nom_raison_sociale'),
            country: 'FR',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address($establishment),
            status: $status,
            legalFormCode: Data::string($result, 'nature_juridique'),
            activities: $naf === null ? [] : [new ActivityCode('NAF', $naf)],
            foundedAt: Data::date(Data::string($result, 'date_creation')),
            raw: $result,
        );
    }

    /**
     * The matching establishment for a SIRET lookup, else the head office.
     *
     * @param  array<array-key, mixed>  $result
     * @return array<array-key, mixed>
     */
    private function establishment(array $result, ?string $siret): array
    {
        if ($siret !== null) {
            foreach (Data::list($result, 'matching_etablissements') as $establishment) {
                if (Data::string($establishment, 'siret') === $siret) {
                    return $establishment;
                }
            }
        }

        return Data::map($result, 'siege');
    }

    /**
     * The establishment address. "adresse" ends with the postal code and the city, which are split off.
     *
     * @param  array<array-key, mixed>  $establishment
     */
    private function address(array $establishment): ?Address
    {
        $line = Data::string($establishment, 'adresse');
        $postalCode = Data::string($establishment, 'code_postal');
        $city = Data::string($establishment, 'libelle_commune') ?? Data::string($establishment, 'libelle_commune_etranger');

        if ($line === null && $postalCode === null && $city === null) {
            return null;
        }

        $street = $line;
        $suffix = trim(($postalCode ?? '').' '.($city ?? ''));
        if ($street !== null && $suffix !== '' && str_ends_with($street, $suffix)) {
            $street = Data::clean(substr($street, 0, -strlen($suffix)));
        }

        return new Address(
            street: $street,
            postalCode: $postalCode,
            city: $city,
            country: Data::string($establishment, 'code_pays_etranger') === null ? 'FR' : null,
        );
    }
}
