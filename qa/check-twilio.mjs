#!/usr/bin/env node
// Twilio message status checker — safe, no secrets printed
// Usage: node qa/check-twilio.mjs
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(
  envLines
    .filter(l => l && !l.startsWith('#') && l.includes('='))
    .map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()])
);

const sid = env.TWILIO_SID || process.env.TWILIO_SID;
const token = env.TWILIO_AUTH_TOKEN || process.env.TWILIO_AUTH_TOKEN;

if (!sid || !token) {
  console.log('TWILIO_SID and TWILIO_AUTH_TOKEN required in qa/.env.qa');
  process.exit(1);
}

const url = `https://api.twilio.com/2010-04-01/Accounts/${sid}/Messages.json?PageSize=10`;
const auth = Buffer.from(`${sid}:${token}`).toString('base64');

try {
  const res = await fetch(url, {
    headers: { Authorization: `Basic ${auth}` },
  });

  if (!res.ok) {
    const err = await res.json();
    console.log('Twilio API error:', res.status, err.message || err.code);
    process.exit(1);
  }

  const data = await res.json();
  const messages = data.messages || [];

  if (messages.length === 0) {
    console.log('No messages found in Twilio account');
    process.exit(0);
  }

  console.log(`\nLast ${messages.length} Twilio messages:\n`);
  console.log('Date                 | To (masked)      | Status      | Error');
  console.log('---------------------|------------------|-------------|------');

  for (const msg of messages) {
    const to = msg.to ? `...${msg.to.slice(-4)}` : 'unknown';
    const date = msg.date_created ? new Date(msg.date_created).toISOString().slice(0, 19) : '?';
    const status = (msg.status || 'unknown').padEnd(11);
    const error = msg.error_code ? `${msg.error_code}: ${msg.error_message || ''}` : 'none';
    console.log(`${date} | ${to.padEnd(16)} | ${status} | ${error}`);
  }

  // Summary
  const statuses = messages.reduce((acc, m) => {
    acc[m.status] = (acc[m.status] || 0) + 1;
    return acc;
  }, {});
  console.log('\nSummary by status:', statuses);

  const hasDelivered = messages.some(m => m.status === 'delivered');
  const hasFailed = messages.some(m => ['failed', 'undelivered'].includes(m.status));

  if (hasDelivered) console.log('\nWhatsApp: At least 1 message DELIVERED');
  if (hasFailed) console.log('\nWhatsApp: Some messages FAILED/UNDELIVERED (check error code above)');
  if (!hasDelivered && !hasFailed) console.log('\nWhatsApp: Messages sent but not yet delivered (check status)');

} catch (e) {
  console.log('Network error:', e.message);
  process.exit(1);
}
