# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-09

### Added

- `CompanyLookup` manager and facade: `lookup()`, `vat()` and `search()` across every driver that
  covers a country, with a unified `Company` result, `LookupOutcome::merged()`, the `first` and
  `all` strategies, `using()` and `extend()`.
- Results that never throw: found, not found, failed (reason and retryable flag) and unconfigured.
- Caching of definitive answers only, per-driver local rate limits, one retry on transient
  errors, and SOAP without `ext-soap`.
- 16 keyless drivers: EU VIES, GLEIF, Czech ARES, Slovak RPO, Polish white list and KRS, Romanian
  ANAF, French Recherche d'entreprises, Norwegian Brønnøysund, Finnish PRH/YTJ, Swiss UID
  register, Singapore ACRA, Taiwan GCIS, Israel Companies Registrar, SEC EDGAR and BrasilAPI
  (community mirror, disabled by default).
- 13 drivers that use your own free credentials: Hungarian NAV taxpayer query, Companies House,
  HMRC VAT, Polish GUS REGON, Irish CRO, Danish CVR, Swedish Bolagsverket, Croatian court
  register, Greek GEMI, Australian ABN Lookup, New Zealand NZBN, Japanese corporate number and
  Corporations Canada.
- Opt-in bulk datasets for Latvia, Estonia and Belgium, with a streaming importer that refuses
  truncated downloads, and the `company-lookup:import` command.
- `company-lookup:doctor` and `company-lookup:lookup` commands, and `CompanyLookup::fake()` with
  assertions.

[1.0.0]: https://github.com/intragile/laravel-company-lookup/releases/tag/v1.0.0

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
