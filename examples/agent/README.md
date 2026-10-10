# Example agent

Pays a `laravel-x402` protected endpoint on Base Sepolia using the official
[`@x402/fetch`](https://github.com/x402-foundation/x402) client. Nothing in this
script knows the server is PHP, so a successful run is real interoperability
proof rather than a self-test.

## Run it

Serve a paid route from your Laravel app:

```php
Route::get('/market-data', fn () => ['btc' => 67_000])->middleware('x402:0.01');
```

```env
X402_MODE=enforce
X402_NETWORK=eip155:84532
X402_PAY_TO=0xYourWallet
X402_FACILITATOR_URL=https://x402.org/facilitator
```

```bash
php artisan serve
```

Then, in this directory:

```bash
cp .env.example .env     # add a funded Base Sepolia private key
npm install
npm start
```

## Funding the agent

- Test USDC: [faucet.circle.com](https://faucet.circle.com) → Base Sepolia
- Test ETH: [alchemy.com/faucets/base-sepolia](https://www.alchemy.com/faucets/base-sepolia)

The agent does not strictly need ETH — the facilitator sponsors gas for
EIP-3009 transfers — but having a little makes debugging easier.

## Expected output

```
Paying wallet:  0x857b06519E91e3A54538791bDbb0E22373e36b66
Requesting:     http://localhost:8000/market-data

402 Payment Required
  network:   eip155:84532
  amount:    10000 atomic units of 0x036CbD53842c5426634e7929541eC2318f3dCF7e
  payTo:     0x209693Bc6afc0C5328bA36FaF03C514EF312287C
  timeout:   60s

200 OK
{"btc":67000,"eth":3400,"as_of":"2026-10-10T12:00:00+00:00"}

Settlement
  success:     true
  transaction: 0x9f2c…
  payer:       0x857b06519E91e3A54538791bDbb0E22373e36b66

https://sepolia.basescan.org/tx/0x9f2c…

Everything this server sells:
  http://localhost:8000/market-data — 10000 atomic units
```

## No wallet handy?

`php artisan x402:test /market-data` runs the same exchange in-process against a
fake facilitator, with no chain and no funds.
