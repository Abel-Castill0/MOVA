#!/usr/bin/env node
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(envLines.filter(l => l && !l.startsWith('#') && l.includes('=')).map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()]));

const { access_token } = await (await fetch('https://oauth2.googleapis.com/token', {
  method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ client_id: env.GMAIL_CLIENT_ID, client_secret: env.GMAIL_CLIENT_SECRET, refresh_token: env.GMAIL_READONLY_REFRESH_TOKEN, grant_type: 'refresh_token' }),
})).json();

const { messages = [] } = await (await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent('subject:"Verify Email" newer_than:2d')}&maxResults=1`, { headers: { Authorization: `Bearer ${access_token}` } })).json();

const msg = await (await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages/${messages[0].id}?format=raw`, { headers: { Authorization: `Bearer ${access_token}` } })).json();
const raw = Buffer.from(msg.raw, 'base64url').toString('utf-8');

// Print headers and first 2000 chars of decoded body
console.log('=== RAW (first 2000 chars) ===');
console.log(raw.substring(0, 2000));

// Also check if body is quoted-printable encoded
if (raw.includes('Content-Transfer-Encoding: quoted-printable')) {
  console.log('\n>> Email is quoted-printable encoded');
  // Decode QP
  const bodyStart = raw.indexOf('\r\n\r\n');
  if (bodyStart > -1) {
    const body = raw.substring(bodyStart + 4).replace(/=\r\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
    const urls = [...body.matchAll(/(https?:\/\/[^\s<>"']+)/g)].map(m => m[1]);
    console.log('\nURLs after QP decode:', urls.slice(0, 5));
  }
}
