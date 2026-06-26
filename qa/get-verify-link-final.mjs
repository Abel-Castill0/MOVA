#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(envLines.filter(l => l && !l.startsWith('#') && l.includes('=')).map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()]));

const { access_token } = await (await fetch('https://oauth2.googleapis.com/token', {
  method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ client_id: env.GMAIL_CLIENT_ID, client_secret: env.GMAIL_CLIENT_SECRET, refresh_token: env.GMAIL_READONLY_REFRESH_TOKEN, grant_type: 'refresh_token' }),
})).json();

const { messages = [] } = await (await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent('subject:"Verify Email" newer_than:2d')}&maxResults=5`, { headers: { Authorization: `Bearer ${access_token}` } })).json();

console.log(`Found ${messages.length} verification emails`);

const results = [];

for (const m of messages) {
  const msg = await (await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages/${m.id}?format=full`, { headers: { Authorization: `Bearer ${access_token}` } })).json();

  const subject = msg.payload?.headers?.find(h => h.name === 'Subject')?.value || '';
  const to      = msg.payload?.headers?.find(h => h.name === 'To')?.value || '';

  // Flatten all parts recursively
  function getParts(part, acc = []) {
    if (part.body?.data) acc.push({ mimeType: part.mimeType, data: part.body.data });
    if (part.parts) part.parts.forEach(p => getParts(p, acc));
    return acc;
  }
  const parts = getParts(msg.payload);
  console.log(`\nEmail to: ${to}`);
  console.log(`Parts: ${parts.map(p => p.mimeType).join(', ')}`);

  let verifyLink = null;
  for (const part of parts) {
    const decoded = Buffer.from(part.data, 'base64url').toString('utf-8');
    // Search for verify URL in all formats
    const patterns = [
      /(https?:\/\/[^\s"<>\\]+email\/verify[^\s"<>\\]*)/,
      /href="(https?:\/\/[^"]+verify[^"]+)"/i,
      /(https?:\/\/mova[^\s"<>\\]+)/,
    ];
    for (const p of patterns) {
      const match = decoded.match(p);
      if (match) { verifyLink = match[1] || match[0]; break; }
    }
    if (verifyLink) {
      console.log(`Verify link found in ${part.mimeType}`);
      break;
    }
    // Print first 200 chars for debugging
    if (decoded.length > 0) {
      console.log(`${part.mimeType} snippet: ${decoded.substring(0, 200).replace(/\n/g, ' ')}`);
    }
  }

  if (verifyLink) {
    // Decode HTML entities (&amp; → &) present in HTML email parts
    verifyLink = verifyLink.replace(/&amp;/g, '&');
    console.log('Link:', verifyLink.substring(0, 100));
    results.push({ to, link: verifyLink });
  } else {
    console.log('No verify link found in this email');
  }
}

if (results.length > 0) {
  writeFileSync('C:/Users/ABEL/AppData/Local/Temp/verify_links.json', JSON.stringify(results, null, 2));
  console.log('\nSaved', results.length, 'links');
} else {
  console.log('\nNo links extracted');
}
