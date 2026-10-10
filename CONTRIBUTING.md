# Contributing

Thanks for considering a contribution. This package aims to be the default x402
implementation for PHP, which means correctness against the specification comes
before everything else.

## Before you start

**Read [`docs/SPEC_NOTES.md`](docs/SPEC_NOTES.md).** It records exactly which
protocol version this package implements, every message schema, and every place
the implementation makes a judgement call the specification leaves open. If your
change touches the wire format, the facilitator API or validation, the
specification is the arbiter — not this README, and not a maintainer's opinion.

The canonical specification lives at
[x402-foundation/x402](https://github.com/x402-foundation/x402), under `/specs`.

## Getting set up

```bash
git clone https://github.com/ryankny/laravel-x402
cd laravel-x402
composer install
vendor/bin/pest
```

Everything runs locally with no chain, no wallet and no network access:
the test suite uses `FakeFacilitator` and `Http::fake()` throughout.

## Before you open a pull request

```bash
vendor/bin/pint            # code style
vendor/bin/phpstan analyse # static analysis, level 8
vendor/bin/pest --coverage --min=90
```

All three must pass. CI runs them against PHP 8.2, 8.3 and 8.4 on every
supported Laravel version.

## What a good pull request looks like

- **A test that fails without the change.** For a protocol change, prefer a
  golden fixture taken from the specification over a hand-written expectation;
  `tests/Fixtures/` has examples.
- **A note in `docs/SPEC_NOTES.md`** if you resolve a spec ambiguity, add a
  deliberate difference, or implement a new protocol version or scheme.
- **An entry under `## [Unreleased]`** in `CHANGELOG.md`.
- **No new dependencies** unless there is no reasonable alternative. In
  particular `src/Core` must keep zero Illuminate imports, so it can be split
  out as `hydralabs/x402-php`. A test enforces this.
- **Comments that explain why, not what.** The codebase is deliberately light on
  comments; the ones that exist earn their place by recording a decision.

## Protocol changes

A new protocol version is a new `Codec` implementation, not an edit to an
existing one. Older versions stay readable for as long as clients send them.
If you are adding one:

1. Add `src/Core/Protocol/V<n>Codec.php` implementing `Codec`.
2. Register it in `CodecRegistry`, newest first.
3. Add round-trip tests with fixtures captured from the specification.
4. Record the header names and schema changes in `docs/SPEC_NOTES.md`.

New schemes (`upto`, `batch-settlement`, `auth-capture`) and non-EVM networks
are welcome. Local validation should fail open to the facilitator for anything
it cannot model, exactly as `PaymentValidator` does for unknown payload shapes
today.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
