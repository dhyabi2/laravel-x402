/**
 * Pays a laravel-x402 protected endpoint, end to end, on Base Sepolia.
 *
 * Uses the official x402 TypeScript client, so a successful run is real proof
 * of interoperability: nothing in this script knows anything about PHP.
 *
 *   cp .env.example .env   # add a funded Base Sepolia key
 *   npm install
 *   npm start
 *
 * On the Laravel side:
 *
 *   X402_MODE=enforce
 *   X402_NETWORK=eip155:84532
 *   X402_PAY_TO=0xYourWallet
 *   X402_FACILITATOR_URL=https://x402.org/facilitator
 */

import { ExactEvmScheme } from '@x402/evm/exact/client';
import { wrapFetchWithPayment, x402Client, x402HTTPClient } from '@x402/fetch';
import { privateKeyToAccount } from 'viem/accounts';

const BASE_SEPOLIA = 'eip155:84532';

const privateKey = process.env.EVM_PRIVATE_KEY as `0x${string}` | undefined;
const baseUrl = process.env.RESOURCE_SERVER_URL ?? 'http://localhost:8000';
const path = process.env.ENDPOINT_PATH ?? '/market-data';
const url = `${baseUrl}${path}`;

if (!privateKey || privateKey === '0x') {
  console.error('Set EVM_PRIVATE_KEY in .env to a funded Base Sepolia wallet.');
  process.exit(1);
}

const signer = privateKeyToAccount(privateKey);

const client = new x402Client();
client.register('eip155:*', new ExactEvmScheme(signer));

// A hard ceiling on what this agent will ever pay for one request. Without it,
// a buggy or hostile server could ask for any amount and the client would sign.
client.setSpendControls({ maxAmountPerPayment: '$0.50' });

const httpClient = new x402HTTPClient(client);
const fetchWithPayment = wrapFetchWithPayment(fetch, client);

console.log(`Paying wallet:  ${signer.address}`);
console.log(`Requesting:     ${url}\n`);

// 1. What does it cost? The unpaid request answers in machine-readable form.
const unpaid = await fetch(url, { headers: { Accept: 'application/json' } });

if (unpaid.status !== 402) {
  console.error(`Expected HTTP 402, got ${unpaid.status}. Is the x402 middleware on that route?`);
  process.exit(1);
}

const declaration = await httpClient.processResponse(unpaid.clone());
const accepts = declaration.header && 'accepts' in declaration.header ? declaration.header.accepts : [];
const option = accepts.find((entry) => entry.network === BASE_SEPOLIA) ?? accepts[0];

if (!option) {
  console.error('The 402 advertised no payment methods.');
  process.exit(1);
}

console.log('402 Payment Required');
console.log(`  network:   ${option.network}`);
console.log(`  amount:    ${option.amount} atomic units of ${option.asset}`);
console.log(`  payTo:     ${option.payTo}`);
console.log(`  timeout:   ${option.maxTimeoutSeconds}s\n`);

// 2. Pay and retry. The client signs an EIP-3009 authorization and resends it
//    on PAYMENT-SIGNATURE; the facilitator settles it on chain.
const paid = await fetchWithPayment(url, { headers: { Accept: 'application/json' } });

console.log(`${paid.status} ${paid.statusText}`);
console.log(await paid.clone().text(), '\n');

const settled = await httpClient.processResponse(paid);

if (settled.header && 'success' in settled.header) {
  console.log('Settlement');
  console.log(`  success:     ${settled.header.success}`);
  console.log(`  transaction: ${settled.header.transaction}`);
  console.log(`  payer:       ${settled.header.payer}`);
  console.log(`\nhttps://sepolia.basescan.org/tx/${settled.header.transaction}`);
}

// 3. The seller's own catalogue, for an agent that wants to shop around.
const discovery = await fetch(`${baseUrl}/.well-known/x402`, {
  headers: { Accept: 'application/json' },
});

if (discovery.ok) {
  const { items } = (await discovery.json()) as { items: Array<{ resource: string; accepts: Array<{ amount: string }> }> };

  console.log('\nEverything this server sells:');
  for (const item of items) {
    console.log(`  ${item.resource} — ${item.accepts[0]?.amount ?? '?'} atomic units`);
  }
}
