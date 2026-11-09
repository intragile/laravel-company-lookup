<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

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
 * Brazilian CNPJ data through BrasilAPI, a community-run mirror of the Receita Federal open data.
 * Free, no key, but not operated by the registry, hence disabled by default.
 *
 * Accepts both numeric and the alphanumeric CNPJ format introduced in 2026.
 */
final class BrasilApiDriver extends AbstractHttpDriver
{
    public const string KEY = 'brasilapi';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'BrasilAPI CNPJ (community mirror of Receita Federal data)',
            countries: ['BR'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: false,
            official: false,
            registryUrl: 'https://solucoes.receita.fazenda.gov.br/servicos/cnpjreva/cnpjreva_solicitacao.asp',
            sample: '33000167000101',
            sampleCountry: 'BR',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $cnpj = Normalize::compact($identifier->value);

        // Strip a "BR" country prefix only from 16 characters: an alphanumeric CNPJ may itself start with BR.
        if (strlen($cnpj) === 16 && str_starts_with($cnpj, 'BR')) {
            $cnpj = substr($cnpj, 2);
        }

        if (strtoupper($country) !== 'BR' || ! self::validCnpj($cnpj)) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($cnpj): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl('https://brasilapi.com.br/api').'/cnpj/v1/'.$cnpj);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, 'HTTP '.$response->status().' '.(Data::string(Data::json($response->body()), 'message') ?? ''));
            }

            $data = $this->json($response);
            if (Data::string($data, 'cnpj') === null) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $this->company($cnpj, $data));
        });
    }

    /**
     * Validates the two mod-11 check digits. Letters count as their ASCII code minus 48.
     */
    public static function validCnpj(string $cnpj): bool
    {
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) !== 1 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $digit = function (string $base): int {
            $sum = 0;
            $weight = 2;
            for ($i = strlen($base) - 1; $i >= 0; $i--) {
                $sum += (ord($base[$i]) - 48) * $weight;
                $weight = $weight === 9 ? 2 : $weight + 1;
            }

            return $sum % 11 < 2 ? 0 : 11 - $sum % 11;
        };

        $first = $digit(substr($cnpj, 0, 12));
        $second = $digit(substr($cnpj, 0, 12).$first);

        return substr($cnpj, 12) === $first.$second;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(string $cnpj, array $data): Company
    {
        $legalFormCode = Data::int($data, 'codigo_natureza_juridica');

        return new Company(
            name: Data::string($data, 'razao_social'),
            country: 'BR',
            source: self::KEY,
            identifiers: [Identifier::registration($cnpj, 'BR-CNPJ')],
            address: $this->address($data),
            status: match (strtoupper(Data::string($data, 'descricao_situacao_cadastral') ?? '')) {
                'ATIVA' => CompanyStatus::Active,
                'BAIXADA', 'NULA' => CompanyStatus::Dissolved,
                'SUSPENSA', 'INAPTA' => CompanyStatus::Inactive,
                default => CompanyStatus::Unknown,
            },
            legalForm: Data::string($data, 'natureza_juridica'),
            legalFormCode: $legalFormCode === null ? null : (string) $legalFormCode,
            activities: $this->activities($data),
            foundedAt: Data::date(Data::string($data, 'data_inicio_atividade')),
            raw: $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function address(array $data): ?Address
    {
        $streetName = implode(' ', array_filter([Data::string($data, 'descricao_tipo_de_logradouro'), Data::string($data, 'logradouro')]));
        $street = implode(', ', array_filter([
            $streetName,
            Data::string($data, 'numero'),
            Data::string($data, 'complemento'),
            Data::string($data, 'bairro'),
        ]));

        $cep = Normalize::digits(Data::string($data, 'cep') ?? '');
        $city = Data::string($data, 'municipio');

        if ($street === '' && $city === null && $cep === '') {
            return null;
        }

        return new Address(
            street: $street === '' ? null : $street,
            postalCode: strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : ($cep === '' ? null : $cep),
            city: $city,
            region: Data::string($data, 'uf'),
            country: 'BR',
        );
    }

    /**
     * The main CNAE activity followed by the secondary ones.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<ActivityCode>
     */
    private function activities(array $data): array
    {
        $activities = [];

        $main = Data::int($data, 'cnae_fiscal');
        if ($main !== null && $main > 0) {
            $activities[] = new ActivityCode('CNAE', str_pad((string) $main, 7, '0', STR_PAD_LEFT), Data::string($data, 'cnae_fiscal_descricao'));
        }

        foreach (Data::list($data, 'cnaes_secundarios') as $secondary) {
            $code = Data::int($secondary, 'codigo');
            if ($code !== null && $code > 0) {
                $activities[] = new ActivityCode('CNAE', str_pad((string) $code, 7, '0', STR_PAD_LEFT), Data::string($secondary, 'descricao'));
            }
        }

        return $activities;
    }
}
