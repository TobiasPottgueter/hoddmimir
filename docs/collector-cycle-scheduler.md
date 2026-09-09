# Collector cycle scheduler boundary

The collector schedule is a persistent singleton named `inventory`. Its first
grid tick is due immediately when the schedule is first created. The UTC start
instant remains the grid anchor across process restarts. After every terminal
cycle outcome, Application `GridSchedule` logic chooses only the next tick
strictly after MariaDB's completion time. Missed ticks are never replayed.

MariaDB `UTC_TIMESTAMP(6)` is authoritative for bootstrap, due checks, claim,
lease renewal, expiry, takeover, fencing, and finalization. The process
monotonic clock is used only for elapsed duration and bounded, signal-aware
runtime waiting. A cycle is identified by the complete tuple of worker
ID, random cycle token, and increasing fencing token.

Cold-start bootstrap uses one idempotent insert so concurrent workers converge
on the same persisted anchor. Every time-sensitive decision samples MariaDB's
clock only after acquiring the potentially blocking schedule-row lock. A lease
renewal must move the persisted expiry strictly forward; a worker cannot mark
its own cycle `abandoned`. The next claimant owns that transition, advances the
schedule to the first strictly future grid tick, and returns waiting without
claiming a replacement cycle in the same call.

This scheduler slice deliberately exposes no generic callable-based fenced unit
of work. Such an API cannot prove that future inventory mutations use the same
locked DB connection and transaction. Each future concrete aggregate writer
must therefore own its DBAL transaction, lock and validate the schedule plus
running cycle row, mutate through that same connection, revalidate immediately
before commit, and roll back on lease loss. An expired cycle is marked
`abandoned`, but its overdue tick is never replayed. Multiple missed ticks and
an expiry exactly on a grid boundary both advance to the following future tick.

The collector command uses its dedicated runtime loop and never the generic
readiness `WorkerLoop`. The runtime wires the production credential/TLS reader
for the fixed GET-only PVE core/storage and PBS server/datastore routes. PVE
and PBS targets are processed independently: one failed target makes the global
cycle partial without preventing the remaining targets from being persisted.
An empty enabled-target catalog is an honest successful cycle. After a usable
core/storage apply, the collector opens the two fenced `external_jobs` and
`observed_tasks` child runs and performs one combined GET-only monitoring read
against the exact endpoint selected by the parent; monitoring never performs
its own failover. Failed or incomplete child reads remain positive-only and do
not roll back the parent inventory apply.

`COLLECTOR_GRID_WIDTH_SECONDS` is the only runtime grid-width source, defaults
to 120, and accepts 1 through 31,536,000 seconds. It must equal the persisted
width. A mismatch fails closed so mixed replicas cannot alternately rewrite
scheduling configuration. Width changes are deliberately unsupported by the
runtime command; a later explicit maintenance operation must prove that no
valid lease exists before it rewrites the persisted schedule.

The process stores one cryptographically random worker ID as 32 lowercase hex
characters in the container's `/app/var` tmpfs with mode `0600`. Each claim
attempt receives a fresh independent cycle token. The collector healthcheck
combines base readiness with this exact worker's MariaDB heartbeat: fresh
`ready`, `busy`, and `degraded` states are healthy; missing, malformed, expired,
or `stopping` state is unhealthy. It never accepts a different replica's
heartbeat.
