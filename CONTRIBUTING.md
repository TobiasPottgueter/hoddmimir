# Contributing to Hoddmímir

Thank you for helping improve Hoddmímir. The project is a clean rewrite of
the backup scheduler; `docs/rewrite-plan.md` is the authoritative architecture
and functional plan. Read it together with `AGENTS.md` before changing behavior
or architecture.

## Development workflow

1. Create a focused branch from `main`.
2. Keep domain and application logic independent of frameworks and external I/O.
3. Add or update tests for every deterministic rule and state transition.
4. Run the relevant commands documented in `AGENTS.md`.
5. Open a pull request and let all required checks finish.

Use conventional commit messages. Keep pull requests focused and explain any
intentional architecture or compatibility decision in the description.

## Compatibility and security boundaries

- Preserve support for Proxmox VE 7, 8 and 9 and Proxmox Backup Server 3 and 4.
- Do not add a third-party Proxmox API client.
- The collector remains read-only; only the backup worker may mutate backup tasks.
- Do not weaken TLS verification or the ambiguous-`vzdump` retry boundary.
- Mandatory release and production validation target `linux/amd64` only.
- Never commit credentials, tokens, host inventories, private endpoints or other
  deployment configuration.

Report vulnerabilities through the private process in `SECURITY.md`, not in a
public issue.
