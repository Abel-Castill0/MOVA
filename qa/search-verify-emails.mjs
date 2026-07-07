#!/usr/bin/env node
// Search Gmail for email verification links for test users
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(
  envLines.filter(l => l && !l.startsWith('#') && l.includes('=')).map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()])
);

const refreshToken = env.GMAIL_READONLY_REFRESH_TOKEN;
const clientId     = env.GMAIL_CLIENT_ID;
const clientSecret = env.GMAIL_CLIENT_SECRET;

if (!refreshToken) { console.log('NO_READONLY_TOKEN'); process.exit(0); }

const tokenRes = await fetch('https://oauth2.googleapis.com/token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ client_id: clientId, client_secret: clientSecret, refresh_token: refreshToken, grant_type: 'refresh_token' }),
});
const { access_token, error } = await tokenRes.json();
if (!access_token) { console.log('Token error:', error); process.exit(1); }
console.log('Gmail token: OK');

async function search(q) {
  const res = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent(q)}&maxResults=10`, {
    headers: { Authorization: `Bearer ${access_token}` }
  });
  const d = await res.json();
  return d.messages || [];
}

async function getMsg(id) {
  const res = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages/${id}?format=full`, {
    headers: { Authorization: `Bearer ${access_token}` }
  });
  return res.json();
}

function decodeBody(msg) {
  const tryPart = (part) => {
    if (part?.body?.data) return Buffer.from(part.body.data, 'base64url').toString('utf-8');
    if (part?.parts) { for (const p of part.parts) { const r = tryPart(p); if (r) return r; } }
    return null;
  };
  return tryPart(msg.payload) || '';
}

function findLink(body, patterns) {
  for (const p of patterns) { const m = body.match(p); if (m) return m[1]; }
  return null;
}

// Search for verification emails and welcome emails
const searches = [
  { label: 'Email Verification', q: 'subject:"Verify Email" newer_than:2d' },
  { label: 'Welcome Email', q: 'subject:"Bienvenido a MOVA" newer_than:2d' },
  { label: 'Test users recent', q: 'to:abelwuarthon3+p newer_than:2d OR to:abelwuarthon3+t newer_than:2d' },
  { label: 'All recent (1h)', q: 'newer_than:1h' },
];

const results = { verify_links: [], welcome_emails: [] };

for (const { label, q } of searches) {
  const msgs = await search(q);
  console.log(`\n[${label}] query="${q}": ${msgs.length} results`);

  for (const m of msgs.slice(0, 5)) {
    const msg = await getMsg(m.id);
    const subject = msg.payload?.headers?.find(h => h.name === 'Subject')?.value || '';
    const to      = msg.payload?.headers?.find(h => h.name === 'To')?.value || '';
    const date    = msg.payload?.headers?.find(h => h.name === 'Date')?.value || '';
    const body    = decodeBody(msg);

    const verifyLink = findLink(body, [
      /href="(https?:\/\/[^"]+email\/verify[^"]+)"/i,
      /href="(https?:\/\/[^"]+\/verify[^"]*id=[^"]+)"/i,
      /(https?:\/\/mova[^\s"<]+\/email\/verify[^\s"<]+)/i,
      /(https?:\/\/[^\s"<]+\/email\/verify[^\s"<]+)/i,
    ]);

    console.log(`  Subject: ${subject.substring(0,60)} | To: ${to.substring(0,40)}`);
    if (verifyLink) {
      console.log(`  >> VERIFY LINK: ${verifyLink.substring(0,80)}...`);
      results.verify_links.push({ subject, to, date, link: verifyLink });
    } else if (subject.toLowerCase().includes('bienvenido')) {
      console.log(`  >> WELCOME EMAIL FOUND`);
      results.welcome_emails.push({ subject, to, date });
    }
  }
}

console.log('\n=== SUMMARY ===');
console.log('Verify links found:', results.verify_links.length);
console.log('Welcome emails found:', results.welcome_emails.length);

if (results.verify_links.length > 0) {
  writeFileSync('/tmp/verify_links.json', JSON.stringify(results.verify_links, null, 2));
  console.log('Links saved to /tmp/verify_links.json');
}

process.exit(0);
