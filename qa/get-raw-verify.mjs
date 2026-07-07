#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(envLines.filter(l => l && !l.startsWith('#') && l.includes('=')).map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()]));

const tokenRes = await fetch('https://oauth2.googleapis.com/token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ client_id: env.GMAIL_CLIENT_ID, client_secret: env.GMAIL_CLIENT_SECRET, refresh_token: env.GMAIL_READONLY_REFRESH_TOKEN, grant_type: 'refresh_token' }),
});
const { access_token } = await tokenRes.json();

const searchRes = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent('subject:"Verify Email" newer_than:2d')}&maxResults=1`, { headers: { Authorization: `Bearer ${access_token}` } });
const { messages = [] } = await searchRes.json();

if (!messages.length) { console.log('No messages found'); process.exit(0); }

const res = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages/${messages[0].id}?format=raw`, { headers: { Authorization: `Bearer ${access_token}` } });
const msg = await res.json();

// Decode raw
const rawBytes = Buffer.from(msg.raw, 'base64url');
const rawText = rawBytes.toString('utf-8');

// Extract URLs from raw email
const urls = [...rawText.matchAll(/(https?:\/\/[^\s<>"'\\]+)/g)].map(m => m[1]);
const verifyUrl = urls.find(u => u.includes('verify') || (u.includes('email') && u.includes('hash')));

console.log('Raw email size:', rawText.length, 'bytes');
console.log('All URLs found:');
urls.slice(0, 10).forEach(u => console.log('  ', u.substring(0, 120)));

if (verifyUrl) {
  console.log('\n>> VERIFY URL FOUND:', verifyUrl);
  writeFileSync('C:/Users/ABEL/AppData/Local/Temp/verify_link.txt', verifyUrl);
  console.log('Saved to /tmp/verify_link.txt');
}
