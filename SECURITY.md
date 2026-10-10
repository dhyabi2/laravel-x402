# Security policy

## Supported versions

The latest minor release receives security fixes. Until `1.0.0`, that means the
latest `0.x` release.

## Reporting a vulnerability

**Please do not open a public issue, pull request or discussion.**

Report privately through
[GitHub's private vulnerability reporting](https://github.com/requestway/laravel-x402/security/advisories/new).

Please include:

- the version of the package, PHP and Laravel
- the configuration that matters (mode, settlement mode, facilitator, cache store)
- what an attacker can achieve: a free resource, a double delivery, a redirected
  payment, a denial of service
- a proof of concept if you have one — a failing test is ideal

You will get an acknowledgement within three working days and an assessment
within seven. If the report is valid, you will be credited in the advisory and
the changelog unless you would rather not be.

## Scope

In scope:

- accepting a payment that should have been rejected (underpaid, expired,
  replayed, wrong recipient, wrong network, wrong resource)
- serving a protected resource without a settled payment, outside the documented
  behaviour of `after_response` mode and `fail_open`
- leaking a signature, a full payment payload or a private key through logs,
  responses, headers or stored records
- bypassing replay protection
- any path that turns malformed client input into a 500, a stack trace, or
  arbitrary code execution

Out of scope:

- vulnerabilities in a facilitator, a token contract or a chain — report those
  to their maintainers
- `X402_ON_FACILITATOR_ERROR=fail_open` giving a resource away during an outage;
  that is what it is for, and it is off by default
- `after_response` settlement failing after a response was served; that is the
  documented trade-off of that mode, and it fires `PaymentFailed`
- using `array` or `file` as the replay cache store across multiple servers;
  `x402:doctor` warns about it
- missing rate limiting on your own routes

## Hardening checklist

The [testnet to mainnet checklist](README.md#testnet-to-mainnet-checklist) in the
README is the short version. The essentials:

- keep `X402_SETTLEMENT_MODE=before_response` unless you have accepted the
  trade-off knowingly
- point `X402_REPLAY_STORE` at a cache shared by every web node
- set `X402_REPLAY_TTL` longer than the largest `maxTimeoutSeconds` you advertise
- leave `X402_ON_FACILITATOR_ERROR=fail_closed`
- leave `X402_LOG_PAYER=false` unless you need payer addresses in logs
- alert on `PaymentFailed` where `servedWithoutPayment()` is true
