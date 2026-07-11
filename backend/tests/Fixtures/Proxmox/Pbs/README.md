# Sanitized PBS 3/4 contract fixtures

These files are constructed, secret-free contract examples for the bounded
Hoddmímir PBS read slice. They are not production captures and contain no real
hostnames, paths, token identifiers, token secrets, certificates, fingerprints,
instance identities, or datastore data.

The pinned contract sources are:

- PBS 3 API viewer documentation 3.4.4, canonical viewer hash prefix
  `2ba388`; PBS source baseline 3.4.0 commit prefix `36ef1b`;
- PBS 4 API viewer documentation 4.2.2, canonical viewer hash prefix
  `c62063`; PBS source baseline 4.2.0 commit prefix `035c449`;
- server identity introduction commit prefix `897df9`, first included in PBS
  4.2.

The complete hashes and extraction procedure are recorded in
`docs/pbs-first-read-contract.md`. Additive `future-*` properties verify that
readers remain tolerant without enabling capabilities from unknown fields.
All digest and instance-ID values are synthetic.
