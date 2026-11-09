# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, steps to reproduce. You will receive a
reply within a few working days.

## Scope

The package sends the identifiers and names you look up to the public registries you enable, and
stores nothing except cached answers in the Laravel cache store you configure and, when bulk
datasets are enabled, the imported register data in your database.

- **Credentials** for keyed registries are read from your configuration and sent only to the
  registry they belong to. They are not written to results, failure messages or logs.
- **The Danish CVR service is plain HTTP**, as documented by the registry, so CVR credentials and
  answers are not protected in transit. Use a TLS proxy (`drivers.cvr.base_url`) if that matters.
- **XML responses** (SOAP registries, Japanese corporate numbers) are parsed without network
  access to external entities.
- **User input** is escaped before it enters SOAP envelopes, Elasticsearch queries and filter
  parameters.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
