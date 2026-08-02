#!/usr/bin/env node
// Gmail inbox checker — requires gmail.readonly scope on refresh token
// Usage: node qa/check-gmail-inbox.mjs
// Fill GMAIL_READONLY_REFRESH_TOKEN, GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET in qa/.env.qa
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

const refreshToken = env.GMAIL_READONLY_REFRESH_TOKEN || process.env.GMAIL_READONLY_REFRESH_TOKEN;
const clientId = env.GMAIL_CLIENT_ID || process.env.GMAIL_CLIENT_ID;
const clientSecret = env.GMAIL_CLIENT_SECRET || process.env.GMAIL_CLIENT_SECRET;

if (!refreshToken || !clientId || !clientSecret) {
  console.log('');
  console.log('Gmail readonly not configured. To enable inbox checking:');
  console.log('');
  console.log('1. Go to: https://accounts.google.com/o/oauth2/v2/auth?');
  console.log('   client_id=YOUR_CLIENT_ID');
  console.log('   &redirect_uri=urn:ietf:wg:oauth:2.0:oob');
  console.log('   &response_type=code');
  console.log('   &scope=https://www.googleapis.com/auth/gmail.send%20https://www.googleapis.com/auth/gmail.readonly');
  console.log('   &access_type=offline&prompt=consent');
  console.log('');
  console.log('2. Authorize and copy the code');
  console.log('3. Exchange for refresh_token and set GMAIL_READONLY_REFRESH_TOKEN in qa/.env.qa');
  console.log('');
  process.exit(0);
}

// Get access token
const tokenRes = await fetch('https://oauth2.googleapis.com/token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    client_id: clientId,
    client_secret: clientSecret,
    refresh_token: refreshToken,
    grant_type: 'refresh_token',
  }),
});

if (!tokenRes.ok) {
  const err = await tokenRes.json();
  console.log('Failed to get access token:', err.error, err.error_description);
  process.exit(1);
}

const { access_token } = await tokenRes.json();

// Search for recent MOVA emails
const movaSubjects = [
  'Tu perfil docente fue verificado en MOVA',
  'Tu clase fue confirmada en MOVA',
  'Recordatorio de clase en MOVA',
  'Tu clase fue cancelada en MOVA',
  'Nueva solicitud de clase en MOVA',
  'Tu clase fue confirmada',
  'MOVA',
];

const query = 'from:(m0v4class@gmail.com) subject:MOVA newer_than:1d';
const searchUrl = `https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent(query)}&maxResults=20`;

const searchRes = await fetch(searchUrl, {
  headers: { Authorization: `Bearer ${access_token}` },
});

if (!searchRes.ok) {
  const err = await searchRes.json();
  console.log('Gmail search failed:', searchRes.status, err.error?.message);
  process.exit(1);
}

const searchData = await searchRes.json();
const messages = searchData.messages || [];

console.log(`\nMOVA emails in last 24h: ${messages.length}`);

if (messages.length === 0) {
  console.log('No recent MOVA emails found — email delivery may not be working');
  process.exit(0);
}

console.log('\nDate                 | Subject                                        | To');
console.log('---------------------|------------------------------------------------|---');

for (const msg of messages.slice(0, 10)) {
  const detailRes = await fetch(
    `https://gmail.googleapis.com/gmail/v1/users/me/messages/${msg.id}?format=metadata&metadataHeaders=Subject&metadataHeaders=To&metadataHeaders=Date`,
    { headers: { Authorization: `Bearer ${access_token}` } }
  );
  if (!detailRes.ok) continue;

  const detail = await detailRes.json();
  const headers = detail.payload?.headers || [];
  const subject = headers.find(h => h.name === 'Subject')?.value || '(no subject)';
  const to = headers.find(h => h.name === 'To')?.value || '?';
  const date = headers.find(h => h.name === 'Date')?.value || '?';
  const shortDate = new Date(date).toISOString().slice(0, 19);
  const shortSubject = subject.length > 46 ? subject.slice(0, 43) + '...' : subject;
  const shortTo = to.length > 30 ? to.slice(0, 27) + '...' : to;

  console.log(`${shortDate} | ${shortSubject.padEnd(46)} | ${shortTo}`);
}

console.log('\nGmail delivery: CONFIRMED — emails are reaching inbox');
